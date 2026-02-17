<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates a ZIP archive from a project directory with a given root folder name inside the archive.
 */
final readonly class ProjectZipper
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Creates a ZIP from a directory with the given project name as the root folder inside the archive.
     *
     * @throws RandomException
     * @throws \RuntimeException
     */
    public function createZip(string $basePath, string $projectName): string
    {
        $zipPath = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8)).'.zip';
        $this->filesystem->mkdir($this->projectDir.'/var/generator', 0755);

        $zip = new \ZipArchive();
        if (!$zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot create zip file');
        }

        $prefix = $projectName.'/';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $baseLen = strlen($basePath) + 1;
        $fileCount = 0;

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();
            if (false === $path) {
                continue;
            }

            $relative = substr($path, $baseLen);
            if (str_starts_with($relative, '.git'.DIRECTORY_SEPARATOR) || '.git' === $relative) {
                continue;
            }

            $zip->addFile($path, $prefix.str_replace('\\', '/', $relative));
            ++$fileCount;
        }

        $zip->close();

        $this->logger->info('Created project zip', [
            'files' => $fileCount,
            'size' => filesize($zipPath),
        ]);

        return $zipPath;
    }
}
