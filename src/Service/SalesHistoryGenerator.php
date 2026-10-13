<?php

declare(strict_types=1);

namespace App\Service;

use App\Helper\MixedToInteger;
use Doctrine\DBAL\Connection;

use function hrtime;
use function intdiv;
use function sprintf;

/**
 * Randomness is CRC32 of store, SKU and days back, so the history stays one SQL statement and is the same
 * for the same catalogue on any date. Product ids are random ULIDs, so they stay out of it.
 */
final readonly class SalesHistoryGenerator
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     */
    public function generate(int $storeCount, int $productCount, int $dayCount, callable $reportProgress): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE daily_store_sales');
        $this->connection->executeStatement('TRUNCATE TABLE store_stock');

        $startedAt = hrtime(true);
        // in key order, so the clustered index does not split pages
        $salesRowCount = $this->connection->executeStatement(sprintf(
            <<<'SQL'
                INSERT INTO daily_store_sales (store_number, product_id, sales_date, quantity)
                SELECT store.seq, product.id, CURRENT_DATE - INTERVAL (days_back.seq + 1) DAY,
                       1 + CRC32(CONCAT('quantity', store.seq, product.sku, days_back.seq)) %% 5
                FROM seq_1_to_%d store
                CROSS JOIN (SELECT id, sku FROM product ORDER BY sku LIMIT %d) product
                CROSS JOIN seq_0_to_%d days_back
                WHERE CRC32(CONCAT('sale', store.seq, product.sku, days_back.seq)) %% 100
                    < 10 + CRC32(CONCAT('rate', store.seq, product.sku)) %% 60
                ORDER BY store.seq, product.id, days_back.seq DESC
                SQL,
            $storeCount,
            $productCount,
            $dayCount - 1,
        ));
        $reportProgress(sprintf(
            'Sales history: %d rows in %.1f s',
            $salesRowCount,
            (hrtime(true) - $startedAt) / 1e9,
        ));

        $stockRowCount = $this->connection->executeStatement(sprintf(
            <<<'SQL'
                INSERT INTO store_stock (store_number, product_id, quantity_on_hand, quantity_on_order)
                SELECT store.seq, product.id,
                       1 + CRC32(CONCAT('on hand', store.seq, product.sku)) %% 40,
                       IF(CRC32(CONCAT('on order', store.seq, product.sku)) %% 4 = 0, 10, 0)
                FROM seq_1_to_%d store
                CROSS JOIN (SELECT id, sku FROM product ORDER BY sku LIMIT %d) product
                SQL,
            $storeCount,
            $productCount,
        ));
        $reportProgress(sprintf('Store stock: %d rows', $stockRowCount));
        if ($stockRowCount < $storeCount * $productCount) {
            $reportProgress(sprintf(
                'The catalogue has only %d products.',
                intdiv(MixedToInteger::transformStrict($stockRowCount), $storeCount),
            ));
        }
    }
}
