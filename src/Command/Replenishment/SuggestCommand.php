<?php

declare(strict_types=1);

namespace App\Command\Replenishment;

use App\DTO\ReplenishmentSuggestion;
use App\Helper\MixedToString;
use App\Repository\DailyStoreSalesRepository;
use App\Service\PeriodicReviewPolicy;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function fopen;
use function fputcsv;
use function hrtime;
use function is_dir;
use function is_writable;
use function memory_get_peak_usage;
use function number_format;
use function rewind;
use function sprintf;

#[AsCommand(
    name: 'catalogue-sync:replenishment:suggest',
    description: 'Write order suggestions per store and product as a CSV for the ordering system',
)]
final class SuggestCommand extends Command
{
    private const array CSV_HEADER = [
        'store',
        'sku',
        'average_daily_demand',
        'safety_stock',
        'on_hand',
        'on_order',
        'order_quantity',
    ];

    public function __construct(
        private readonly DailyStoreSalesRepository $dailyStoreSalesRepository,
        private readonly PeriodicReviewPolicy $periodicReviewPolicy,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            'as-of',
            null,
            InputOption::VALUE_REQUIRED,
            'The last sales day the suggestions are computed from, as YYYY-MM-DD',
            new DateTimeImmutable('yesterday')->format('Y-m-d'),
        );
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Where to write the CSV',
            $this->projectDirectory . '/var/replenishment.csv',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asOfOption = MixedToString::transformStrict($input->getOption('as-of'));
        $asOfDate = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfOption);
        if ($asOfDate === false || $asOfDate->format('Y-m-d') !== $asOfOption) {
            $io->error(sprintf('--as-of takes a date as YYYY-MM-DD, %s is not one.', $asOfOption));

            return Command::INVALID;
        }

        $outputPath = MixedToString::transformStrict($input->getOption('output'));
        // dumpFile would create a missing directory, and replace a file in an unwritable one non-atomically
        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory) || !is_writable($outputDirectory)) {
            $io->error(sprintf('Cannot write %s: %s is not a writable directory.', $outputPath, $outputDirectory));

            return Command::FAILURE;
        }

        $startedAt = hrtime(true);
        if (!$this->dailyStoreSalesRepository->hasSalesOn($asOfDate)) {
            $io->error(sprintf(
                'No sales on %s, %s is left as it was. Run make sales, or pass --as-of with a day that has sales.',
                $asOfOption,
                $outputPath,
            ));

            return Command::FAILURE;
        }

        $windowStart = $asOfDate->modify(sprintf('-%d days', PeriodicReviewPolicy::DEMAND_WINDOW_DAYS - 1));
        if (!$this->dailyStoreSalesRepository->hasSalesOnOrBefore($windowStart)) {
            $io->error(sprintf(
                'The sales history does not reach back to %s, the first of the %d days, %s is left as it was.',
                $windowStart->format('Y-m-d'),
                PeriodicReviewPolicy::DEMAND_WINDOW_DAYS,
                $outputPath,
            ));

            return Command::FAILURE;
        }

        // built in memory, so the one disk write is dumpFile's: it fails on a short write and renames atomically
        $csvStream = fopen('php://memory', 'w+');
        if ($csvStream === false) {
            throw new LogicException('Cannot open a memory stream.');
        }

        if (fputcsv($csvStream, self::CSV_HEADER, escape: '') === false) {
            throw new LogicException('Cannot write to a memory stream.');
        }

        $suggestionCount = 0;
        $salesWindowTotalsPerPair = $this->dailyStoreSalesRepository->iterateSalesWindowTotals($windowStart, $asOfDate);
        foreach ($salesWindowTotalsPerPair as $salesWindowTotals) {
            $suggestion = $this->periodicReviewPolicy->suggestOrder($salesWindowTotals);
            if (!$suggestion instanceof ReplenishmentSuggestion) {
                continue;
            }

            $csvLine = [
                $suggestion->storeNumber,
                $suggestion->productSku,
                number_format($suggestion->averageDailyDemand, 2, '.', ''),
                number_format($suggestion->safetyStock, 2, '.', ''),
                $suggestion->quantityOnHand,
                $suggestion->quantityOnOrder,
                $suggestion->suggestedOrderQuantity,
            ];
            if (fputcsv($csvStream, $csvLine, escape: '') === false) {
                throw new LogicException('Cannot write to a memory stream.');
            }

            ++$suggestionCount;
        }

        rewind($csvStream);
        try {
            $this->filesystem->dumpFile($outputPath, $csvStream);
        } catch (IOException $exception) {
            $io->error(sprintf('%s %s is left as it was.', $exception->getMessage(), $outputPath));

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            '%d suggestions as of %s written to %s in %.1f s, peak memory %.1f MB',
            $suggestionCount,
            $asOfOption,
            $outputPath,
            (hrtime(true) - $startedAt) / 1e9,
            memory_get_peak_usage(true) / 1024 / 1024,
        ));

        return Command::SUCCESS;
    }
}
