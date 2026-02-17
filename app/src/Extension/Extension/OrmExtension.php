<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\ExtensionInterface;

final class OrmExtension implements ExtensionInterface
{
    public function getName(): string
    {
        return 'orm';
    }

    public function getLabel(): string
    {
        return 'Doctrine ORM';
    }

    public function isMetaPack(): bool
    {
        return true;
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
        return ['symfony/orm-pack'];
    }
}
