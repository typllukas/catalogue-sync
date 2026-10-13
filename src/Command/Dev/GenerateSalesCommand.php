<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Helper\MixedToInteger;
use App\Service\SalesHistoryGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'catalogue-sync:dev:generate-sales',
    description: 'Replace the daily sales history and the store stock with generated ones. Needs the catalogue.',
)]
final class GenerateSalesCommand extends Command
{
    private const int DEFAULT_STORE_COUNT = 40;

    private const int DEFAULT_PRODUCT_COUNT = 10000;

    private const int DEFAULT_DAY_COUNT = 120;

    public function __construct(
        private readonly SalesHistoryGenerator $salesHistoryGenerator,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addOption('stores', null, InputOption::VALUE_REQUIRED, 'How many stores', self::DEFAULT_STORE_COUNT)
            ->addOption(
                'products',
                null,
                InputOption::VALUE_REQUIRED,
                'How many products each store sells',
                self::DEFAULT_PRODUCT_COUNT,
            )
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of history', self::DEFAULT_DAY_COUNT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storeCount = MixedToInteger::transformStrict($input->getOption('stores'));
        $productCount = MixedToInteger::transformStrict($input->getOption('products'));
        $dayCount = MixedToInteger::transformStrict($input->getOption('days'));
        // store_number is SMALLINT UNSIGNED
        if ($storeCount < 1 || $storeCount > 65535 || $productCount < 1 || $dayCount < 1) {
            $io->error('Stores must be 1 to 65535, products and days at least 1.');

            return Command::INVALID;
        }

        $this->salesHistoryGenerator->generate(
            $storeCount,
            $productCount,
            $dayCount,
            static fn (string $message) => $io->writeln($message),
        );

        return Command::SUCCESS;
    }
}
