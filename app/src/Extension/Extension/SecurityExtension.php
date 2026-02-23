<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\AbstractExtension;

final class SecurityExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'security';
    }

    public function getLabel(): string
    {
        return 'Security';
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
        return [sprintf('symfony/security-bundle:^%s', $symfonyVersion)];
    }
}
