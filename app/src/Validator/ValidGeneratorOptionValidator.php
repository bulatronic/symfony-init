<?php

declare(strict_types=1);

namespace App\Validator;

use App\GeneratorOptions;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidGeneratorOptionValidator extends ConstraintValidator
{
    public function __construct(private readonly GeneratorOptions $options)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidGeneratorOption) {
            throw new UnexpectedTypeException($constraint, ValidGeneratorOption::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        $valid = match ($constraint->type) {
            'php' => $this->options->isValidPhp((string) $value),
            'server' => $this->options->isValidServer((string) $value),
            'symfony' => $this->options->isValidSymfony((string) $value),
            'database' => $this->options->isValidDatabase((string) $value),
            'cache' => $this->options->isValidCache((string) $value),
            'extension' => isset($this->options->extensions[(string) $value]),
            default => throw new \InvalidArgumentException(sprintf('Unknown ValidGeneratorOption type "%s".', $constraint->type)),
        };

        if (!$valid) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->setParameter('{{ type }}', $constraint->type)
                ->addViolation();
        }
    }
}
