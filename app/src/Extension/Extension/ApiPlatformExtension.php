<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\AbstractExtension;

final class ApiPlatformExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'api-platform';
    }

    public function getLabel(): string
    {
        return 'API Platform';
    }

    public function isMetaPack(): bool
    {
        return true;
    }

    public function getPhpExtensions(): array
    {
        return [];
    }

    /**
     * api-pack already includes orm + serializer; dependencies are for resolve() and labels.
     */
    public function getDependencies(): array
    {
        return ['orm', 'serializer', 'nelmio-api-doc'];
    }

    public function getPackages(string $symfonyVersion): array
    {
        return ['api-platform/api-pack'];
    }
}
