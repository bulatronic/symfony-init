<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\ExtensionInterface;

final class HttpClientExtension implements ExtensionInterface
{
    public function getName(): string
    {
        return 'http-client';
    }

    public function getLabel(): string
    {
        return 'HTTP Client';
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
        return [sprintf('symfony/http-client:^%s', $symfonyVersion)];
    }
}
