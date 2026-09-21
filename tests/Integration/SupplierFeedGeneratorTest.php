<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Helper\MixedToInteger;
use App\Repository\ProductRepository;
use App\Repository\ProductSyncOutboxRepository;
use App\Service\CatalogueDataGenerator;
use App\Service\ProductSyncDrain;
use App\Service\SupplierFeedGenerator;
use App\Tests\ElasticsearchIndexTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SupplierFeedGeneratorTest extends KernelTestCase
{
    use ElasticsearchIndexTrait;

    private const int PRODUCT_COUNT = 300;

    private const int FEED_ROWS = 300;

    private SupplierFeedGenerator $supplierFeedGenerator;

    private ProductSyncDrain $productSyncDrain;

    private ProductSyncOutboxRepository $productSyncOutboxRepository;

    private Connection $connection;

    private string $indexName;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->supplierFeedGenerator = $container->get(SupplierFeedGenerator::class);
        $this->productSyncDrain = $container->get(ProductSyncDrain::class);
        $this->productSyncOutboxRepository = $container->get(ProductSyncOutboxRepository::class);
        $this->connection = $container->get(Connection::class);
        $this->indexName = self::createProductIndex();

        // the generator builds from empty tables, as make reset leaves them, and would collide with the fixture
        $this->connection->executeStatement('DELETE FROM product');
        $this->connection->executeStatement('DELETE FROM category');
        $this->connection->executeStatement('DELETE FROM brand');
        $container->get(CatalogueDataGenerator::class)->generate(self::PRODUCT_COUNT, $this->discardProgress());

        foreach ($container->get(ProductRepository::class)->iterateIdBatches(self::PRODUCT_COUNT) as $productIds) {
            $this->productSyncOutboxRepository->enqueueMany(
                $this->indexName,
                $productIds,
                [ProductSyncScope::EVERYTHING],
                ProductSyncOperation::WRITE,
                new DateTimeImmutable(),
            );
        }

        $this->productSyncDrain->drain(self::PRODUCT_COUNT);
        self::$elasticsearchClient->indices()->refresh(['index' => $this->indexName]);
    }

    protected function tearDown(): void
    {
        self::dropIndex($this->indexName);

        parent::tearDown();
    }

    /**
     * @return callable(string): void
     */
    private function discardProgress(): callable
    {
        return static function (string $message): void {
        };
    }

    public function testTheResultReportsTheFileRowsAndTheMarkedProducts(): void
    {
        $feedResult = $this->supplierFeedGenerator->generate(self::FEED_ROWS, $this->discardProgress());

        self::assertSame(self::FEED_ROWS, $feedResult->touchedRowCount);
        self::assertSame(
            $this->productSyncOutboxRepository->countPending($this->indexName),
            $feedResult->pendingProductCount,
        );
        self::assertGreaterThan(0, $feedResult->pendingProductCount);
    }

    /**
     * A file that moved every price would make detect_noop unreachable, so most of the assortment
     * has to stay untouched.
     */
    public function testADrainAfterTheFileBothWritesAndSkips(): void
    {
        $this->supplierFeedGenerator->generate(self::FEED_ROWS, $this->discardProgress());

        $drainResult = $this->productSyncDrain->drain(self::FEED_ROWS);

        self::assertGreaterThan(0, $drainResult->writtenProductCount);
        self::assertGreaterThan(0, $drainResult->unchangedProductCount);
        self::assertGreaterThan($drainResult->writtenProductCount, $drainResult->unchangedProductCount);
    }

    public function testRepeatedFilesKeepEveryPriceUnderTheCeiling(): void
    {
        for ($run = 0; $run < 5; ++$run) {
            $this->supplierFeedGenerator->generate(self::FEED_ROWS, $this->discardProgress());
        }

        self::assertLessThanOrEqual(
            SupplierFeedGenerator::MAXIMUM_PRICE,
            MixedToInteger::transformStrict(
                $this->connection->fetchOne('SELECT MAX(price_in_minor_units) FROM product'),
            ),
        );
    }

    public function testEveryMarkedRowIsReleased(): void
    {
        $this->supplierFeedGenerator->generate(self::FEED_ROWS, $this->discardProgress());
        self::assertGreaterThan(0, $this->productSyncOutboxRepository->countPending($this->indexName));

        $this->productSyncDrain->drain(self::FEED_ROWS);

        self::assertSame(0, $this->productSyncOutboxRepository->countPending($this->indexName));
    }
}
