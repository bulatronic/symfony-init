<?php

declare(strict_types=1);

namespace App\Service;

use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class ProjectGeneratorService
{
    private const int COMPOSER_TIMEOUT = 120;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.project_dir%/templates/generator')]
        private readonly string $generatorTemplatesDir,
    ) {
    }

    /**
     * Generate a Symfony project zip.
     *
     * @param string $projectName root folder name inside the zip (e.g. demo-symfony)
     *
     * @return string path to the generated zip file (caller must delete after sending response)
     *
     * @throws \RuntimeException on generation failure
     */
    public function generate(string $phpVersion, string $server, string $symfonyVersion, string $projectName = 'demo-symfony'): string
    {
        $tempDir = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8));
        if (!is_dir($this->projectDir.'/var/generator')) {
            mkdir($this->projectDir.'/var/generator', 0755, true);
        }
        mkdir($tempDir, 0755, true);

        try {
            $this->runComposerCreateProject($tempDir, $symfonyVersion);
            $this->injectDockerFiles($tempDir, $phpVersion, $server);
            $this->runComposerInstall($tempDir);
            $zipPath = $this->createZip($tempDir, $projectName);

            return $zipPath;
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function runComposerCreateProject(string $tempDir, string $symfonyVersion): void
    {
        $process = new Process(
            [
                'composer',
                'create-project',
                'symfony/skeleton:'.$symfonyVersion.'.*',
                $tempDir,
                '--no-interaction',
                '--no-install',
            ],
            null,
            null,
            self::COMPOSER_TIMEOUT
        );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('composer create-project failed: '.$process->getErrorOutput());
        }
    }

    private function injectDockerFiles(string $tempDir, string $phpVersion, string $server): void
    {
        $templates = $this->generatorTemplatesDir;
        $replace = ['{{ php_version }}' => $phpVersion];

        if ('frankenphp' === $server) {
            $this->writeFile($tempDir.'/Dockerfile', str_replace(array_keys($replace), array_values($replace), (string) file_get_contents($templates.'/Dockerfile.frankenphp.twig')));
            $this->writeFile($tempDir.'/Caddyfile', (string) file_get_contents($templates.'/Caddyfile.frankenphp.twig'));
            $this->writeFile($tempDir.'/docker-compose.yml', (string) file_get_contents($templates.'/docker-compose.frankenphp.twig'));
        } else {
            $this->writeFile($tempDir.'/Dockerfile', str_replace(array_keys($replace), array_values($replace), (string) file_get_contents($templates.'/Dockerfile.fpm.twig')));
            $this->writeFile($tempDir.'/docker-compose.yml', str_replace(array_keys($replace), array_values($replace), (string) file_get_contents($templates.'/docker-compose.fpm.twig')));
            $this->writeFile($tempDir.'/nginx.conf', (string) file_get_contents($templates.'/nginx.fpm.twig'));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    private function runComposerInstall(string $tempDir): void
    {
        $process = new Process(
            ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-scripts'],
            $tempDir,
            null,
            self::COMPOSER_TIMEOUT
        );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('composer install failed: '.$process->getErrorOutput());
        }
    }

    /**
     * @throws RandomException
     */
    private function createZip(string $tempDir, string $projectName): string
    {
        $zipPath = $this->projectDir.'/var/generator/'.bin2hex(random_bytes(8)).'.zip';
        $zip = new \ZipArchive();
        if (!$zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot create zip file');
        }

        $prefix = $projectName.'/';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $baseLen = strlen($tempDir) + 1;
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getRealPath();
            $relative = substr($path, $baseLen);
            if (str_starts_with($relative, '.git'.DIRECTORY_SEPARATOR) || '.git' === $relative) {
                continue;
            }
            $zip->addFile($path, $prefix.str_replace('\\', '/', $relative));
        }
        $zip->close();

        return $zipPath;
    }

    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
