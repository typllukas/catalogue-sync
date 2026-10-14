<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\SalesWindowTotals;
use App\Entity\DailyStoreSales;
use App\Helper\MixedToInteger;
use App\Helper\MixedToString;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyStoreSales>
 */
class DailyStoreSalesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyStoreSales::class);
    }

    public function findLastSalesDate(): ?DateTimeImmutable
    {
        $lastSalesDate = $this->createQueryBuilder('sales')
            ->select('MAX(sales.salesDate)')
            ->getQuery()
            ->getSingleScalarResult();

        return $lastSalesDate === null ? null : new DateTimeImmutable(MixedToString::transformStrict($lastSalesDate));
    }

    public function hasSalesOn(DateTimeImmutable $salesDate): bool
    {
        return $this->createQueryBuilder('sales')
            ->select('sales.storeNumber')
            ->where('sales.salesDate = :salesDate')
            ->setParameter('salesDate', $salesDate, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasSalesOnOrBefore(DateTimeImmutable $salesDate): bool
    {
        return $this->createQueryBuilder('sales')
            ->select('sales.storeNumber')
            ->where('sales.salesDate <= :salesDate')
            ->setParameter('salesDate', $salesDate, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * SQL, because DQL has no subquery in FROM: product and stock would be joined per day, not per pair.
     *
     * @return iterable<SalesWindowTotals>
     */
    public function iterateSalesWindowTotals(DateTimeImmutable $windowStart, DateTimeImmutable $asOfDate): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            <<<'SQL'
                SELECT window_totals.store_number, product.sku, window_totals.quantity_sum,
                       window_totals.quantity_square_sum,
                       COALESCE(stock.quantity_on_hand, 0) AS quantity_on_hand,
                       COALESCE(stock.quantity_on_order, 0) AS quantity_on_order
                FROM (
                    SELECT store_number, product_id, SUM(quantity) AS quantity_sum,
                           SUM(quantity * quantity) AS quantity_square_sum
                    FROM daily_store_sales
                    WHERE sales_date BETWEEN :windowStart AND :asOfDate
                    GROUP BY store_number, product_id
                ) window_totals
                JOIN product ON product.id = window_totals.product_id
                LEFT JOIN store_stock stock
                    ON stock.store_number = window_totals.store_number
                    AND stock.product_id = window_totals.product_id
                ORDER BY window_totals.store_number, product.sku
                SQL,
            [
                'windowStart' => $windowStart->format('Y-m-d'),
                'asOfDate' => $asOfDate->format('Y-m-d'),
            ],
        );

        foreach ($rows as $row) {
            yield new SalesWindowTotals(
                MixedToInteger::transformStrict($row['store_number']),
                MixedToString::transformStrict($row['sku']),
                MixedToInteger::transformStrict($row['quantity_sum']),
                MixedToInteger::transformStrict($row['quantity_square_sum']),
                MixedToInteger::transformStrict($row['quantity_on_hand']),
                MixedToInteger::transformStrict($row['quantity_on_order']),
            );
        }
    }
}
