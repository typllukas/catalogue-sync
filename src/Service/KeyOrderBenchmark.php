<?php

declare(strict_types=1);

namespace App\Service;

use App\Helper\MixedToInteger;
use App\Helper\MixedToString;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

use function array_keys;
use function hrtime;
use function implode;
use function intdiv;
use function sprintf;

final readonly class KeyOrderBenchmark
{
    private const int TIMED_RUN_COUNT = 3;

    private const array ANALYZE_COLUMNS = ['table', 'type', 'key', 'rows', 'r_rows', 'r_filtered', 'Extra'];

    private const array COPY_TABLE_DEFINITIONS = [
        'daily_store_sales_store_first' => <<<'SQL'
            CREATE TABLE daily_store_sales_store_first (
                store_number SMALLINT UNSIGNED NOT NULL, product_id BINARY(16) NOT NULL, sales_date DATE NOT NULL,
                quantity SMALLINT UNSIGNED NOT NULL, PRIMARY KEY (store_number, product_id, sales_date)
            )
            SQL,
        'daily_store_sales_date_first' => <<<'SQL'
            CREATE TABLE daily_store_sales_date_first (
                store_number SMALLINT UNSIGNED NOT NULL, product_id BINARY(16) NOT NULL, sales_date DATE NOT NULL,
                quantity SMALLINT UNSIGNED NOT NULL, PRIMARY KEY (sales_date, store_number, product_id)
            )
            SQL,
        'daily_store_sales_id_keyed' => <<<'SQL'
            CREATE TABLE daily_store_sales_id_keyed (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, store_number SMALLINT UNSIGNED NOT NULL,
                product_id BINARY(16) NOT NULL, sales_date DATE NOT NULL, quantity SMALLINT UNSIGNED NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY natural_key (store_number, product_id, sales_date)
            )
            SQL,
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     */
    public function run(DateTimeImmutable $lastSalesDate, callable $reportProgress): void
    {
        $windowStart = $lastSalesDate
            ->modify(sprintf('-%d days', PeriodicReviewPolicy::DEMAND_WINDOW_DAYS - 1))
            ->format('Y-m-d');
        $windowEnd = $lastSalesDate->format('Y-m-d');

        try {
            foreach (self::COPY_TABLE_DEFINITIONS as $copyTableName => $copyTableDefinition) {
                $startedAt = hrtime(true);
                $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $copyTableName);
                $this->connection->executeStatement($copyTableDefinition);
                // in the order a daily import would write the rows
                $this->connection->executeStatement(sprintf(
                    <<<'SQL'
                        INSERT INTO %s (store_number, product_id, sales_date, quantity)
                        SELECT store_number, product_id, sales_date, quantity FROM daily_store_sales
                        ORDER BY sales_date, store_number, product_id
                        SQL,
                    $copyTableName,
                ));
                $reportProgress(sprintf('Copy %s made in %.1f s', $copyTableName, (hrtime(true) - $startedAt) / 1e9));
            }

            $this->connection->fetchAllAssociative(
                'ANALYZE TABLE daily_store_sales, ' . implode(', ', array_keys(self::COPY_TABLE_DEFINITIONS)),
            );
            $tableSizes = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT table_name, data_length, index_length FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name LIKE 'daily_store_sales%'
                    ORDER BY table_name
                    SQL,
            );
            foreach ($tableSizes as $tableSize) {
                $reportProgress(sprintf(
                    '%s: %d MB of rows, %d MB of secondary indexes',
                    MixedToString::transformStrict($tableSize['table_name']),
                    intdiv(MixedToInteger::transformStrict($tableSize['data_length']), 1024 * 1024),
                    intdiv(MixedToInteger::transformStrict($tableSize['index_length']), 1024 * 1024),
                ));
            }

            $variants = [
                'key (store, product, date), filled in key order, the totals grouped' => $this->buildGroupedTotalsQuery(
                    'daily_store_sales',
                ),
                'key (store, product, date), filled day by day, the totals grouped' => $this->buildGroupedTotalsQuery(
                    'daily_store_sales_store_first',
                ),
                'key (store, product, date), driven from the stock rows' => <<<'SQL'
                    SELECT COUNT(*) FROM (
                        SELECT stock.store_number, stock.product_id,
                               SUM(sales.quantity), SUM(sales.quantity * sales.quantity)
                        FROM store_stock stock
                        LEFT JOIN daily_store_sales sales
                            ON sales.store_number = stock.store_number
                            AND sales.product_id = stock.product_id
                            AND sales.sales_date BETWEEN :windowStart AND :windowEnd
                        GROUP BY stock.store_number, stock.product_id
                    ) totals
                    SQL,
                'key (date, store, product), the totals grouped' => $this->buildGroupedTotalsQuery(
                    'daily_store_sales_date_first',
                ),
                'key id, unique (store, product, date), the totals grouped' => $this->buildGroupedTotalsQuery(
                    'daily_store_sales_id_keyed',
                ),
                'key id, forced onto the unique key, the totals grouped' => $this->buildGroupedTotalsQuery(
                    'daily_store_sales_id_keyed FORCE INDEX (natural_key)',
                ),
            ];
            $parameters = ['windowStart' => $windowStart, 'windowEnd' => $windowEnd];

            foreach ($variants as $variantName => $sql) {
                $reportProgress('');
                $reportProgress($variantName);
                foreach ($this->connection->fetchAllAssociative('ANALYZE ' . $sql, $parameters) as $planRow) {
                    $planCells = [];
                    foreach (self::ANALYZE_COLUMNS as $column) {
                        $planCells[] = $column . ' ' . MixedToString::transformStrict($planRow[$column] ?? 'NULL');
                    }

                    $reportProgress('  ' . implode(' | ', $planCells));
                }

                $runTimes = [];
                for ($runNumber = 1; $runNumber <= self::TIMED_RUN_COUNT; ++$runNumber) {
                    $runStartedAt = hrtime(true);
                    $this->connection->fetchOne($sql, $parameters);
                    $runTimes[] = sprintf('%.2f s', (hrtime(true) - $runStartedAt) / 1e9);
                }

                $reportProgress('  runs after the ANALYZE warm-up: ' . implode(', ', $runTimes));
            }
        } finally {
            foreach (array_keys(self::COPY_TABLE_DEFINITIONS) as $copyTableName) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $copyTableName);
            }
        }
    }

    private function buildGroupedTotalsQuery(string $tableReference): string
    {
        return sprintf(
            <<<'SQL'
                SELECT COUNT(*) FROM (
                    SELECT store_number, product_id, SUM(quantity), SUM(quantity * quantity)
                    FROM %s
                    WHERE sales_date BETWEEN :windowStart AND :windowEnd
                    GROUP BY store_number, product_id
                ) totals
                SQL,
            $tableReference,
        );
    }
}
