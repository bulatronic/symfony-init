<?php

declare(strict_types=1);

namespace App\Service;

use App\Builder\ProjectBuilder;
use App\Config\ProjectConfig;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Orchestrator: cache + build via ProjectBuilder + ZIP creation.
 * All build logic lives in the Builder; here only cache and packaging.
 */
final readonly class ProjectGeneratorService
{
    private const int CACHE_TTL = 86400;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private ProjectBuilder $builder,
        private ProjectZipper $zipper,
        #[Autowire(service: 'cache.projects')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Generates the project and returns the path to the ZIP file (caller must delete after sending).
     *
     * @throws \RuntimeException
     */
    public function generate(ProjectConfig $config): string
    {
        try {
            $cacheKey = $config->cacheKey();

            // Fast path: cache hit without acquiring lock
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $this->zipper->createZip($item->get(), $config->projectName);
            }

            $lock = $this->lockFactory->createLock('build_'.$cacheKey, ttl: 180);
            $lock->acquire(blocking: true);

            try {
                // Double-check after acquiring lock — another process may have built it already
                $item = $this->cache->getItem($cacheKey);
                if ($item->isHit()) {
                    return $this->zipper->createZip($item->get(), $config->projectName);
                }

                $this->logger->info('Generating new base project for caching', [
                    'php' => $config->phpVersion,
                    'server' => $config->server,
                    'symfony' => $config->symfonyVersion,
                ]);

                $cachedPath = $this->buildAndCache($config);

                $item->set($cachedPath);
                $item->expiresAfter(self::CACHE_TTL);
                $this->cache->save($item);

                return $this->zipper->createZip($cachedPath, $config->projectName);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Project generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Failed to generate project: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws RandomException|\Throwable
     */
    private function buildAndCache(ProjectConfig $config): string
    {
        $tempDir = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectDir.'/var/generator', 0755);

        try {
            $this->builder->build($config, $tempDir);

            $shareDir = $this->projectDir.'/var/share/projects';
            $this->filesystem->mkdir($shareDir, 0755);
            $cachedPath = $shareDir.'/'.bin2hex(random_bytes(8));
            $this->filesystem->rename($tempDir, $cachedPath);

            return $cachedPath;
        } catch (\Throwable $e) {
            if (is_dir($tempDir)) {
                $this->filesystem->remove($tempDir);
            }
            throw $e;
        }
    }
}
