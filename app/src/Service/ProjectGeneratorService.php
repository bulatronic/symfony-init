<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ProjectGeneratorService
{
    private const int COMPOSER_TIMEOUT = 120;
    private const int CACHE_TTL = 86400; // 24 hours
    private const int ZIP_BUFFER_SIZE = 8192;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.project_dir%/templates/generator')]
        private readonly string $generatorTemplatesDir,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Generate a Symfony project zip with caching.
     *
     * @param string $projectName root folder name inside the zip (e.g. demo-symfony)
     *
     * @return string path to the generated zip file (caller must delete after sending response)
     *
     * @throws \RuntimeException on generation failure
     */
    public function generate(
        string $phpVersion,
        string $server,
        string $symfonyVersion,
        string $projectName = 'demo-symfony',
        array $extensions = [],
        ?string $database = null,
        bool $redis = false,
    ): string {
        // Create cache key based on configuration (excluding project name)
        $cacheKey = $this->generateCacheKey($phpVersion, $server, $symfonyVersion, $extensions, $database, $redis);

        // Try to get cached base project
        try {
            $cachedBasePath = $this->cache->get($cacheKey, function (ItemInterface $item) use ($phpVersion, $server, $symfonyVersion, $extensions, $database, $redis): string {
                $item->expiresAfter(self::CACHE_TTL);

                $this->logger->info('Generating new base project for caching', [
                    'php' => $phpVersion,
                    'server' => $server,
                    'symfony' => $symfonyVersion,
                ]);

                // Generate and cache the base project
                return $this->generateBaseProject($phpVersion, $server, $symfonyVersion, $extensions, $database, $redis);
            });

            // Create a copy with custom project name
            return $this->createProjectZip($cachedBasePath, $projectName);
        } catch (\Throwable $e) {
            $this->logger->error('Project generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Failed to generate project: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate cache key for project configuration.
     */
    private function generateCacheKey(
        string $phpVersion,
        string $server,
        string $symfonyVersion,
        array $extensions,
        ?string $database,
        bool $redis,
    ): string {
        sort($extensions); // Normalize order

        return sprintf(
            'project_%s_%s_%s_%s_%s_%s',
            $phpVersion,
            $server,
            $symfonyVersion,
            implode('_', $extensions),
            $database ?? 'none',
            $redis ? 'redis' : 'noredis'
        );
    }

    /**
     * Generate base project and store in cache directory.
     *
     * @throws RandomException|\Throwable
     */
    private function generateBaseProject(
        string $phpVersion,
        string $server,
        string $symfonyVersion,
        array $extensions,
        ?string $database,
        bool $redis,
    ): string {
        $tempDir = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8));
        $this->ensureDirectory($this->projectDir.'/var/generator');
        $this->filesystem->mkdir($tempDir, 0755);

        try {
            $this->runComposerCreateProject($tempDir, $symfonyVersion);
            $this->injectDockerFiles($tempDir, $phpVersion, $server, $database, $redis);

            if (!empty($extensions)) {
                $this->installExtensions($tempDir, $extensions, $symfonyVersion);
            }

            if ($database) {
                $this->configureDatabase($tempDir, $database);
            }

            if ($redis) {
                $this->configureRedis($tempDir);
            }

            $this->runComposerInstall($tempDir);

            // Store in share directory (shared between instances, Symfony 7.4+)
            $shareDir = $this->projectDir.'/var/share/projects';
            $this->ensureDirectory($shareDir);
            $cachedPath = $shareDir.'/'.bin2hex(random_bytes(8));

            // Move to share using filesystem for better performance
            $this->filesystem->rename($tempDir, $cachedPath);

            return $cachedPath;
        } catch (\Throwable $e) {
            // Clean up on failure
            if (is_dir($tempDir)) {
                $this->filesystem->remove($tempDir);
            }
            throw $e;
        }
    }

    /**
     * Create final zip from cached base with custom project name.
     *
     * @throws RandomException
     */
    private function createProjectZip(string $basePath, string $projectName): string
    {
        $zipPath = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8)).'.zip';
        $zip = new \ZipArchive();

        if (!$zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot create zip file');
        }

        $prefix = $projectName.'/';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $baseLen = strlen($basePath) + 1;
        $fileCount = 0;

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();
            if (false === $path) {
                continue;
            }

            $relative = substr($path, $baseLen);

            // Skip .git files
            if (str_starts_with($relative, '.git'.DIRECTORY_SEPARATOR) || '.git' === $relative) {
                continue;
            }

            $zip->addFile($path, $prefix.str_replace('\\', '/', $relative));
            ++$fileCount;
        }

        $zip->close();

        $this->logger->info('Created project zip', [
            'files' => $fileCount,
            'size' => filesize($zipPath),
        ]);

        return $zipPath;
    }

    private function runComposerCreateProject(string $tempDir, string $symfonyVersion): void
    {
        $process = new Process(
            [
                'composer',
                'create-project',
                'symfony/skeleton:'.$symfonyVersion.'.*',
                $tempDir,
                '--no-interaction',
                '--no-install',
                '--prefer-dist', // Use dist for better performance
            ],
            null,
            null,
            self::COMPOSER_TIMEOUT
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Composer create-project failed', [
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ]);
            throw new \RuntimeException('composer create-project failed: '.$process->getErrorOutput());
        }
    }

    private function injectDockerFiles(
        string $tempDir,
        string $phpVersion,
        string $server,
        ?string $database,
        bool $redis,
    ): void {
        $templates = $this->generatorTemplatesDir;
        $replace = [
            '{{ php_version }}' => $phpVersion,
            '{{ database }}' => $database ?? 'none',
            '{{ redis }}' => $redis ? 'enabled' : 'disabled',
            '{{ php_extensions }}' => $this->buildPhpExtensionsList($database, $redis),
        ];

        if ('frankenphp' === $server) {
            $this->writeFile(
                $tempDir.'/Dockerfile',
                $this->renderTemplate($templates.'/Dockerfile.frankenphp.twig', $replace)
            );
            $this->writeFile(
                $tempDir.'/Caddyfile',
                $this->readTemplate($templates.'/Caddyfile.frankenphp.twig')
            );
            $this->writeFile(
                $tempDir.'/docker-compose.yml',
                $this->renderTemplate($templates.'/docker-compose.frankenphp.twig', $replace)
            );
        } else {
            $this->writeFile(
                $tempDir.'/Dockerfile',
                $this->renderTemplate($templates.'/Dockerfile.fpm.twig', $replace)
            );
            $this->writeFile(
                $tempDir.'/docker-compose.yml',
                $this->renderTemplate($templates.'/docker-compose.fpm.twig', $replace)
            );
            $this->writeFile(
                $tempDir.'/nginx.conf',
                $this->readTemplate($templates.'/nginx.fpm.twig')
            );
        }
    }

    /**
     * Build space-separated list of PHP extensions for Docker (zip, opcache, pdo_*, redis).
     */
    private function buildPhpExtensionsList(?string $database, bool $redis): string
    {
        $extensions = ['zip', 'opcache'];
        if ($database) {
            $extensions[] = match ($database) {
                'postgresql' => 'pdo_pgsql',
                'mysql', 'mariadb' => 'pdo_mysql',
                'sqlite' => 'pdo_sqlite',
                default => null,
            };
        }
        if ($redis) {
            $extensions[] = 'redis';
        }

        return implode(' ', array_filter($extensions));
    }

    /**
     * Read template file content.
     */
    private function readTemplate(string $templatePath): string
    {
        $content = @file_get_contents($templatePath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Failed to read template: %s', $templatePath));
        }

        return $content;
    }

    /**
     * Render template with replacements.
     */
    private function renderTemplate(string $templatePath, array $replacements): string
    {
        $content = $this->readTemplate($templatePath);

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Packages that are meta-packs (versioning v1.x, v2.x), not Symfony major version.
     * They must be required without version so Composer resolves from project's Symfony.
     */
    private const PACK_META_PACKAGES = ['symfony/orm-pack'];

    /**
     * Install Symfony extensions using array_filter() with PHP 8.4+ syntax.
     */
    private function installExtensions(string $tempDir, array $extensions, string $symfonyVersion): void
    {
        $packages = array_filter(
            array_map(
                fn (string $ext): ?string => match ($ext) {
                    'orm' => 'symfony/orm-pack',
                    'mailer' => 'symfony/mailer',
                    'messenger' => 'symfony/messenger',
                    'security' => 'symfony/security-bundle',
                    'validator' => 'symfony/validator',
                    'serializer' => 'symfony/serializer',
                    default => null,
                },
                $extensions
            )
        );

        if (empty($packages)) {
            return;
        }

        // Require meta-packs (orm-pack) first so lock file is populated before other deps
        $sorted = [];
        foreach (self::PACK_META_PACKAGES as $meta) {
            if (\in_array($meta, $packages, true)) {
                $sorted[] = $meta;
            }
        }
        foreach ($packages as $package) {
            if (!\in_array($package, self::PACK_META_PACKAGES, true)) {
                $sorted[] = $package;
            }
        }
        $packages = $sorted;

        // Require one package at a time so composer.json and composer.lock stay in sync.
        foreach ($packages as $package) {
            $constraint = \in_array($package, self::PACK_META_PACKAGES, true)
                ? $package
                : sprintf('%s:^%s', $package, $symfonyVersion);

            $process = new Process(
                ['composer', 'require', $constraint, '--no-interaction', '--prefer-dist'],
                $tempDir,
                null,
                self::COMPOSER_TIMEOUT
            );

            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->error('Composer require failed', [
                    'package' => $constraint,
                    'error' => $process->getErrorOutput(),
                ]);
                throw new \RuntimeException('composer require failed: '.$process->getErrorOutput());
            }
        }

        // Force lock file to match composer.json (fixes "X is not present in the lock file" after require)
        $updateProcess = new Process(
            ['composer', 'update', '--lock', '--no-install', '--no-interaction'],
            $tempDir,
            null,
            self::COMPOSER_TIMEOUT
        );
        $updateProcess->run();
        if (!$updateProcess->isSuccessful()) {
            $this->logger->error('Composer update --lock failed', ['error' => $updateProcess->getErrorOutput()]);
            throw new \RuntimeException('composer update --lock failed: '.$updateProcess->getErrorOutput());
        }
    }

    private function configureDatabase(string $tempDir, string $database): void
    {
        $envPath = $tempDir.'/.env';
        $envContent = @file_get_contents($envPath);
        if (false === $envContent) {
            $envContent = '';
        }

        $databaseUrl = match ($database) {
            'postgresql' => 'postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8',
            'mysql' => 'mysql://app:!ChangeMe!@database:3306/app?serverVersion=8.0.32&charset=utf8mb4',
            'mariadb' => 'mysql://app:!ChangeMe!@database:3306/app?serverVersion=10.11.2-MariaDB&charset=utf8mb4',
            'sqlite' => 'sqlite:///%kernel.project_dir%/var/data.db',
            default => null,
        };

        if ($databaseUrl) {
            // Recipe (e.g. doctrine/doctrine-bundle) may already add DATABASE_URL; only add if missing
            if (!preg_match('/^DATABASE_URL=/m', $envContent)) {
                $envContent .= "\nDATABASE_URL=\"{$databaseUrl}\"\n";
                $this->writeFile($envPath, $envContent);
            }
        }
    }

    private function configureRedis(string $tempDir): void
    {
        $envPath = $tempDir.'/.env';
        $envContent = @file_get_contents($envPath);
        if (false === $envContent) {
            $envContent = '';
        }

        $envContent .= "\nREDIS_URL=redis://redis:6379\n";
        $this->writeFile($envPath, $envContent);
    }

    private function runComposerInstall(string $tempDir): void
    {
        $process = new Process(
            [
                'composer',
                'install',
                '--no-dev',
                '--optimize-autoloader',
                '--classmap-authoritative', // Better performance
                '--no-interaction',
                '--no-scripts',
                '--prefer-dist',
            ],
            $tempDir,
            null,
            self::COMPOSER_TIMEOUT
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Composer install failed', [
                'error' => $process->getErrorOutput(),
            ]);
            throw new \RuntimeException('composer install failed: '.$process->getErrorOutput());
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            $this->filesystem->mkdir($dir, 0755);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($path, $contents);
    }
}
