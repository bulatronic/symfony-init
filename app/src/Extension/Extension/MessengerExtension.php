<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\ExtensionInterface;

final class MessengerExtension implements ExtensionInterface
{
    public function getName(): string
    {
        return 'messenger';
    }

    public function getLabel(): string
    {
        return 'Messenger';
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
        return [sprintf('symfony/messenger:^%s', $symfonyVersion)];
    }
}
