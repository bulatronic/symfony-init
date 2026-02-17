<?php

declare(strict_types=1);

namespace App\Extension;

/**
 * Strategy: each extension describes itself — packages, PHP extensions, dependencies.
 */
interface ExtensionInterface
{
    /** Unique slug used in query params, cache keys, and registry. */
    public function getName(): string;

    /** Human-readable label for the UI. */
    public function getLabel(): string;

    /**
     * Composer packages to require (with version constraint when needed).
     *
     * @return list<string> e.g. ['symfony/security-bundle:^7.4']
     */
    public function getPackages(string $symfonyVersion): array;

    /**
     * PHP extensions required in the Docker image.
     *
     * @return list<string> e.g. ['pdo_pgsql']
     */
    public function getPhpExtensions(): array;

    /**
     * Names of extensions that must be installed with this one.
     * ExtensionRegistry resolves the graph and adds missing dependencies.
     *
     * @return list<string>
     */
    public function getDependencies(): array;

    /** Whether this is a meta-pack (orm-pack, api-pack) — no version in constraint. */
    public function isMetaPack(): bool;
}
