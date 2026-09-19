<?php

declare(strict_types=1);

namespace App\Command;

use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\Mapping\ProductIndexDefinition;
use App\Elasticsearch\Reindexer;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function memory_get_peak_usage;
use function sprintf;

#[AsCommand(
    name: 'catalogue-sync:index:reindex',
    description: 'Rebuild the index into a new version and switch the alias onto it.',
)]
final class ReindexCommand extends Command
{
    public function __construct(
        private readonly Reindexer $reindexer,
        private readonly ProductIndexDefinition $productIndexDefinition,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->reindexer->reindex(
                $this->productIndexDefinition,
                static fn (string $message) => $io->writeln($message),
            );
        } catch (ElasticsearchException | TransportException | BulkIndexingFailedException $exception) {
            $io->error(sprintf('Elasticsearch refused the reindex: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Peak memory: %.1f MB', memory_get_peak_usage(true) / 1024 / 1024));

        return Command::SUCCESS;
    }
}
