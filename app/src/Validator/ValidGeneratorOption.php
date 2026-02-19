<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class ValidGeneratorOption extends Constraint
{
    public string $message = 'The value "{{ value }}" is not a valid {{ type }}.';

    public function __construct(
        public readonly string $type,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }

    public function validatedBy(): string
    {
        return ValidGeneratorOptionValidator::class;
    }
}
