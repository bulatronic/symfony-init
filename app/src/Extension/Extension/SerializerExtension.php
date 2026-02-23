<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\AbstractExtension;

final class SerializerExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'serializer';
    }

    public function getLabel(): string
    {
        return 'Serializer';
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

    public function getPackages(string $symfonyVersion): array
    {
        return [sprintf('symfony/serializer:^%s', $symfonyVersion)];
    }
}
