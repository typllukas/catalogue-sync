<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\FeedResult;
use App\Elasticsearch\IndexNameFactory;
use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Helper\MixedToString;
use App\Repository\DeletedProductRepository;
use App\Repository\ProductSyncOutboxRepository;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function array_values;
use function count;
use function intdiv;
use function max;
use function mt_rand;
use function mt_srand;
use function sprintf;

/**
 * DBAL: updated_at is set by hand, and ProductWriteCallRule does not see these writes.
 */
final readonly class SupplierFeedGenerator
{
    private const int TOUCH_BATCH_SIZE = 1000;

    private const int RANDOM_SEED = 20260919;

    /** One line per warehouse and price list; the outbox unique key collapses them. */
    private const int LINES_PER_PRODUCT = 3;

    private const int DISCONTINUED_IN = 1000;

    /** The rest repeat yesterday's price, and the import marks them anyway. */
    private const int CHANGED_SHARE_PERCENT = 15;

    /** The seeded price ceiling; unbounded, the random walk of prices would reach the column limit. */
    public const int MAXIMUM_PRICE = 1999900;

    private const int MINIMUM_PRICE = 100;

    public function __construct(
        private Connection $connection,
        private ProductSyncOutboxRepository $productSyncOutboxRepository,
        private DeletedProductRepository $deletedProductRepository,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     */
    public function generate(int $touchedRowCount, callable $reportProgress): FeedResult
    {
        mt_srand(self::RANDOM_SEED);

        $indexName = $this->indexNameFactory->build();
        $activeAssortment = $this->readActiveAssortment(
            max(1, intdiv($touchedRowCount, self::LINES_PER_PRODUCT)),
        );
        if ($activeAssortment === []) {
            return new FeedResult(0, 0, 0);
        }

        // microseconds, two files in one second must still differ
        $fileIdentity = new DateTimeImmutable()->format('Y-m-d H:i:s.u');
        $discontinuedCount = 0;
        $writtenLines = 0;
        $batch = [];

        for ($lineIndex = 0; $lineIndex < $touchedRowCount; ++$lineIndex) {
            $batch[] = $activeAssortment[mt_rand(0, count($activeAssortment) - 1)];

            if (count($batch) < self::TOUCH_BATCH_SIZE) {
                continue;
            }

            $discontinuedCount += $this->applyBatch($indexName, $batch, $fileIdentity);
            $writtenLines += count($batch);
            $batch = [];
            $reportProgress(sprintf('feed lines: %d', $writtenLines));
        }

        if ($batch !== []) {
            $discontinuedCount += $this->applyBatch($indexName, $batch, $fileIdentity);
            $reportProgress(sprintf('feed lines: %d', $writtenLines + count($batch)));
        }

        return new FeedResult(
            $touchedRowCount,
            $this->productSyncOutboxRepository->countPending($indexName),
            $discontinuedCount,
        );
    }

    /**
     * @param array<int, Ulid> $productIds
     *
     * @return int how many products this batch withdrew
     */
    private function applyBatch(string $indexName, array $productIds, string $fileIdentity): int
    {
        if ($productIds === []) {
            return 0;
        }

        $touchedAt = new DateTimeImmutable();
        $distinctIds = $this->deduplicateIds($productIds);
        $discontinuedIds = [];
        $updatedIds = [];

        foreach ($distinctIds as $productId) {
            if (mt_rand(1, self::DISCONTINUED_IN) === 1) {
                $discontinuedIds[] = $productId;
                continue;
            }

            $updatedIds[] = $productId;
        }

        $this->connection->transactional(function () use (
            $indexName,
            $updatedIds,
            $discontinuedIds,
            $touchedAt,
            $fileIdentity,
        ): void {
            $this->updatePriceAndStock($updatedIds, $touchedAt, $fileIdentity);
            $this->productSyncOutboxRepository->enqueueMany(
                $indexName,
                $updatedIds,
                [ProductSyncScope::PRICE, ProductSyncScope::STOCK],
                ProductSyncOperation::WRITE,
                $touchedAt,
            );

            $this->withdraw($discontinuedIds, $touchedAt);
            $this->productSyncOutboxRepository->enqueueMany(
                $indexName,
                $discontinuedIds,
                [ProductSyncScope::EVERYTHING],
                ProductSyncOperation::DELETE,
                $touchedAt,
            );
        });

        return count($discontinuedIds);
    }

    /**
     * @param array<int, Ulid> $productIds
     */
    private function updatePriceAndStock(array $productIds, DateTimeImmutable $touchedAt, string $fileIdentity): void
    {
        if ($productIds === []) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE product SET'
                . ' price_in_minor_units = LEAST(?, GREATEST(?, ROUND(price_in_minor_units'
                . ' * (85 + CRC32(CONCAT(id, ?, \'price\')) % 31) / 100))),'
                . ' stock_quantity = CRC32(CONCAT(id, ?, \'stock\')) % 400, updated_at = ?'
                . ' WHERE id IN (?) AND CRC32(CONCAT(id, ?)) % 100 < ?',
            [
                self::MAXIMUM_PRICE,
                self::MINIMUM_PRICE,
                $fileIdentity,
                $fileIdentity,
                $touchedAt->format('Y-m-d H:i:s'),
                $this->toBinaryIds($productIds),
                $fileIdentity,
                self::CHANGED_SHARE_PERCENT,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ArrayParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::INTEGER,
            ],
        );
    }

    /**
     * A running rebuild learns about the delete only from the tombstone.
     *
     * @param array<int, Ulid> $productIds
     */
    private function withdraw(array $productIds, DateTimeImmutable $touchedAt): void
    {
        if ($productIds === []) {
            return;
        }

        $this->deletedProductRepository->insertTombstones($productIds, $touchedAt);
        $this->connection->executeStatement(
            'DELETE FROM product WHERE id IN (?)',
            [$this->toBinaryIds($productIds)],
            [ArrayParameterType::BINARY],
        );
    }

    /**
     * @return array<int, Ulid>
     */
    private function readActiveAssortment(int $assortmentSize): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT id FROM product ORDER BY id LIMIT ?',
            [$assortmentSize],
            [ParameterType::INTEGER],
        );

        return array_map(
            static fn (mixed $id): Ulid => Ulid::fromBinary(MixedToString::transformStrict($id)),
            $rows,
        );
    }

    /**
     * @param array<int, Ulid> $productIds
     *
     * @return array<int, Ulid>
     */
    private function deduplicateIds(array $productIds): array
    {
        $byBase32 = [];
        foreach ($productIds as $productId) {
            $byBase32[$productId->toBase32()] = $productId;
        }

        return array_values($byBase32);
    }

    /**
     * @param array<int, Ulid> $productIds
     *
     * @return array<int, string>
     */
    private function toBinaryIds(array $productIds): array
    {
        return array_map(static fn (Ulid $productId): string => $productId->toBinary(), $productIds);
    }
}
