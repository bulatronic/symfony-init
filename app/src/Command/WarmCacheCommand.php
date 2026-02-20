<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ProjectConfigFactory;
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
        private readonly ProjectConfigFactory $configFactory,
        private readonly ProjectGeneratorService $generator,
        private readonly GeneratorOptions $options,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all-base', null, InputOption::VALUE_NONE, 'Generate all base combinations (PHP × Symfony × server, no extensions/DB)')
            ->addOption('popular-only', null, InputOption::VALUE_NONE, 'Generate only most popular configurations (default)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Warming Project Cache');

        $generateAllBase = $input->getOption('all-base');
        $configs = $generateAllBase
            ? $this->getAllBaseConfigurations()
            : $this->getPopularConfigurations();

        $io->info(sprintf('Generating %d configurations...', count($configs)));
        $io->progressStart(count($configs));

        $successCount = 0;
        $failureCount = 0;
        $startTime = microtime(true);

        foreach ($configs as $index => $config) {
            try {
                $io->writeln(sprintf(
                    "\n  [%d/%d] PHP %s + %s + Symfony %s%s%s%s",
                    $index + 1,
                    count($configs),
                    $config['php'],
                    $config['server'],
                    $config['symfony'],
                    ($config['database'] ?? null) ? ' + '.$config['database'] : '',
                    ($config['cache'] ?? null) ? ' + '.$config['cache'] : '',
                    ($config['rabbitmq'] ?? false) ? ' + RabbitMQ' : ''
                ));

                $projectConfig = $this->configFactory->fromRequest(
                    $config['php'],
                    $config['server'],
                    $config['symfony'],
                    'cache-warmup',
                    $config['database'] ?? 'none',
                    $config['cache'] ?? 'none',
                    $config['rabbitmq'] ?? false,
                    $config['extensions'] ?? [],
                );

                $zipPath = $this->generator->generate($projectConfig);
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
                'cache' => 'redis',
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
     * @return array<int, array<string, mixed>>
     */
    private function getAllBaseConfigurations(): array
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
