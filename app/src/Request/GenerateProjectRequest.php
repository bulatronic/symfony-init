<?php

declare(strict_types=1);

namespace App\Request;

use App\Validator\ValidGeneratorOption;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class GenerateProjectRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Parameter "php" is required.')]
        #[ValidGeneratorOption(type: 'php')]
        public string $php,

        #[Assert\NotBlank(message: 'Parameter "server" is required.')]
        #[ValidGeneratorOption(type: 'server')]
        public string $server,

        #[Assert\NotBlank(message: 'Parameter "symfony" is required.')]
        #[ValidGeneratorOption(type: 'symfony')]
        public string $symfony,

        public string $name = 'demo-symfony',

        #[Assert\NotBlank]
        #[ValidGeneratorOption(type: 'database')]
        public string $database = 'none',

        #[Assert\NotBlank]
        #[ValidGeneratorOption(type: 'cache')]
        public string $cache = 'none',

        public bool $rabbitmq = false,

        /** @var list<string> */
        #[Assert\All([new Assert\Type('string'), new ValidGeneratorOption(type: 'extension')])]
        public array $extensions = [],
    ) {
    }
}
