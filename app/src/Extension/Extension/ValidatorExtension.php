<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\ExtensionInterface;

final class ValidatorExtension implements ExtensionInterface
{
    public function getName(): string
    {
        return 'validator';
    }

    public function getLabel(): string
    {
        return 'Validator';
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
        return [sprintf('symfony/validator:^%s', $symfonyVersion)];
    }
}
