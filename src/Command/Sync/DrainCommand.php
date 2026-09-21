<?php

declare(strict_types=1);

namespace App\Command\Sync;

use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Service\ProductSyncDrain;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'catalogue-sync:sync:drain',
    description: 'Send one batch of pending product changes to the index and release the rows.',
)]
final class DrainCommand extends Command
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly ProductSyncDrain $productSyncDrain,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $drainResult = $this->productSyncDrain->drain(self::BATCH_SIZE);
        } catch (SearchUnavailableException | BulkIndexingFailedException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            'Written %d, unchanged %d, deleted %d, conflicts %d, still pending %d, %d ms',
            $drainResult->writtenProductCount,
            $drainResult->unchangedProductCount,
            $drainResult->deletedProductCount,
            $drainResult->conflictCount,
            $drainResult->stillPendingProductCount,
            $drainResult->elapsedMilliseconds,
        ));

        return Command::SUCCESS;
    }
}
