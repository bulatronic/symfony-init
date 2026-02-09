<?php

declare(strict_types=1);

namespace App\Command;

use App\GeneratorOptions;
use App\Service\ProjectGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:warm-cache',
    description: 'Pre-generate popular project configurations to warm cache',
)]
final class WarmCacheCommand extends Command
{
    public function __construct(
        private readonly ProjectGeneratorService $generator,
        private readonly GeneratorOptions $options,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Generate all possible combinations (slow)')
            ->addOption('popular-only', null, InputOption::VALUE_NONE, 'Generate only most popular configurations (default)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Warming Project Cache');

        $generateAll = $input->getOption('all');
        $configs = $generateAll
            ? $this->getAllConfigurations()
            : $this->getPopularConfigurations();

        $io->info(sprintf('Generating %d configurations...', count($configs)));
        $io->progressStart(count($configs));

        $successCount = 0;
        $failureCount = 0;
        $startTime = microtime(true);

        foreach ($configs as $index => $config) {
            try {
                $io->writeln(sprintf(
                    "\n  [%d/%d] PHP %s + %s + Symfony %s%s%s",
                    $index + 1,
                    count($configs),
                    $config['php'],
                    $config['server'],
                    $config['symfony'],
                    $config['database'] ?? null ? ' + '.$config['database'] : '',
                    ($config['redis'] ?? false) ? ' + Redis' : ''
                ));

                $zipPath = $this->generator->generate(
                    phpVersion: $config['php'],
                    server: $config['server'],
                    symfonyVersion: $config['symfony'],
                    projectName: 'cache-warmup',
                    extensions: $config['extensions'] ?? [],
                    database: $config['database'] ?? null,
                    redis: $config['redis'] ?? false
                );

                // Clean up the zip file immediately
                @unlink($zipPath);

                ++$successCount;
                $io->progressAdvance();
            } catch (\Throwable $e) {
                ++$failureCount;
                $io->error(sprintf(
                    'Failed: %s',
                    $e->getMessage()
                ));
            }
        }

        $io->progressFinish();
        $duration = microtime(true) - $startTime;

        $io->newLine();
        $io->success(sprintf(
            'Cache warming completed! Success: %d, Failed: %d, Duration: %.2fs',
            $successCount,
            $failureCount,
            $duration
        ));

        return Command::SUCCESS;
    }

    /**
     * Get popular configurations for quick cache warming (5–6 variants).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getPopularConfigurations(): array
    {
        $phpList = $this->options->phpVersions;
        $symfonyKeys = array_keys($this->options->symfonyVersions);
        $latestPhp = $phpList[0] ?? '8.5';
        $latestSymfony = $symfonyKeys[0] ?? '8.0';
        $ltsSymfony = $symfonyKeys[1] ?? $latestSymfony;

        return [
            [
                'php' => $latestPhp,
                'server' => 'frankenphp',
                'symfony' => $latestSymfony,
            ],
            [
                'php' => $latestPhp,
                'server' => 'php-fpm',
                'symfony' => $latestSymfony,
            ],
            [
                'php' => $latestPhp,
                'server' => 'frankenphp',
                'symfony' => $latestSymfony,
                'database' => 'postgresql',
                'extensions' => ['orm'],
            ],
            [
                'php' => $latestPhp,
                'server' => 'frankenphp',
                'symfony' => $latestSymfony,
                'database' => 'postgresql',
                'redis' => true,
                'extensions' => ['orm', 'security', 'mailer'],
            ],
            [
                'php' => $latestPhp,
                'server' => 'frankenphp',
                'symfony' => $ltsSymfony,
            ],
        ];
    }

    /**
     * All combinations of PHP × Symfony × server (no DB/extensions/redis).
     * Full matrix would be too large (PHP × Symfony × server × DB × redis × extensions).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getAllConfigurations(): array
    {
        $configs = [];
        $phpVersions = $this->options->phpVersions;
        $symfonyVersions = array_keys($this->options->symfonyVersions);
        $servers = array_keys($this->options->servers);

        foreach ($phpVersions as $php) {
            foreach ($symfonyVersions as $symfony) {
                foreach ($servers as $server) {
                    $configs[] = [
                        'php' => $php,
                        'server' => $server,
                        'symfony' => $symfony,
                    ];
                }
            }
        }

        return $configs;
    }
}
