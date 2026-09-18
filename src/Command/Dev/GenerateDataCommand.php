<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Helper\MixedToInteger;
use App\Service\CatalogueDataGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function memory_get_peak_usage;
use function sprintf;

#[AsCommand(
    name: 'catalogue-sync:dev:generate-data',
    description: 'Seed brands, the category tree and products. Needs an empty database.',
)]
final class GenerateDataCommand extends Command
{
    private const int DEFAULT_PRODUCT_COUNT = 10000;

    public function __construct(
        private readonly CatalogueDataGenerator $catalogueDataGenerator,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            'products',
            null,
            InputOption::VALUE_REQUIRED,
            'How many products to generate',
            self::DEFAULT_PRODUCT_COUNT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->catalogueDataGenerator->generate(
            MixedToInteger::transformStrict($input->getOption('products')),
            static fn (string $message) => $io->writeln($message),
        );

        $io->writeln(sprintf('Peak memory: %.1f MB', memory_get_peak_usage(true) / 1024 / 1024));

        return Command::SUCCESS;
    }
}
