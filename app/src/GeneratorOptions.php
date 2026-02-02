<?php

declare(strict_types=1);

namespace App;

/**
 * Exposes generator options (PHP versions, servers, Symfony versions) for the Initializr form.
 *
 * From the controller, access as properties:
 *   $this->options->phpVersions
 *   $this->options->servers
 *   $this->options->symfonyVersions
 */
final readonly class GeneratorOptions
{
    /**
     * @param list<string>          $phpVersions
     * @param array<string, string> $servers
     * @param array<string, string> $symfonyVersions
     */
    public function __construct(
        private(set) array $phpVersions = ['8.2', '8.3', '8.4', '8.5'],
        private(set) array $servers = ['php-fpm' => 'PHP-FPM', 'frankenphp' => 'FrankenPHP'],
        private(set) array $symfonyVersions = ['7.4' => '7.4 (LTS)', '8.0' => '8.0'],
    ) {
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
}
