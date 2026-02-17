<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable value object for project generation configuration.
 * Single place to pass parameters instead of long method signatures.
 */
final readonly class ProjectConfig
{
    /**
     * @param list<string> $extensions Extension keys (orm, security, api-platform, ...)
     */
    public function __construct(
        public string $phpVersion,
        public string $server,
        public string $symfonyVersion,
        public string $projectName = 'demo-symfony',
        public array $extensions = [],
        public ?string $database = null,
        public ?string $cache = null,
        public bool $rabbitmq = false,
    ) {
    }

    /**
     * Stable cache key (project name excluded).
     */
    public function cacheKey(): string
    {
        $ext = $this->extensions;
        sort($ext);

        return sprintf(
            'project_%s_%s_%s_%s_%s_%s_%s',
            $this->phpVersion,
            $this->server,
            $this->symfonyVersion,
            implode('_', $ext),
            $this->database ?? 'none',
            $this->cache ?? 'nocache',
            $this->rabbitmq ? 'rabbitmq' : 'norabbitmq'
        );
    }
}
