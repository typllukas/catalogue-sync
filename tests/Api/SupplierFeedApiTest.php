<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Elasticsearch\IndexNameFactory;
use App\Repository\ProductSyncOutboxRepository;
use App\Tests\ApiTestCase;

/**
 * POST /api/dev/supplier-feed
 */
final class SupplierFeedApiTest extends ApiTestCase
{
    public function testTheFeedFillsTheQueue(): void
    {
        $client = self::createClient();

        $productSyncOutboxRepository = self::getContainer()->get(ProductSyncOutboxRepository::class);
        $indexName = self::getContainer()->get(IndexNameFactory::class)->build();
        self::assertSame(0, $productSyncOutboxRepository->countPending($indexName));

        $client->request('POST', '/api/dev/supplier-feed');

        self::assertResponseIsSuccessful();
        $body = $this->getResponseBody($client);
        self::assertGreaterThan(0, $body['touchedRowCount']);
        self::assertGreaterThan(0, $body['pendingProductCount']);
        self::assertSame($body['pendingProductCount'], $productSyncOutboxRepository->countPending($indexName));
    }
}
