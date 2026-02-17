<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\ExtensionInterface;

final class NelmioApiDocExtension implements ExtensionInterface
{
    public function getName(): string
    {
        return 'nelmio-api-doc';
    }

    public function getLabel(): string
    {
        return 'Nelmio API Doc';
    }

    public function isMetaPack(): bool
    {
        return false;
    }

    public function getPhpExtensions(): array
    {
        return [];
    }

    public function getDependencies(): array
    {
        return [];
    }

    /** Own versioning, not tied to Symfony. */
    public function getPackages(string $symfonyVersion): array
    {
        return ['nelmio/api-doc-bundle'];
    }
}
