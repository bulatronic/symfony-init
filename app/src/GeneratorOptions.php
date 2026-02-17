<?php

declare(strict_types=1);

namespace App;

use App\Extension\ExtensionRegistry;
use App\Service\VersionProviderService;
use Psr\Cache\InvalidArgumentException;

/**
 * Exposes generator options with dynamic version fetching and validation.
 *
 * Uses PHP 8.4 property hooks for lazy-loading versions from API.
 */
final class GeneratorOptions
{
    /**
     * Lazy-loaded PHP versions from VersionProviderService.
     *
     * @var list<string>|null
     */
    private ?array $phpVersionsCache = null;

    /**
     * Lazy-loaded Symfony versions from VersionProviderService.
     *
     * @var array<string, string>|null
     */
    private ?array $symfonyVersionsCache = null;

    /**
     * PHP versions with lazy loading via property hooks (PHP 8.4+).
     *
     * @var list<string>
     */
    public array $phpVersions {
        /**
         * @throws InvalidArgumentException
         */
        get {
            if (null === $this->phpVersionsCache) {
                $this->phpVersionsCache = $this->versionProvider->getPhpVersions();
            }

            return $this->phpVersionsCache;
        }
    }

    /**
     * Symfony versions with lazy loading via property hooks (PHP 8.4+).
     *
     * @var array<string, string>
     */
    public array $symfonyVersions {
        /**
         * @throws InvalidArgumentException
         */
        get {
            if (null === $this->symfonyVersionsCache) {
                $this->symfonyVersionsCache = $this->versionProvider->getSymfonyVersions();
            }

            return $this->symfonyVersionsCache;
        }
    }

    /**
     * @var array<string, string>
     */
    public array $servers {
        get => [
            'php-fpm' => 'PHP-FPM + Nginx',
            'frankenphp' => 'FrankenPHP',
        ];
    }

    /**
     * @var array<string, string>
     */
    public array $databases {
        get => [
            'none' => 'None',
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'sqlite' => 'SQLite',
        ];
    }

    /**
     * Extension labels from the registry (single source of truth).
     *
     * @var array<string, string>
     */
    public array $extensions {
        get => $this->registry->getLabels();
    }

    /**
     * @var array<string, string>
     */
    public array $caches {
        get => [
            'none' => 'None',
            'redis' => 'Redis',
            'memcached' => 'Memcached',
        ];
    }

    public function __construct(
        private readonly VersionProviderService $versionProvider,
        private readonly ExtensionRegistry $registry,
    ) {
    }

    /**
     * @deprecated Use direct property access: $options->phpVersions
     *
     * @return list<string>
     */
    public function getPhpVersions(): array
    {
        return $this->phpVersions;
    }

    /**
     * @deprecated Use direct property access: $options->symfonyVersions
     *
     * @return array<string, string>
     */
    public function getSymfonyVersions(): array
    {
        return $this->symfonyVersions;
    }

    /**
     * @deprecated Use direct property access: $options->servers
     *
     * @return array<string, string>
     */
    public function getServers(): array
    {
        return $this->servers;
    }

    /**
     * @deprecated Use direct property access: $options->databases
     *
     * @return array<string, string>
     */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /**
     * @deprecated Use direct property access: $options->extensions
     *
     * @return array<string, string>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function isValidPhp(string $version): bool
    {
        return \in_array($version, $this->phpVersions, true);
    }

    public function isValidServer(string $server): bool
    {
        return isset($this->servers[$server]);
    }

    public function isValidSymfony(string $version): bool
    {
        return isset($this->symfonyVersions[$version]);
    }

    public function isValidDatabase(string $database): bool
    {
        return isset($this->databases[$database]);
    }

    public function isValidCache(string $cache): bool
    {
        return isset($this->caches[$cache]);
    }

    /**
     * @param list<string> $extensions
     */
    public function areValidExtensions(array $extensions): bool
    {
        return array_all($extensions, fn (string $ext): bool => $this->registry->has($ext));
    }
}
