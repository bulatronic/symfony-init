<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ProjectConfigFactory;
use App\GeneratorOptions;
use App\Service\ProjectGeneratorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GenerateController
{
    private const int STREAM_CHUNK_SIZE = 8192;

    public function __construct(
        private GeneratorOptions $options,
        private ProjectConfigFactory $configFactory,
        private ProjectGeneratorService $generator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/generate', name: 'app_generate', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $php = $request->query->getString('php');
        $server = $request->query->getString('server');
        $symfony = $request->query->getString('symfony');

        if (!$this->options->isValidPhp($php)
            || !$this->options->isValidServer($server)
            || !$this->options->isValidSymfony($symfony)
        ) {
            $this->logger->warning('Invalid generation parameters', [
                'php' => $php,
                'server' => $server,
                'symfony' => $symfony,
            ]);

            return new Response('Invalid parameters', Response::HTTP_BAD_REQUEST);
        }

        $rawName = $request->query->getString('name', 'demo-symfony');
        $name = $this->sanitizeProjectName('' === $rawName ? 'demo-symfony' : $rawName);
        $database = $request->query->getString('database', 'none');
        $cache = $request->query->getString('cache', 'none');
        $rabbitmq = $request->query->getBoolean('rabbitmq', false);
        $extensions = $request->query->all('extensions') ?? [];

        if (!$this->options->isValidDatabase($database)
            || !$this->options->isValidCache($cache)
            || !$this->options->areValidExtensions($extensions)
        ) {
            $this->logger->warning('Invalid optional parameters', [
                'database' => $database,
                'cache' => $cache,
                'extensions' => $extensions,
            ]);

            return new Response('Invalid parameters', Response::HTTP_BAD_REQUEST);
        }

        $config = $this->configFactory->fromRequest(
            $php,
            $server,
            $symfony,
            $name,
            $database,
            $cache,
            $rabbitmq,
            $extensions,
        );

        set_time_limit(600);

        try {
            $startTime = microtime(true);
            $zipPath = $this->generator->generate($config);

            $this->logger->info('Project generated successfully', [
                'duration' => round(microtime(true) - $startTime, 2),
                'php' => $php,
                'server' => $server,
                'symfony' => $symfony,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Project generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new Response(
                'Generation failed: '.$e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->streamZipResponse($zipPath, $config->projectName.'.zip');
    }

    private function streamZipResponse(string $zipPath, string $filename): Response
    {
        $response = new StreamedResponse(function () use ($zipPath): void {
            $handle = @fopen($zipPath, 'rb');
            if (false === $handle) {
                $this->logger->error('Failed to open zip file for streaming', ['path' => $zipPath]);

                return;
            }

            try {
                if (ob_get_level()) {
                    ob_end_clean();
                }

                while (!feof($handle)) {
                    $chunk = fread($handle, self::STREAM_CHUNK_SIZE);
                    if (false !== $chunk && '' !== $chunk) {
                        echo $chunk;
                        flush();
                    }
                }
            } finally {
                fclose($handle);
                @unlink($zipPath);
            }
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        if (file_exists($zipPath)) {
            $size = filesize($zipPath);
            if (false !== $size) {
                $response->headers->set('Content-Length', (string) $size);
            }
        }

        return $response;
    }

    private function sanitizeProjectName(string $name): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        if (null === $cleaned) {
            return 'demo-symfony';
        }

        $trimmed = trim($cleaned, '-');

        return ('' === $trimmed || strlen($trimmed) > 50) ? 'demo-symfony' : $trimmed;
    }
}
