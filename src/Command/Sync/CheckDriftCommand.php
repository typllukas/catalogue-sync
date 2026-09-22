<?php

declare(strict_types=1);

namespace App\Command\Sync;

use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Helper\MixedToInteger;
use App\Service\ProductDriftChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'catalogue-sync:sync:check-drift',
    description: 'Rebuild every product document from MariaDB and report the ones the index has wrong. '
        . 'On a million products this is the weekly consistency pass, not something to run between two edits.',
)]
final class CheckDriftCommand extends Command
{
    private const int REPORTED_IDS_LIMIT = 20;

    public function __construct(
        private readonly ProductDriftChecker $productDriftChecker,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            'recently-changed',
            null,
            InputOption::VALUE_REQUIRED,
            'Compare only the N most recently changed products instead of every one',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recentlyChangedCount = $input->getOption('recently-changed');

        try {
            $driftedCount = $recentlyChangedCount === null
                ? $this->reportEveryDriftedProduct($io)
                : $this->reportRecentlyChanged($io, MixedToInteger::transformStrict($recentlyChangedCount));
        } catch (SearchUnavailableException) {
            $io->writeln('Elasticsearch is not answering, so drift cannot be checked');

            return Command::FAILURE;
        }

        if ($driftedCount > 0) {
            $io->writeln(sprintf('%d drifted documents. Run make reindex', $driftedCount));

            return Command::FAILURE;
        }

        $io->writeln('Drift: every compared product matches its document');

        return Command::SUCCESS;
    }

    /**
     * @throws SearchUnavailableException
     */
    private function reportEveryDriftedProduct(SymfonyStyle $io): int
    {
        $driftedCount = 0;
        foreach ($this->productDriftChecker->iterateDriftedProductIds() as $driftedId) {
            ++$driftedCount;
            if ($driftedCount <= self::REPORTED_IDS_LIMIT) {
                $io->writeln(sprintf('%s differs from its document', $driftedId));
            }
        }

        $surplusDocumentCount = $this->productDriftChecker->countSurplusDocuments();
        if ($surplusDocumentCount > 0) {
            $io->writeln(sprintf('The index holds %d more documents than MariaDB has products', $surplusDocumentCount));
            $driftedCount += $surplusDocumentCount;
        }

        return $driftedCount;
    }

    /**
     * @throws SearchUnavailableException
     */
    private function reportRecentlyChanged(SymfonyStyle $io, int $recentlyChangedCount): int
    {
        $driftedCount = $this->productDriftChecker->countDriftedInSample($recentlyChangedCount);
        $io->writeln(sprintf('Compared the %d most recently changed products', $recentlyChangedCount));

        return $driftedCount;
    }
}
