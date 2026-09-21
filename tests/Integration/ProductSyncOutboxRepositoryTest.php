<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Elasticsearch\IndexNameFactory;
use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Repository\ProductSyncOutboxRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class ProductSyncOutboxRepositoryTest extends KernelTestCase
{
    private ProductSyncOutboxRepository $productSyncOutboxRepository;

    private string $indexName;

    private DateTimeImmutable $markedAt;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->productSyncOutboxRepository = $container->get(ProductSyncOutboxRepository::class);
        $this->indexName = $container->get(IndexNameFactory::class)->build();
        $this->markedAt = new DateTimeImmutable('2026-09-18 10:00:00.000');
    }

    private function enqueue(Ulid $productId, ProductSyncScope $scope, DateTimeImmutable $markedAt): void
    {
        $this->productSyncOutboxRepository->enqueue(
            $this->indexName,
            $productId,
            [$scope],
            ProductSyncOperation::WRITE,
            $markedAt,
        );
    }

    public function testRepeatedTouchesCollapseIntoOneRowHoldingTheUnionOfScopes(): void
    {
        $productId = new Ulid();
        $this->enqueue($productId, ProductSyncScope::PRICE, $this->markedAt);
        $this->enqueue($productId, ProductSyncScope::STOCK, $this->markedAt->modify('+1 second'));

        $pendingProductSyncs = $this->productSyncOutboxRepository->findPending($this->indexName, 10);

        self::assertCount(1, $pendingProductSyncs);
        self::assertSame(
            [ProductSyncScope::PRICE, ProductSyncScope::STOCK],
            $pendingProductSyncs[0]->scopes,
        );
    }

    public function testARowMarkedAgainAfterItWasReadIsNotReleased(): void
    {
        $productId = new Ulid();
        $this->enqueue($productId, ProductSyncScope::PRICE, $this->markedAt);
        $pendingProductSyncs = $this->productSyncOutboxRepository->findPending($this->indexName, 10);
        self::assertCount(1, $pendingProductSyncs);
        $this->enqueue($productId, ProductSyncScope::STOCK, $this->markedAt->modify('+5 milliseconds'));

        self::assertSame(0, $this->productSyncOutboxRepository->release($this->indexName, $pendingProductSyncs));
        self::assertCount(1, $this->productSyncOutboxRepository->findPending($this->indexName, 10));
    }

    public function testAnUntouchedRowIsReleased(): void
    {
        $this->enqueue(new Ulid(), ProductSyncScope::PRICE, $this->markedAt);
        $pendingProductSyncs = $this->productSyncOutboxRepository->findPending($this->indexName, 10);

        self::assertSame(1, $this->productSyncOutboxRepository->release($this->indexName, $pendingProductSyncs));
        self::assertSame([], $this->productSyncOutboxRepository->findPending($this->indexName, 10));
    }

    public function testTheQueueDepthCountsOnlyThisIndex(): void
    {
        $this->enqueue(new Ulid(), ProductSyncScope::PRICE, $this->markedAt);
        $this->productSyncOutboxRepository->enqueue(
            'products_other',
            new Ulid(),
            [ProductSyncScope::PRICE],
            ProductSyncOperation::WRITE,
            $this->markedAt,
        );

        self::assertSame(1, $this->productSyncOutboxRepository->countPending($this->indexName));
    }
}
