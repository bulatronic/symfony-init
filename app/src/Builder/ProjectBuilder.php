<?php

declare(strict_types=1);

namespace App\Builder;

use App\Config\ProjectConfig;
use App\Extension\ExtensionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Step-by-step project build: scaffold → Docker → packages → env → install → cleanup.
 */
final readonly class ProjectBuilder
{
    private const int COMPOSER_TIMEOUT = 120;

    private Environment $twig;

    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/generator')]
        private string $generatorTemplatesDir,
        private ExtensionRegistry $registry,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
    ) {
        $loader = new FilesystemLoader($this->generatorTemplatesDir);
        $this->twig = new Environment($loader, ['autoescape' => false]);
    }

    /**
     * Builds the project into the given directory.
     */
    public function build(ProjectConfig $config, string $targetDir): void
    {
        $this->filesystem->mkdir($targetDir, 0755);

        $resolved = $this->registry->resolve($config->extensions);

        $this->createProject($config, $targetDir);
        $this->injectDockerFiles($config, $targetDir, $resolved);
        $this->patchComposerPhpConstraint($config, $targetDir);
        $this->installPackages($config, $targetDir, $resolved);
        $this->configureEnvironment($config, $targetDir);
        $this->runComposerInstall($targetDir);
        $this->cleanupFlexDockerFiles($targetDir);
    }

    private function createProject(ProjectConfig $config, string $targetDir): void
    {
        $process = new Process(
            [
                'composer',
                'create-project',
                'symfony/skeleton:'.$config->symfonyVersion.'.*',
                $targetDir,
                '--no-interaction',
                '--no-install',
                '--prefer-dist',
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

    /**
     * Set composer.json "require"."php" to the selected PHP version so the project matches the chosen runtime.
     */
    private function patchComposerPhpConstraint(ProjectConfig $config, string $targetDir): void
    {
        $path = $targetDir.'/composer.json';
        if (!is_file($path)) {
            return;
        }

        $content = file_get_contents($path);
        if (false === $content) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['require']) || !is_array($data['require'])) {
            return;
        }

        $data['require']['php'] = '>='.$config->phpVersion;

        // Composer schema requires require-dev to be an object {}; empty array [] is invalid
        if (isset($data['require-dev']) && is_array($data['require-dev']) && empty($data['require-dev'])) {
            $data['require-dev'] = new \stdClass();
        }

        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            return;
        }

        $this->filesystem->dumpFile($path, $encoded."\n");
    }

    /**
     * @param list<string> $resolved
     */
    private function injectDockerFiles(ProjectConfig $config, string $targetDir, array $resolved): void
    {
        $phpExtensions = array_unique([
            'zip',
            'opcache',
            ...$this->registry->getPhpExtensions($resolved),
            ...$this->getInfraPhpExtensions($config),
        ]);

        $context = [
            'php_version' => $config->phpVersion,
            'database' => $config->database ?? 'none',
            'cache' => $config->cache ?? 'none',
            'rabbitmq' => $config->rabbitmq ? 'enabled' : 'disabled',
            'php_extensions' => implode(' ', array_filter($phpExtensions)),
        ];

        $templates = $this->getDockerTemplates($config->server);
        foreach ($templates as $filename => $template) {
            $path = $targetDir.'/'.$filename;
            $this->filesystem->dumpFile($path, $this->render($template, $context));
        }
    }

    /**
     * @return array<string, string> Filename => Twig template name
     */
    private function getDockerTemplates(string $server): array
    {
        return match ($server) {
            'frankenphp' => [
                'Dockerfile' => 'Dockerfile.frankenphp.twig',
                'Caddyfile' => 'Caddyfile.frankenphp.twig',
                'docker-compose.yml' => 'docker-compose.frankenphp.twig',
            ],
            default => [
                'Dockerfile' => 'Dockerfile.fpm.twig',
                'docker-compose.yml' => 'docker-compose.fpm.twig',
                'nginx.conf' => 'nginx.fpm.twig',
            ],
        };
    }

    /**
     * PHP extensions required by infrastructure (DB, cache, RabbitMQ), not by Symfony extensions.
     *
     * @return list<string>
     */
    private function getInfraPhpExtensions(ProjectConfig $config): array
    {
        $extensions = [];

        if (null !== $config->database) {
            $extensions[] = match ($config->database) {
                'postgresql' => 'pdo_pgsql',
                'mysql', 'mariadb' => 'pdo_mysql',
                'sqlite' => 'pdo_sqlite',
                default => null,
            };
        }

        if (null !== $config->cache) {
            $extensions[] = match ($config->cache) {
                'redis' => 'redis',
                'memcached' => 'memcached',
                default => null,
            };
        }

        if ($config->rabbitmq) {
            $extensions[] = 'amqp';
        }

        return array_values(array_filter($extensions));
    }

    /**
     * @param list<string> $resolved
     */
    private function installPackages(ProjectConfig $config, string $targetDir, array $resolved): void
    {
        $packages = $this->registry->getPackages($resolved, $config->symfonyVersion, $config->rabbitmq);

        if (empty($packages)) {
            return;
        }

        foreach ($packages as $package) {
            $this->composerRequire($targetDir, $package);
        }

        $this->composerUpdateLock($targetDir);
    }

    private function composerRequire(string $targetDir, string $package): void
    {
        $process = new Process(
            ['composer', 'require', $package, '--no-interaction', '--prefer-dist'],
            $targetDir,
            ['SYMFONY_SKIP_DOCKER' => '1'],
            self::COMPOSER_TIMEOUT
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Composer require failed', [
                'package' => $package,
                'error' => $process->getErrorOutput(),
            ]);
            throw new \RuntimeException('composer require failed: '.$process->getErrorOutput());
        }
    }

    private function composerUpdateLock(string $targetDir): void
    {
        $process = new Process(
            ['composer', 'update', '--lock', '--no-install', '--no-interaction'],
            $targetDir,
            null,
            self::COMPOSER_TIMEOUT
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Composer update --lock failed', ['error' => $process->getErrorOutput()]);
            throw new \RuntimeException('composer update --lock failed: '.$process->getErrorOutput());
        }
    }

    private function configureEnvironment(ProjectConfig $config, string $targetDir): void
    {
        $envPath = $targetDir.'/.env';
        $content = (string) (@file_get_contents($envPath) ?: '');

        $content = $this->appendDatabaseUrl($content, $config->database);
        $content = $this->appendCacheDsn($content, $config->cache);
        $content = $this->applyRabbitMqDsn($content, $config->rabbitmq, $targetDir);

        $this->filesystem->dumpFile($envPath, $content);
    }

    private function appendDatabaseUrl(string $env, ?string $database): string
    {
        if (null === $database || preg_match('/^DATABASE_URL=/m', $env)) {
            return $env;
        }

        $url = match ($database) {
            'postgresql' => 'postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8',
            'mysql' => 'mysql://app:!ChangeMe!@database:3306/app?serverVersion=8.0.32&charset=utf8mb4',
            'mariadb' => 'mysql://app:!ChangeMe!@database:3306/app?serverVersion=10.11.2-MariaDB&charset=utf8mb4',
            'sqlite' => 'sqlite:///%kernel.project_dir%/var/data.db',
            default => null,
        };

        return null !== $url ? $env."\nDATABASE_URL=\"{$url}\"\n" : $env;
    }

    private function appendCacheDsn(string $env, ?string $cache): string
    {
        if (null === $cache || 'none' === $cache) {
            return $env;
        }

        $dsn = match ($cache) {
            'redis' => 'redis://redis:6379',
            'memcached' => 'memcached://memcached:11211',
            default => null,
        };

        return null !== $dsn ? $env."\nCACHE_DSN={$dsn}\n" : $env;
    }

    private function applyRabbitMqDsn(string $env, bool $rabbitmq, string $targetDir): string
    {
        if (!$rabbitmq) {
            return $env;
        }

        $dsn = 'MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages';
        $env = preg_match('/^MESSENGER_TRANSPORT_DSN=/m', $env)
            ? (string) preg_replace('/^MESSENGER_TRANSPORT_DSN=.*/m', $dsn, $env)
            : $env."\n{$dsn}\n";

        $messengerYaml = $targetDir.'/config/packages/messenger.yaml';
        if (is_file($messengerYaml)) {
            $yaml = (string) file_get_contents($messengerYaml);
            $yaml = (string) preg_replace(
                "/^(\\s*)#\\s*async:\\s*'%env\\(MESSENGER_TRANSPORT_DSN\\)%'/m",
                '$1async: \'%env(MESSENGER_TRANSPORT_DSN)%\'',
                $yaml
            );
            $this->filesystem->dumpFile($messengerYaml, $yaml);
        }

        return $env;
    }

    private function runComposerInstall(string $targetDir): void
    {
        $process = new Process(
            [
                'composer',
                'install',
                '--no-dev',
                '--optimize-autoloader',
                '--classmap-authoritative',
                '--no-interaction',
                '--no-scripts',
                '--prefer-dist',
            ],
            $targetDir,
            null,
            self::COMPOSER_TIMEOUT
        );

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Composer install failed', ['error' => $process->getErrorOutput()]);
            throw new \RuntimeException('composer install failed: '.$process->getErrorOutput());
        }
    }

    private function cleanupFlexDockerFiles(string $targetDir): void
    {
        $overrides = [
            'compose.override.yaml',
            'compose.override.yml',
            'docker-compose.override.yaml',
            'docker-compose.override.yml',
        ];

        foreach ($overrides as $file) {
            $path = $targetDir.'/'.$file;
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $dcFile = $targetDir.'/docker-compose.yml';
        if (!file_exists($dcFile)) {
            return;
        }

        $content = (string) file_get_contents($dcFile);
        $content = (string) preg_replace('/\n*###>.*?###.*?\n.*?###<.*?###.*?\n*/s', "\n", $content);
        $content = (string) preg_replace('/\n{3,}/', "\n\n", $content);

        $this->filesystem->dumpFile($dcFile, $content);
    }

    private function render(string $template, array $context): string
    {
        try {
            return $this->twig->render($template, $context);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to render template %s: %s', $template, $e->getMessage()), 0, $e);
        }
    }
}
