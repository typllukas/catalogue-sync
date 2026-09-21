<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DataFixtures\ProductFixtures;
use App\DTO\ProductUpdateInput;
use App\Repository\ProductSyncRunRepository;
use App\Service\ProductSyncDrain;
use App\Service\ProductUpdater;
use App\Tests\ApiTestCase;
use App\Tests\ElasticsearchIndexTrait;
use App\Tests\EntityGettersTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET /api/sync/status
 */
final class SyncStatusApiTest extends ApiTestCase
{
    use ElasticsearchIndexTrait;
    use EntityGettersTrait;

    private KernelBrowser $client;

    private string $indexName;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->indexName = self::createProductIndex();
    }

    protected function tearDown(): void
    {
        self::dropIndex($this->indexName);

        parent::tearDown();
    }

    public function testBeforeAnyDrainTheStatusReportsNoLastBatch(): void
    {
        self::assertNull(self::getContainer()->get(ProductSyncRunRepository::class)->findLatest());

        $this->client->request('GET', '/api/sync/status');

        self::assertResponseIsSuccessful();
        $status = $this->getResponseBody($this->client);
        self::assertNull($status['lastDrainAt']);
        self::assertSame(0, $status['lastDrainWrittenProductCount']);
    }

    public function testTheStatusAfterADrainReportsTheDrainAndNoDrift(): void
    {
        $container = self::getContainer();
        $container->get(ProductUpdater::class)->update(
            $this->getProductEntity(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID),
            new ProductUpdateInput(priceInMinorUnits: 12900),
        );
        $container->get(ProductSyncDrain::class)->drain(10);

        $this->client->request('GET', '/api/sync/status');

        $status = $this->getResponseBody($this->client);
        self::assertSame(0, $status['pendingProductCount']);
        self::assertSame(1, $status['lastDrainWrittenProductCount']);
        self::assertNotNull($status['lastDrainAt']);
        self::assertSame(0, $status['driftedProductCount']);
    }
}
