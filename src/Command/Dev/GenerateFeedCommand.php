<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Helper\MixedToInteger;
use App\Service\SupplierFeedGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'catalogue-sync:dev:generate-feed',
    description: 'Run a simulated daily supplier file over the catalogue and fill the outbox.',
)]
final class GenerateFeedCommand extends Command
{
    private const int DEFAULT_ROW_COUNT = 120000;

    public function __construct(
        private readonly SupplierFeedGenerator $supplierFeedGenerator,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            'rows',
            null,
            InputOption::VALUE_REQUIRED,
            'How many lines the supplier file carries',
            self::DEFAULT_ROW_COUNT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $feedResult = $this->supplierFeedGenerator->generate(
            MixedToInteger::transformStrict($input->getOption('rows')),
            $io->writeln(...),
        );

        $io->writeln(sprintf(
            'The feed touched %d rows and left %d products in the queue, %d of them withdrawn',
            $feedResult->touchedRowCount,
            $feedResult->pendingProductCount,
            $feedResult->discontinuedProductCount,
        ));

        return Command::SUCCESS;
    }
}
