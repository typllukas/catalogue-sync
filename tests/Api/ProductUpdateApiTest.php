<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DataFixtures\ProductFixtures;
use App\DTO\PendingProductSync;
use App\Elasticsearch\IndexNameFactory;
use App\Enum\ProductSyncScope;
use App\Repository\ProductSyncOutboxRepository;
use App\Tests\ApiTestCase;
use App\Tests\EntityGettersTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * PATCH /api/products/{id}
 */
final class ProductUpdateApiTest extends ApiTestCase
{
    use EntityGettersTrait;

    /**
     * @return array<int, PendingProductSync>
     */
    private function findPendingProductSyncs(): array
    {
        $container = self::getContainer();

        return $container->get(ProductSyncOutboxRepository::class)
            ->findPending($container->get(IndexNameFactory::class)->build(), 10);
    }

    public function testAStockChangeWritesTheRowAndMarksOnlyTheStock(): void
    {
        $client = self::createClient();
        $stockQuantity = 3;

        $product = $this->getProductEntity(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID);
        self::assertNotSame($stockQuantity, $product->getStockQuantity());
        self::assertSame([], $this->findPendingProductSyncs());

        $client->jsonRequest(
            'PATCH',
            '/api/products/' . $product->getId()->toBase32(),
            ['stockQuantity' => $stockQuantity],
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());
        self::getContainer()->get(EntityManagerInterface::class)->refresh($product);
        self::assertSame($stockQuantity, $product->getStockQuantity());
        $pendingProductSyncs = $this->findPendingProductSyncs();
        self::assertCount(1, $pendingProductSyncs);
        self::assertSame([ProductSyncScope::STOCK], $pendingProductSyncs[0]->scopes);
    }

    public function testAPriceChangeWritesTheRowAndMarksOnlyThePrice(): void
    {
        $client = self::createClient();
        $priceInMinorUnits = 12900;

        $product = $this->getProductEntity(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID);
        self::assertNotSame($priceInMinorUnits, $product->getPriceInMinorUnits());
        self::assertSame([], $this->findPendingProductSyncs());

        $client->jsonRequest(
            'PATCH',
            '/api/products/' . $product->getId()->toBase32(),
            ['priceInMinorUnits' => $priceInMinorUnits],
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());
        self::getContainer()->get(EntityManagerInterface::class)->refresh($product);
        self::assertSame($priceInMinorUnits, $product->getPriceInMinorUnits());
        $pendingProductSyncs = $this->findPendingProductSyncs();
        self::assertCount(1, $pendingProductSyncs);
        self::assertSame([ProductSyncScope::PRICE], $pendingProductSyncs[0]->scopes);
    }

    public function testABodyThatChangesNothingMarksNothing(): void
    {
        $client = self::createClient();

        self::assertSame([], $this->findPendingProductSyncs());

        $client->jsonRequest('PATCH', '/api/products/' . ProductFixtures::PRODUCT_MAKITA_DRILL_ULID, []);

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->findPendingProductSyncs());
    }

    public function testAnUnknownProductIsNotFound(): void
    {
        $client = self::createClient();

        $client->jsonRequest('PATCH', '/api/products/' . new Ulid()->toBase32(), ['stockQuantity' => 3]);

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    public function testAPriceAboveWhatTheColumnHoldsIsRefusedWithAViolation(): void
    {
        $client = self::createClient();

        $client->jsonRequest(
            'PATCH',
            '/api/products/' . ProductFixtures::PRODUCT_MAKITA_DRILL_ULID,
            ['priceInMinorUnits' => 2147483648],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertContains('priceInMinorUnits', $this->getViolatedFields($client));
    }

    public function testANegativePriceIsRefusedWithAViolation(): void
    {
        $client = self::createClient();

        $client->jsonRequest(
            'PATCH',
            '/api/products/' . ProductFixtures::PRODUCT_MAKITA_DRILL_ULID,
            ['priceInMinorUnits' => -1],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertContains('priceInMinorUnits', $this->getViolatedFields($client));
    }
}
