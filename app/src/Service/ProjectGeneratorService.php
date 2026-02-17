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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ProjectGeneratorService
{
    private const int COMPOSER_TIMEOUT = 120;
    private const int CACHE_TTL = 86400; // 24 hours
    private const int ZIP_BUFFER_SIZE = 8192;

    private readonly Environment $twig;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.project_dir%/templates/generator')]
        private readonly string $generatorTemplatesDir,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem,
    ) {
        // Initialize Twig for template rendering
        $loader = new FilesystemLoader($this->generatorTemplatesDir);
        $this->twig = new Environment($loader, [
            'autoescape' => false, // Disable autoescaping for docker-compose.yml
        ]);
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
        ?string $cache = null,
        bool $rabbitmq = false,
    ): string {
        // Create cache key based on configuration (excluding project name)
        $cacheKey = $this->generateCacheKey($phpVersion, $server, $symfonyVersion, $extensions, $database, $cache, $rabbitmq);

        // Try to get cached base project
        try {
            $cachedBasePath = $this->cache->get($cacheKey, function (ItemInterface $item) use ($phpVersion, $server, $symfonyVersion, $extensions, $database, $cache, $rabbitmq): string {
                $item->expiresAfter(self::CACHE_TTL);

                $this->logger->info('Generating new base project for caching', [
                    'php' => $phpVersion,
                    'server' => $server,
                    'symfony' => $symfonyVersion,
                ]);

                // Generate and cache the base project
                return $this->generateBaseProject($phpVersion, $server, $symfonyVersion, $extensions, $database, $cache, $rabbitmq);
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
        ?string $cache,
        bool $rabbitmq,
    ): string {
        sort($extensions); // Normalize order

        return sprintf(
            'project_%s_%s_%s_%s_%s_%s_%s',
            $phpVersion,
            $server,
            $symfonyVersion,
            implode('_', $extensions),
            $database ?? 'none',
            $cache ?? 'nocache',
            $rabbitmq ? 'rabbitmq' : 'norabbitmq'
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
        ?string $cache,
        bool $rabbitmq,
    ): string {
        $tempDir = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8));
        $this->ensureDirectory($this->projectDir.'/var/generator');
        $this->filesystem->mkdir($tempDir, 0755);

        try {
            $this->runComposerCreateProject($tempDir, $symfonyVersion);
            $this->injectDockerFiles($tempDir, $phpVersion, $server, $database, $cache, $rabbitmq);

            // Auto-include dependencies
            if ($database && !in_array('orm', $extensions, true)) {
                $extensions[] = 'orm';
                $this->logger->info('Auto-included Doctrine ORM (database selected)', [
                    'database' => $database,
                ]);
            }

            if (in_array('api-platform', $extensions, true)) {
                if (!in_array('orm', $extensions, true)) {
                    $extensions[] = 'orm';
                    $this->logger->info('Auto-included Doctrine ORM (API Platform requires it)');
                }
                if (!in_array('serializer', $extensions, true)) {
                    $extensions[] = 'serializer';
                    $this->logger->info('Auto-included Serializer (API Platform requires it)');
                }
                if (!in_array('nelmio-api-doc', $extensions, true)) {
                    $extensions[] = 'nelmio-api-doc';
                    $this->logger->info('Auto-included Nelmio API Doc (works great with API Platform)');
                }
            }

            if ($rabbitmq && !in_array('messenger', $extensions, true)) {
                $extensions[] = 'messenger';
                $this->logger->info('Auto-included Messenger (RabbitMQ selected)');
            }

            if (!empty($extensions) || $rabbitmq) {
                $this->installExtensions($tempDir, $extensions, $symfonyVersion, $rabbitmq);
            }

            if ($database) {
                $this->configureDatabase($tempDir, $database);
            }

            if ($cache && 'none' !== $cache) {
                $this->configureCache($tempDir, $cache);
            }

            if ($rabbitmq) {
                $this->configureRabbitMQ($tempDir);
            }

            $this->runComposerInstall($tempDir);

            // Clean up Docker files added by Flex recipes
            $this->cleanupFlexDockerFiles($tempDir);

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
        ?string $cache,
        bool $rabbitmq,
    ): void {
        $context = [
            'php_version' => $phpVersion,
            'database' => $database ?? 'none',
            'cache' => $cache ?? 'none',
            'rabbitmq' => $rabbitmq ? 'enabled' : 'disabled',
            'php_extensions' => $this->buildPhpExtensionsList($database, $cache, $rabbitmq),
        ];

        if ('frankenphp' === $server) {
            $this->writeFile(
                $tempDir.'/Dockerfile',
                $this->renderTwigTemplate('Dockerfile.frankenphp.twig', $context)
            );
            $this->writeFile(
                $tempDir.'/Caddyfile',
                $this->renderTwigTemplate('Caddyfile.frankenphp.twig', $context)
            );
            $this->writeFile(
                $tempDir.'/docker-compose.yml',
                $this->renderTwigTemplate('docker-compose.frankenphp.twig', $context)
            );
        } else {
            $this->writeFile(
                $tempDir.'/Dockerfile',
                $this->renderTwigTemplate('Dockerfile.fpm.twig', $context)
            );
            $this->writeFile(
                $tempDir.'/docker-compose.yml',
                $this->renderTwigTemplate('docker-compose.fpm.twig', $context)
            );
            $this->writeFile(
                $tempDir.'/nginx.conf',
                $this->renderTwigTemplate('nginx.fpm.twig', $context)
            );
        }
    }

    /**
     * Build space-separated list of PHP extensions for Docker (zip, opcache, pdo_*, redis, memcached, amqp).
     */
    private function buildPhpExtensionsList(?string $database, ?string $cache, bool $rabbitmq = false): string
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

        if ($cache) {
            $extensions[] = match ($cache) {
                'redis' => 'redis',
                'memcached' => 'memcached',
                default => null,
            };
        }

        if ($rabbitmq) {
            $extensions[] = 'amqp';
        }

        return implode(' ', array_filter($extensions));
    }

    /**
     * Render Twig template with context variables.
     */
    private function renderTwigTemplate(string $templateName, array $context = []): string
    {
        try {
            return $this->twig->render($templateName, $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to render template %s: %s', $templateName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Packages that are meta-packs (versioning v1.x, v2.x), not Symfony major version.
     * They must be required without version so Composer resolves from project's Symfony.
     */
    private const array PACK_META_PACKAGES = ['symfony/orm-pack', 'api-platform/api-pack'];

    /**
     * Install Symfony extensions using array_filter() with PHP 8.4+ syntax.
     */
    private function installExtensions(string $tempDir, array $extensions, string $symfonyVersion, bool $rabbitmq = false): void
    {
        // If api-platform is selected, skip orm and serializer (they're included in api-pack)
        $hasApiPlatform = in_array('api-platform', $extensions, true);

        $packages = array_filter(
            array_map(
                fn (string $ext): ?string => match ($ext) {
                    'orm' => $hasApiPlatform ? null : 'symfony/orm-pack',
                    'mailer' => 'symfony/mailer',
                    'messenger' => 'symfony/messenger',
                    'security' => 'symfony/security-bundle',
                    'validator' => 'symfony/validator',
                    'serializer' => $hasApiPlatform ? null : 'symfony/serializer',
                    'api-platform' => 'api-platform/api-pack',
                    'http-client' => 'symfony/http-client',
                    'nelmio-api-doc' => 'nelmio/api-doc-bundle',
                    default => null,
                },
                $extensions
            )
        );

        // RabbitMQ requires symfony/amqp-messenger (see Symfony docs: messenger + RabbitMQ)
        if ($rabbitmq) {
            $packages[] = 'symfony/amqp-messenger';
        }

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
            // Determine version constraint
            if (\in_array($package, self::PACK_META_PACKAGES, true)) {
                $constraint = $package;
            } elseif ('nelmio/api-doc-bundle' === $package) {
                // Nelmio has its own versioning (5.x), not tied to Symfony version
                $constraint = $package;
            } elseif ('symfony/amqp-messenger' === $package) {
                // amqp-messenger has its own versioning, not tied to Symfony version
                $constraint = $package;
            } else {
                $constraint = sprintf('%s:^%s', $package, $symfonyVersion);
            }

            $process = new Process(
                ['composer', 'require', $constraint, '--no-interaction', '--prefer-dist'],
                $tempDir,
                ['SYMFONY_SKIP_DOCKER' => '1'], // Skip Docker integration from Flex recipes
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

    private function configureCache(string $tempDir, string $cache): void
    {
        $envPath = $tempDir.'/.env';
        $envContent = @file_get_contents($envPath);
        if (false === $envContent) {
            $envContent = '';
        }

        $cacheUrl = match ($cache) {
            'redis' => 'redis://redis:6379',
            'memcached' => 'memcached://memcached:11211',
            default => null,
        };

        if ($cacheUrl) {
            $envContent .= "\nCACHE_DSN={$cacheUrl}\n";
            $this->writeFile($envPath, $envContent);
        }
    }

    /**
     * Set Messenger transport to RabbitMQ in .env.
     * The symfony/messenger recipe already creates config/packages/messenger.yaml and the
     * ###> symfony/messenger ### block in .env; we only replace MESSENGER_TRANSPORT_DSN
     * so the recipe output stays the single source of truth.
     */
    private function configureRabbitMQ(string $tempDir): void
    {
        $envPath = $tempDir.'/.env';
        $envContent = @file_get_contents($envPath);
        if (false === $envContent) {
            $envContent = '';
        }

        $dsn = 'MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages';
        if (preg_match('/^MESSENGER_TRANSPORT_DSN=/m', $envContent)) {
            $envContent = preg_replace('/^MESSENGER_TRANSPORT_DSN=.*/m', $dsn, $envContent);
        } else {
            $envContent .= "\n{$dsn}\n";
        }
        $this->writeFile($envPath, $envContent);

        // Enable async transport in config (recipe leaves it commented)
        $messengerYaml = $tempDir.'/config/packages/messenger.yaml';
        if (is_file($messengerYaml)) {
            $yaml = file_get_contents($messengerYaml);
            $yaml = preg_replace("/^(\\s*)#\\s*async:\\s*'%env\\(MESSENGER_TRANSPORT_DSN\\)%'/m", '$1async: \'%env(MESSENGER_TRANSPORT_DSN)%\'', $yaml);
            if (null !== $yaml) {
                $this->writeFile($messengerYaml, $yaml);
            }
        }
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

    /**
     * Remove Docker files added by Symfony Flex recipes.
     * Flex may add compose.override.yaml or inject services into docker-compose.yml.
     */
    private function cleanupFlexDockerFiles(string $tempDir): void
    {
        // Remove compose.override.yaml if it exists
        $overrideFiles = [
            $tempDir.'/compose.override.yaml',
            $tempDir.'/compose.override.yml',
            $tempDir.'/docker-compose.override.yaml',
            $tempDir.'/docker-compose.override.yml',
        ];

        foreach ($overrideFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // Clean up docker-compose.yml by removing Flex-injected blocks
        $dockerComposeFile = $tempDir.'/docker-compose.yml';
        if (!file_exists($dockerComposeFile)) {
            return;
        }

        $content = file_get_contents($dockerComposeFile);
        if (false === $content) {
            return;
        }

        // Remove blocks between ###> package/name ### and ###< package/name ###
        // Keep at least one newline (\n+) to avoid gluing lines together
        $content = preg_replace(
            '/\n*###>.*?###.*?\n.*?###<.*?###.*?\n*/s',
            "\n",
            $content
        );

        // Clean up extra empty lines (3 or more)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        $this->writeFile($dockerComposeFile, $content);
    }
}
