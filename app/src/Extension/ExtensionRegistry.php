<?php

declare(strict_types=1);

namespace App\Extension;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Extension registry: UI labels, dependency resolution, package and PHP extension collection.
 */
final readonly class ExtensionRegistry
{
    /** @var array<string, ExtensionInterface> */
    private array $extensions;

    /**
     * @param iterable<ExtensionInterface> $extensions All services tagged with app.extension
     */
    public function __construct(
        #[TaggedIterator('app.extension')]
        iterable $extensions,
    ) {
        $map = [];
        foreach ($extensions as $ext) {
            $map[$ext->getName()] = $ext;
        }
        $this->extensions = $map;
    }

    /**
     * Label map for the UI (extension checkboxes).
     *
     * @return array<string, string> ['orm' => 'Doctrine ORM', ...]
     */
    public function getLabels(): array
    {
        return array_map(
            fn (ExtensionInterface $e) => $e->getLabel(),
            $this->extensions
        );
    }

    public function has(string $name): bool
    {
        return isset($this->extensions[$name]);
    }

    public function get(string $name): ExtensionInterface
    {
        return $this->extensions[$name]
            ?? throw new \InvalidArgumentException(sprintf('Unknown extension "%s"', $name));
    }

    /**
     * Resolves the dependency graph: returns a deduplicated list (dependencies before dependents).
     *
     * @param list<string> $selected Selected extension names
     *
     * @return list<string>
     */
    public function resolve(array $selected): array
    {
        $resolved = [];
        foreach ($selected as $name) {
            $this->resolveOne($name, $resolved);
        }

        return array_values($resolved);
    }

    /**
     * Collects Composer packages for the resolved extension list.
     * Meta-packs first. When api-platform is present, orm and serializer packages are skipped (included in api-pack).
     *
     * @param list<string> $resolved Result of resolve()
     *
     * @return list<string>
     */
    public function getPackages(array $resolved, string $symfonyVersion, bool $rabbitmq = false): array
    {
        $hasApiPlatform = in_array('api-platform', $resolved, true);
        $metaPacks = [];
        $regular = [];

        foreach ($resolved as $name) {
            if ($hasApiPlatform && in_array($name, ['orm', 'serializer'], true)) {
                continue;
            }

            $ext = $this->extensions[$name] ?? null;
            if (null === $ext) {
                continue;
            }

            foreach ($ext->getPackages($symfonyVersion) as $pkg) {
                if ($ext->isMetaPack()) {
                    $metaPacks[] = $pkg;
                } else {
                    $regular[] = $pkg;
                }
            }
        }

        if ($rabbitmq) {
            $regular[] = 'symfony/amqp-messenger:^'.$symfonyVersion;
        }

        return [...$metaPacks, ...$regular];
    }

    /**
     * PHP extensions required in Docker for the selected stack.
     *
     * @param list<string> $resolved Resolved extension names
     *
     * @return list<string>
     */
    public function getPhpExtensions(array $resolved): array
    {
        $out = [];
        foreach ($resolved as $name) {
            $ext = $this->extensions[$name] ?? null;
            if (null === $ext) {
                continue;
            }
            foreach ($ext->getPhpExtensions() as $phpExt) {
                $out[] = $phpExt;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, true> $resolved
     */
    private function resolveOne(string $name, array &$resolved): void
    {
        if (isset($resolved[$name])) {
            return;
        }

        $ext = $this->extensions[$name] ?? null;
        if (null === $ext) {
            return;
        }

        foreach ($ext->getDependencies() as $dep) {
            $this->resolveOne($dep, $resolved);
        }

        $resolved[$name] = true;
    }
}
