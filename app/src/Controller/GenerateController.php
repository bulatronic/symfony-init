<?php

declare(strict_types=1);

namespace App\Controller;

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
        private ProjectGeneratorService $generator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/generate', name: 'app_generate', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Validate required parameters
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

        // Optional parameters
        $name = $this->sanitizeProjectName($request->query->getString('name', 'demo-symfony'));
        $database = $request->query->getString('database', 'none');
        $cache = $request->query->getString('cache', 'none');
        $rabbitmq = $request->query->getBoolean('rabbitmq', false);
        $extensions = $request->query->all('extensions') ?? [];

        // Validate optional parameters
        if (!$this->options->isValidDatabase($database)) {
            $this->logger->warning('Invalid database parameter', ['database' => $database]);

            return new Response('Invalid database', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->options->isValidCache($cache)) {
            $this->logger->warning('Invalid cache parameter', ['cache' => $cache]);

            return new Response('Invalid cache', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->options->areValidExtensions($extensions)) {
            $this->logger->warning('Invalid extensions', ['extensions' => $extensions]);

            return new Response('Invalid extensions', Response::HTTP_BAD_REQUEST);
        }

        // ORM without DB: orm-pack recipe expects a DB, use PostgreSQL so docker-compose has DB + volumes
        if ('none' === $database && in_array('orm', $extensions, true)) {
            $database = 'postgresql';
            $this->logger->info('Auto-selected PostgreSQL (ORM selected without database)');
        }

        // Auto-include dependencies
        if ('none' !== $database && !in_array('orm', $extensions, true)) {
            $extensions[] = 'orm';
            $this->logger->info('Auto-included Doctrine ORM (database selected)', [
                'database' => $database,
            ]);
        }

        if (in_array('api-platform', $extensions, true)) {
            if (!in_array('orm', $extensions, true)) {
                $extensions[] = 'orm';
                $this->logger->info('Auto-included Doctrine ORM (API Platform requires it)');
            }
            if (!in_array('serializer', $extensions, true)) {
                $extensions[] = 'serializer';
                $this->logger->info('Auto-included Serializer (API Platform requires it)');
            }
            if (!in_array('nelmio-api-doc', $extensions, true)) {
                $extensions[] = 'nelmio-api-doc';
                $this->logger->info('Auto-included Nelmio API Doc (works great with API Platform)');
            }
        }

        if ($rabbitmq && !in_array('messenger', $extensions, true)) {
            $extensions[] = 'messenger';
            $this->logger->info('Auto-included Messenger (RabbitMQ selected)');
        }

        // Generation runs composer create-project, require, install — can take several minutes
        set_time_limit(600);

        // Generate project
        try {
            $startTime = microtime(true);
            $zipPath = $this->generator->generate(
                phpVersion: $php,
                server: $server,
                symfonyVersion: $symfony,
                projectName: $name,
                extensions: $extensions,
                database: 'none' !== $database ? $database : null,
                cache: 'none' !== $cache ? $cache : null,
                rabbitmq: $rabbitmq
            );

            $duration = microtime(true) - $startTime;
            $this->logger->info('Project generated successfully', [
                'duration' => round($duration, 2),
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

        // Stream response with optimized chunk size
        $filename = $name.'.zip';
        $response = new StreamedResponse(function () use ($zipPath): void {
            $handle = @fopen($zipPath, 'rb');
            if (false === $handle) {
                $this->logger->error('Failed to open zip file for streaming', ['path' => $zipPath]);

                return;
            }

            try {
                // Disable output buffering for better streaming
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

        // Add file size if available
        if (file_exists($zipPath)) {
            $size = filesize($zipPath);
            if (false !== $size) {
                $response->headers->set('Content-Length', (string) $size);
            }
        }

        return $response;
    }

    /**
     * Sanitize project name using cleaner transformation chain.
     */
    private function sanitizeProjectName(string $name): string
    {
        // Remove invalid characters
        $cleaned = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        if (null === $cleaned) {
            return 'demo-symfony';
        }

        // Remove leading/trailing dashes
        $trimmed = trim($cleaned, '-');

        // Ensure non-empty and reasonable length
        return ('' === $trimmed || strlen($trimmed) > 50) ? 'demo-symfony' : $trimmed;
    }
}
