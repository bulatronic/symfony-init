<?php

declare(strict_types=1);

namespace App\Controller;

use App\GeneratorOptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly GeneratorOptions $options,
    ) {
    }

    #[Route(path: '/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig', [
            'php_versions' => $this->options->phpVersions,
            'servers' => $this->options->servers,
            'symfony_versions' => $this->options->symfonyVersions,
            'databases' => $this->options->databases,
            'extensions' => $this->options->extensions,
        ]);
    }
}
