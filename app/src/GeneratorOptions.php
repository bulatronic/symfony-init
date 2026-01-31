<?php

declare(strict_types=1);

namespace App;

/**
 * Exposes generator options (PHP versions, servers, Symfony versions) for the Initializr form.
 *
 * Uses PHP 8.4 property hooks — from the controller access as properties:
 *   $this->options->phpVersions
 *   $this->options->servers
 *   $this->options->symfonyVersions
 */
final class GeneratorOptions
{
    /** @var list<string> */
    private array $phpVersionsBacking;

    /** @var array<string, string> */
    private array $serversBacking;

    /** @var array<string, string> */
    private array $symfonyVersionsBacking;

    /** @var list<string> */
    public array $phpVersions {
        get => $this->phpVersionsBacking;
    }

    /** @var array<string, string> */
    public array $servers {
        get => $this->serversBacking;
    }

    /** @var array<string, string> */
    public array $symfonyVersions {
        get => $this->symfonyVersionsBacking;
    }

    /**
     * @param list<string>          $phpVersions
     * @param array<string, string> $servers
     * @param array<string, string> $symfonyVersions
     */
    public function __construct(
        array $phpVersions = ['8.2', '8.3', '8.4', '8.5'],
        array $servers = ['php-fpm' => 'PHP-FPM', 'frankenphp' => 'FrankenPHP'],
        array $symfonyVersions = ['7.4' => '7.4 (LTS)', '8.0' => '8.0'],
    ) {
        $this->phpVersionsBacking = $phpVersions;
        $this->serversBacking = $servers;
        $this->symfonyVersionsBacking = $symfonyVersions;
    }

    public function isValidPhp(string $version): bool
    {
        return \in_array($version, $this->phpVersionsBacking, true);
    }

    public function isValidServer(string $server): bool
    {
        return isset($this->serversBacking[$server]);
    }

    public function isValidSymfony(string $version): bool
    {
        return isset($this->symfonyVersionsBacking[$version]);
    }
}
