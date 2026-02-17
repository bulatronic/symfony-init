<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Builds ProjectConfig from raw request parameters with ORM ↔ database rules applied.
 * Preserves behaviour: ORM without DB → postgresql; DB without ORM → add orm.
 */
final readonly class ProjectConfigFactory
{
    /**
     * @param list<string> $extensions Selected extension keys from the request
     */
    public function fromRequest(
        string $phpVersion,
        string $server,
        string $symfonyVersion,
        string $projectName,
        string $database,
        string $cache,
        bool $rabbitmq,
        array $extensions,
    ): ProjectConfig {
        $ext = array_values(array_unique($extensions));
        $db = 'none' === $database ? null : $database;

        // ORM without DB: orm-pack expects a DB, default to PostgreSQL
        if (null === $db && in_array('orm', $ext, true)) {
            $db = 'postgresql';
        }

        // DB selected — add ORM
        if (null !== $db && !in_array('orm', $ext, true)) {
            $ext[] = 'orm';
        }

        // RabbitMQ — add Messenger
        if ($rabbitmq && !in_array('messenger', $ext, true)) {
            $ext[] = 'messenger';
        }

        return new ProjectConfig(
            phpVersion: $phpVersion,
            server: $server,
            symfonyVersion: $symfonyVersion,
            projectName: $projectName,
            extensions: array_values($ext),
            database: $db,
            cache: 'none' === $cache ? null : $cache,
            rabbitmq: $rabbitmq,
        );
    }
}
