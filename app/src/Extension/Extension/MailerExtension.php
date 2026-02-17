<?php

declare(strict_types=1);

namespace App\Extension\Extension;

use App\Extension\ExtensionInterface;

final class MailerExtension implements ExtensionInterface
{
    public function getName(): string
    {
        return 'mailer';
    }

    public function getLabel(): string
    {
        return 'Mailer';
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
        return [sprintf('symfony/mailer:^%s', $symfonyVersion)];
    }
}
