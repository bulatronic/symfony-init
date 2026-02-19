<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ProjectConfig;
use App\Config\ProjectConfigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectConfigFactoryTest extends TestCase
{
    private ProjectConfigFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ProjectConfigFactory();
    }

    // --- database / cache mapping ---

    public function testDatabaseNoneBecomesNull(): void
    {
        $config = $this->make(database: 'none');

        self::assertNull($config->database);
    }

    public function testDatabaseValueIsPreserved(): void
    {
        $config = $this->make(database: 'postgresql');

        self::assertSame('postgresql', $config->database);
    }

    public function testCacheNoneBecomesNull(): void
    {
        $config = $this->make(cache: 'none');

        self::assertNull($config->cache);
    }

    public function testCacheValueIsPreserved(): void
    {
        $config = $this->make(cache: 'redis');

        self::assertSame('redis', $config->cache);
    }

    // --- ORM ↔ DB rules ---

    public function testOrmWithoutDatabaseDefaultsToPostgresql(): void
    {
        $config = $this->make(database: 'none', extensions: ['orm']);

        self::assertSame('postgresql', $config->database);
    }

    public function testDatabaseWithoutOrmAddsOrm(): void
    {
        $config = $this->make(database: 'postgresql', extensions: []);

        self::assertContains('orm', $config->extensions);
    }

    public function testDatabaseWithOrmAlreadyDoesNotDuplicateIt(): void
    {
        $config = $this->make(database: 'postgresql', extensions: ['orm']);

        self::assertSame(1, count(array_filter($config->extensions, fn ($e) => 'orm' === $e)));
    }

    // --- RabbitMQ → Messenger ---

    public function testRabbitmqAddsMessenger(): void
    {
        $config = $this->make(rabbitmq: true, extensions: []);

        self::assertContains('messenger', $config->extensions);
    }

    public function testRabbitmqWithMessengerAlreadyDoesNotDuplicateIt(): void
    {
        $config = $this->make(rabbitmq: true, extensions: ['messenger']);

        self::assertSame(1, count(array_filter($config->extensions, fn ($e) => 'messenger' === $e)));
    }

    // --- extensions deduplication ---

    public function testDuplicateExtensionsAreDeduped(): void
    {
        $config = $this->make(database: 'none', extensions: ['security', 'security', 'serializer']);

        self::assertSame(1, count(array_filter($config->extensions, fn ($e) => 'security' === $e)));
    }

    // --- sanitizeProjectName ---

    #[DataProvider('projectNameProvider')]
    public function testProjectNameSanitization(string $input, string $expected): void
    {
        $config = $this->make(projectName: $input);

        self::assertSame($expected, $config->projectName);
    }

    public static function projectNameProvider(): array
    {
        return [
            'valid name is preserved' => ['my-project', 'my-project'],
            'spaces replaced with dashes' => ['my project', 'my-project'],
            'special chars replaced' => ['my_project!', 'my_project'],
            'uppercase letters preserved' => ['MyProject', 'MyProject'],
            'empty string falls back' => ['', 'demo-symfony'],
            'only dashes falls back' => ['---', 'demo-symfony'],
            '50 chars is preserved' => [str_repeat('a', 50), str_repeat('a', 50)],
            '51 chars falls back' => [str_repeat('a', 51), 'demo-symfony'],
        ];
    }

    // --- helper ---

    private function make(
        string $phpVersion = '8.4',
        string $server = 'frankenphp',
        string $symfonyVersion = '7.4',
        string $projectName = 'demo-symfony',
        string $database = 'none',
        string $cache = 'none',
        bool $rabbitmq = false,
        array $extensions = [],
    ): ProjectConfig {
        return $this->factory->fromRequest(
            $phpVersion,
            $server,
            $symfonyVersion,
            $projectName,
            $database,
            $cache,
            $rabbitmq,
            $extensions,
        );
    }
}
