<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\ProductFixtures;
use App\DTO\SalesWindowTotals;
use App\Repository\DailyStoreSalesRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

use function array_keys;
use function iterator_to_array;
use function sprintf;

final class DailyStoreSalesRepositoryTest extends KernelTestCase
{
    private const int STORE_WITH_STOCK = 1;

    private const int STORE_WITHOUT_SALES_IN_THE_WINDOW = 2;

    private const int STORE_WITHOUT_STOCK = 3;

    private Connection $connection;

    private DailyStoreSalesRepository $dailyStoreSalesRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->dailyStoreSalesRepository = $container->get(DailyStoreSalesRepository::class);
    }

    private function insertStock(int $storeNumber, int $quantityOnHand, int $quantityOnOrder): void
    {
        $this->connection->insert('store_stock', [
            'store_number' => $storeNumber,
            'product_id' => Ulid::fromString(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID)->toBinary(),
            'quantity_on_hand' => $quantityOnHand,
            'quantity_on_order' => $quantityOnOrder,
        ], ['product_id' => ParameterType::BINARY]);
    }

    private function insertSale(int $storeNumber, string $salesDate, int $quantity): void
    {
        $this->connection->insert('daily_store_sales', [
            'store_number' => $storeNumber,
            'product_id' => Ulid::fromString(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID)->toBinary(),
            'sales_date' => $salesDate,
            'quantity' => $quantity,
        ], ['product_id' => ParameterType::BINARY]);
    }

    /**
     * Sales on seven days of the window, and one before it that must not count.
     */
    private function insertSalesHistory(int $storeNumber): void
    {
        $this->insertSale($storeNumber, '2026-08-01', 100);
        for ($dayOfMonth = 24; $dayOfMonth <= 30; ++$dayOfMonth) {
            $this->insertSale($storeNumber, sprintf('2026-09-%d', $dayOfMonth), 7);
        }
    }

    /**
     * @return array<int, SalesWindowTotals>
     */
    private function findSalesWindowTotalsByStore(): array
    {
        $salesWindowTotalsByStore = [];
        $salesWindowTotalsPerPair = $this->dailyStoreSalesRepository->iterateSalesWindowTotals(
            new DateTimeImmutable('2026-09-03'),
            new DateTimeImmutable('2026-09-30'),
        );
        foreach (iterator_to_array($salesWindowTotalsPerPair, false) as $salesWindowTotals) {
            $salesWindowTotalsByStore[$salesWindowTotals->storeNumber] = $salesWindowTotals;
        }

        return $salesWindowTotalsByStore;
    }

    public function testTotalsSumOnlyTheWindowAndCarryTheStock(): void
    {
        $this->insertStock(self::STORE_WITH_STOCK, 5, 10);
        $this->insertSalesHistory(self::STORE_WITH_STOCK);

        $salesWindowTotals = $this->findSalesWindowTotalsByStore()[self::STORE_WITH_STOCK];

        self::assertSame('MAK-DHP484Z', $salesWindowTotals->productSku);
        self::assertSame(49, $salesWindowTotals->quantitySum);
        self::assertSame(343, $salesWindowTotals->quantitySquareSum);
        self::assertSame(5, $salesWindowTotals->quantityOnHand);
        self::assertSame(10, $salesWindowTotals->quantityOnOrder);
    }

    public function testTheWindowIncludesItsFirstDayAndNotTheDayBefore(): void
    {
        $this->insertSale(self::STORE_WITH_STOCK, '2026-09-02', 100);
        $this->insertSale(self::STORE_WITH_STOCK, '2026-09-03', 28);

        self::assertSame(28, $this->findSalesWindowTotalsByStore()[self::STORE_WITH_STOCK]->quantitySum);
    }

    public function testAPairWithoutSalesInTheWindowIsLeftOut(): void
    {
        $this->insertStock(self::STORE_WITH_STOCK, 5, 0);
        $this->insertSalesHistory(self::STORE_WITH_STOCK);
        $this->insertStock(self::STORE_WITHOUT_SALES_IN_THE_WINDOW, 3, 0);
        $this->insertSale(self::STORE_WITHOUT_SALES_IN_THE_WINDOW, '2026-08-01', 100);

        self::assertSame([self::STORE_WITH_STOCK], array_keys($this->findSalesWindowTotalsByStore()));
    }

    public function testAPairWithSalesAndNoStockRowCountsAsNothingOnHand(): void
    {
        $this->insertSalesHistory(self::STORE_WITHOUT_STOCK);

        $salesWindowTotals = $this->findSalesWindowTotalsByStore()[self::STORE_WITHOUT_STOCK];

        self::assertSame(0, $salesWindowTotals->quantityOnHand);
        self::assertSame(0, $salesWindowTotals->quantityOnOrder);
    }
}
