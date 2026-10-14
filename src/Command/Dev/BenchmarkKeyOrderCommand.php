<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Repository\DailyStoreSalesRepository;
use App\Service\KeyOrderBenchmark;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'catalogue-sync:dev:benchmark-key-order',
    description: 'Time the 28-day sales totals under each key order and print their ANALYZE plans. Needs make sales.',
)]
final class BenchmarkKeyOrderCommand extends Command
{
    public function __construct(
        private readonly DailyStoreSalesRepository $dailyStoreSalesRepository,
        private readonly KeyOrderBenchmark $keyOrderBenchmark,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lastSalesDate = $this->dailyStoreSalesRepository->findLastSalesDate();
        if (!$lastSalesDate instanceof DateTimeImmutable) {
            $io->error('No sales history. Run make sales first.');

            return Command::FAILURE;
        }

        $this->keyOrderBenchmark->run(
            $lastSalesDate,
            static fn (string $message) => $io->writeln($message),
        );

        return Command::SUCCESS;
    }
}
