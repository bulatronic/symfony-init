<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches actual PHP and Symfony versions from official sources with caching.
 */
final readonly class VersionProviderService
{
    private const string PHP_VERSIONS_URL = 'https://www.php.net/releases/index.php?json&version=8';
    private const string SYMFONY_API_URL = 'https://symfony.com/releases.json';
    private const int CACHE_TTL = 3600; // 1 hour
    private const int HTTP_TIMEOUT = 5; // 5 seconds timeout for HTTP requests

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get list of supported PHP versions (actively supported + security only).
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function getPhpVersions(): array
    {
        return $this->cache->get('php_versions', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            try {
                $response = $this->httpClient->request('GET', self::PHP_VERSIONS_URL, [
                    'timeout' => self::HTTP_TIMEOUT,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'Symfony-Initializr/1.0',
                    ],
                ]);

                $data = $response->toArray();
                $versions = $this->extractPhpVersions($data);

                if (empty($versions)) {
                    $this->logger->warning('No PHP versions extracted from API, using defaults');

                    return $this->getDefaultPhpVersions();
                }

                return $versions;
            } catch (ExceptionInterface $e) {
                $this->logger->error('Failed to fetch PHP versions', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                return $this->getDefaultPhpVersions();
            } catch (\Throwable $e) {
                $this->logger->error('Unexpected error fetching PHP versions', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                return $this->getDefaultPhpVersions();
            }
        });
    }

    /**
     * Get list of Symfony LTS and current stable versions.
     *
     * @return array<string, string> ['7.4' => '7.4 (LTS)', '8.0' => '8.0']
     *
     * @throws InvalidArgumentException
     */
    public function getSymfonyVersions(): array
    {
        return $this->cache->get('symfony_versions', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            try {
                $response = $this->httpClient->request('GET', self::SYMFONY_API_URL, [
                    'timeout' => self::HTTP_TIMEOUT,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'Symfony-Initializr/1.0',
                    ],
                ]);

                $data = $response->toArray();
                $versions = $this->extractSymfonyVersions($data);

                if (empty($versions)) {
                    $this->logger->warning('No Symfony versions extracted from API, using defaults');

                    return $this->getDefaultSymfonyVersions();
                }

                return $versions;
            } catch (ExceptionInterface $e) {
                $this->logger->error('Failed to fetch Symfony versions', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                return $this->getDefaultSymfonyVersions();
            } catch (\Throwable $e) {
                $this->logger->error('Unexpected error fetching Symfony versions', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                return $this->getDefaultSymfonyVersions();
            }
        });
    }

    /**
     * Extract and normalize PHP versions from API response.
     *
     * Uses improved array processing.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function extractPhpVersions(array $data): array
    {
        // New API format: single object with 'supported_versions' array
        if (isset($data['supported_versions']) && is_array($data['supported_versions'])) {
            $versions = array_filter($data['supported_versions'], 'is_string');
            rsort($versions, SORT_NATURAL);

            return array_values($versions);
        }

        // Fallback: try old format (array of objects)
        $versions = [];
        foreach ($data as $versionInfo) {
            if (!is_array($versionInfo)) {
                continue;
            }

            if (isset($versionInfo['supported_versions']) && is_array($versionInfo['supported_versions'])) {
                foreach ($versionInfo['supported_versions'] as $version) {
                    if (is_string($version)) {
                        $versions[] = $version;
                    }
                }
            }
        }

        if (empty($versions)) {
            return [];
        }

        $uniqueVersions = array_unique($versions);
        rsort($uniqueVersions, SORT_NATURAL);

        return array_values($uniqueVersions);
    }

    /**
     * Extract and normalize Symfony versions from API response.
     *
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function extractSymfonyVersions(array $data): array
    {
        // New API format: single object with symfony_versions, supported_versions etc
        $versions = [];
        if (isset($data['symfony_versions']['lts'], $data['supported_versions'])) {
            $ltsVersion = (string) $data['symfony_versions']['lts'];
            $ltsMajorMinor = implode('.', array_slice(explode('.', $ltsVersion), 0, 2));

            // Get supported versions
            if (is_array($data['supported_versions'])) {
                foreach ($data['supported_versions'] as $version) {
                    if (is_string($version)) {
                        $label = $version;
                        // Mark LTS version
                        if ($version === $ltsMajorMinor) {
                            $label .= ' (LTS)';
                        }
                        $versions[$version] = $label;
                    }
                }
            }

            // Sort versions in descending order
            uksort($versions, fn (string $a, string $b): int => version_compare($b, $a));

            $this->logger->info('Symfony versions extracted from new API format', [
                'count' => count($versions),
                'versions' => $versions,
            ]);

            return $versions;
        }

        // Fallback: try old format (array of objects with version keys)
        foreach ($data as $version => $info) {
            if (!is_string($version) || !is_array($info)) {
                continue;
            }

            if (isset($info['maintained']) && true === $info['maintained']) {
                $majorMinor = implode('.', array_slice(explode('.', $version), 0, 2));
                $label = $majorMinor;

                if (isset($info['lts']) && true === $info['lts']) {
                    $label .= ' (LTS)';
                }

                $versions[$majorMinor] = $label;
            }
        }

        if (!empty($versions)) {
            // Sort versions in descending order
            uksort($versions, fn (string $a, string $b): int => version_compare($b, $a));

            $this->logger->info('Symfony versions extracted from old API format', [
                'count' => count($versions),
            ]);
        }

        return $versions;
    }

    /**
     * @return list<string>
     */
    private function getDefaultPhpVersions(): array
    {
        return ['8.5', '8.4', '8.3', '8.2'];
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultSymfonyVersions(): array
    {
        return [
            '8.0' => '8.0',
            '7.4' => '7.4 (LTS)',
            '6.4' => '6.4 (LTS)',
        ];
    }
}
