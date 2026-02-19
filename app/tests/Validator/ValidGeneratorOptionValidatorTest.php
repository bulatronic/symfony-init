<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Extension\ExtensionInterface;
use App\Extension\ExtensionRegistry;
use App\GeneratorOptions;
use App\Service\VersionProviderService;
use App\Validator\ValidGeneratorOption;
use App\Validator\ValidGeneratorOptionValidator;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Uses static options (server/database/cache) and the registry with one 'orm' extension.
 * PHP/Symfony versions come from VersionProviderService fallback defaults:
 *   PHP: ['8.5', '8.4', '8.3', '8.2'], Symfony: ['8.0', '7.4', '6.4'].
 */
final class ValidGeneratorOptionValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidator
    {
        $registry = new ExtensionRegistry([
            new class implements ExtensionInterface {
                public function getName(): string
                {
                    return 'orm';
                }

                public function getLabel(): string
                {
                    return 'Doctrine ORM';
                }

                public function getPackages(string $symfonyVersion): array
                {
                    return [];
                }

                public function getPhpExtensions(): array
                {
                    return [];
                }

                public function getDependencies(): array
                {
                    return [];
                }

                public function isMetaPack(): bool
                {
                    return false;
                }
            },
        ]);

        // HTTP client mock will return an empty array from toArray(),
        // which triggers fallback to hardcoded defaults in VersionProviderService.
        $httpClient = $this->createMock(HttpClientInterface::class);
        $options = new GeneratorOptions(
            new VersionProviderService($httpClient, new ArrayAdapter(), new NullLogger()),
            $registry,
        );

        return new ValidGeneratorOptionValidator($options);
    }

    // --- null / empty passthrough ---

    public function testNullValueIsSkipped(): void
    {
        $this->validator->validate(null, new ValidGeneratorOption(type: 'server'));

        $this->assertNoViolation();
    }

    public function testEmptyStringIsSkipped(): void
    {
        $this->validator->validate('', new ValidGeneratorOption(type: 'server'));

        $this->assertNoViolation();
    }

    // --- server ---

    public function testValidServerPassesWithoutViolation(): void
    {
        $this->validator->validate('frankenphp', new ValidGeneratorOption(type: 'server'));

        $this->assertNoViolation();
    }

    public function testInvalidServerRaisesViolation(): void
    {
        $constraint = new ValidGeneratorOption(type: 'server');

        $this->validator->validate('apache', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', 'apache')
            ->setParameter('{{ type }}', 'server')
            ->assertRaised();
    }

    // --- database ---

    public function testValidDatabasePassesWithoutViolation(): void
    {
        $this->validator->validate('postgresql', new ValidGeneratorOption(type: 'database'));

        $this->assertNoViolation();
    }

    public function testInvalidDatabaseRaisesViolation(): void
    {
        $constraint = new ValidGeneratorOption(type: 'database');

        $this->validator->validate('mssql', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', 'mssql')
            ->setParameter('{{ type }}', 'database')
            ->assertRaised();
    }

    // --- cache ---

    public function testValidCachePassesWithoutViolation(): void
    {
        $this->validator->validate('redis', new ValidGeneratorOption(type: 'cache'));

        $this->assertNoViolation();
    }

    public function testInvalidCacheRaisesViolation(): void
    {
        $constraint = new ValidGeneratorOption(type: 'cache');

        $this->validator->validate('varnish', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', 'varnish')
            ->setParameter('{{ type }}', 'cache')
            ->assertRaised();
    }

    // --- extension ---

    public function testValidExtensionPassesWithoutViolation(): void
    {
        $this->validator->validate('orm', new ValidGeneratorOption(type: 'extension'));

        $this->assertNoViolation();
    }

    public function testInvalidExtensionRaisesViolation(): void
    {
        $constraint = new ValidGeneratorOption(type: 'extension');

        $this->validator->validate('unknown-ext', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', 'unknown-ext')
            ->setParameter('{{ type }}', 'extension')
            ->assertRaised();
    }

    // --- php (relies on VersionProviderService fallback defaults) ---

    public function testValidPhpVersionPassesWithoutViolation(): void
    {
        $this->validator->validate('8.4', new ValidGeneratorOption(type: 'php'));

        $this->assertNoViolation();
    }

    public function testInvalidPhpVersionRaisesViolation(): void
    {
        $constraint = new ValidGeneratorOption(type: 'php');

        $this->validator->validate('6.0', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', '6.0')
            ->setParameter('{{ type }}', 'php')
            ->assertRaised();
    }

    // --- symfony ---

    public function testValidSymfonyVersionPassesWithoutViolation(): void
    {
        $this->validator->validate('7.4', new ValidGeneratorOption(type: 'symfony'));

        $this->assertNoViolation();
    }

    public function testInvalidSymfonyVersionRaisesViolation(): void
    {
        $constraint = new ValidGeneratorOption(type: 'symfony');

        $this->validator->validate('5.0', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', '5.0')
            ->setParameter('{{ type }}', 'symfony')
            ->assertRaised();
    }

    // --- error cases ---

    public function testUnknownTypeThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown ValidGeneratorOption type "unknown".');

        $this->validator->validate('value', new ValidGeneratorOption(type: 'unknown'));
    }

    public function testWrongConstraintThrowsUnexpectedTypeException(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('value', new \Symfony\Component\Validator\Constraints\NotBlank());
    }
}
