<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ProjectConfigFactory;
use App\Request\GenerateProjectRequest;
use App\Service\ProjectGeneratorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class GenerateController
{
    private const int STREAM_CHUNK_SIZE = 8192;

    public function __construct(
        private ProjectConfigFactory $configFactory,
        private ProjectGeneratorService $generator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/generate', name: 'app_generate', methods: ['GET'])]
    public function __invoke(#[MapQueryString] ?GenerateProjectRequest $request): Response
    {
        if (null === $request) {
            throw new ValidationFailedException(null, new ConstraintViolationList([new ConstraintViolation('Missing required query parameters: php, server, symfony.', '', [], null, '', null)]));
        }

        $config = $this->configFactory->fromRequest(
            $request->php,
            $request->server,
            $request->symfony,
            $request->name,
            $request->database,
            $request->cache,
            $request->rabbitmq,
            $request->extensions,
        );

        set_time_limit(600);

        try {
            $startTime = microtime(true);
            $zipPath = $this->generator->generate($config);

            $this->logger->info('Project generated successfully', [
                'duration' => round(microtime(true) - $startTime, 2),
                'php' => $request->php,
                'server' => $request->server,
                'symfony' => $request->symfony,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Project generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new Response('Generation failed: '.$e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
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
            }
        });

        register_shutdown_function(static function () use ($zipPath): void {
            if (file_exists($zipPath)) {
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
}
