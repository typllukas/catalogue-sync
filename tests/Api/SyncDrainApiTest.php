<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DataFixtures\ProductFixtures;
use App\DTO\ProductUpdateInput;
use App\Repository\ProductSyncOutboxRepository;
use App\Repository\ProductSyncRunRepository;
use App\Service\ProductUpdater;
use App\Tests\ApiTestCase;
use App\Tests\ElasticsearchIndexTrait;
use App\Tests\EntityGettersTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * POST /api/sync/drain
 */
final class SyncDrainApiTest extends ApiTestCase
{
    use ElasticsearchIndexTrait;
    use EntityGettersTrait;

    private KernelBrowser $client;

    private string $indexName;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->indexName = self::createProductIndex();

        self::getContainer()->get(ProductUpdater::class)->update(
            $this->getProductEntity(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID),
            new ProductUpdateInput(priceInMinorUnits: 12900),
        );
    }

    protected function tearDown(): void
    {
        self::dropIndex($this->indexName);

        parent::tearDown();
    }

    public function testDrainingEmptiesTheQueue(): void
    {
        $productSyncOutboxRepository = self::getContainer()->get(ProductSyncOutboxRepository::class);
        self::assertSame(1, $productSyncOutboxRepository->countPending($this->indexName));

        $this->client->request('POST', '/api/sync/drain');

        self::assertSame(1, $this->getResponseBody($this->client)['writtenProductCount']);
        self::assertSame(0, $productSyncOutboxRepository->countPending($this->indexName));
    }

    public function testACallThatFindsNothingIsNotRecordedAsTheLastBatch(): void
    {
        $this->client->request('POST', '/api/sync/drain');

        $this->client->request('POST', '/api/sync/drain');

        self::assertSame(0, $this->getResponseBody($this->client)['writtenProductCount']);
        self::assertSame(
            1,
            self::getContainer()->get(ProductSyncRunRepository::class)->findLatest()?->getWrittenProductCount(),
        );
    }
}
