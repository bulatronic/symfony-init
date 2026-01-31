<?php

declare(strict_types=1);

namespace App\Controller;

use App\GeneratorOptions;
use App\Service\ProjectGeneratorService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GenerateController
{
    public function __construct(
        private GeneratorOptions $options,
        private ProjectGeneratorService $generator,
    ) {
    }

    #[Route(path: '/generate', name: 'app_generate', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $php = $request->query->getString('php');
        $server = $request->query->getString('server');
        $symfony = $request->query->getString('symfony');
        $name = $this->sanitizeProjectName($request->query->getString('name'));

        if (!$this->options->isValidPhp($php) || !$this->options->isValidServer($server) || !$this->options->isValidSymfony($symfony)) {
            return new Response('Invalid parameters', Response::HTTP_BAD_REQUEST);
        }

        try {
            $zipPath = $this->generator->generate($php, $server, $symfony, $name);
        } catch (\Throwable $e) {
            return new Response('Generation failed: '.$e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $filename = $name.'.zip';
        $response = new StreamedResponse(function () use ($zipPath): void {
            $handle = fopen($zipPath, 'rb');
            if (false === $handle) {
                return;
            }
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if (false !== $chunk) {
                        echo $chunk;
                    }
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            } finally {
                fclose($handle);
                @unlink($zipPath);
            }
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function sanitizeProjectName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $name = trim($name, '-');

        return '' !== $name ? $name : 'demo-symfony';
    }
}
