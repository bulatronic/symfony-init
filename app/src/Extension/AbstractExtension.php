<?php

declare(strict_types=1);

namespace App\Extension;

abstract class AbstractExtension implements ExtensionInterface
{
    public function getDevPackages(string $symfonyVersion): array
    {
        return [];
    }
}
