<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ProductUpdateInput;
use App\Elasticsearch\IndexNameFactory;
use App\Entity\Product;
use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Repository\ProductSyncOutboxRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProductUpdater
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductSyncOutboxRepository $productSyncOutboxRepository,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    public function update(Product $product, ProductUpdateInput $input): void
    {
        $scopes = [];

        if ($input->priceInMinorUnits !== null) {
            $product->setPriceInMinorUnits($input->priceInMinorUnits);
            $scopes[] = ProductSyncScope::PRICE;
        }

        if ($input->stockQuantity !== null) {
            $product->setStockQuantity($input->stockQuantity);
            $scopes[] = ProductSyncScope::STOCK;
        }

        if ($scopes === []) {
            return;
        }

        // flush first: the feed also locks the product row before the outbox row, and the opposite order deadlocks
        $this->entityManager->wrapInTransaction(function () use ($product, $scopes): void {
            $this->entityManager->flush();

            $this->productSyncOutboxRepository->enqueue(
                $this->indexNameFactory->build(),
                $product->getId(),
                $scopes,
                ProductSyncOperation::WRITE,
                new DateTimeImmutable(),
            );
        });
    }
}
