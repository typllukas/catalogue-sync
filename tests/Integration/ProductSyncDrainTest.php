<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\ProductFixtures;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Entity\Product;
use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Repository\ProductSyncOutboxRepository;
use App\Service\ProductSyncDrain;
use App\Tests\ElasticsearchIndexTrait;
use App\Tests\EntityGettersTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

use function array_last;

final class ProductSyncDrainTest extends KernelTestCase
{
    use ElasticsearchIndexTrait;
    use EntityGettersTrait;

    private ProductSyncDrain $productSyncDrain;

    private ProductSyncOutboxRepository $productSyncOutboxRepository;

    private EntityManagerInterface $entityManager;

    private string $indexName;

    private Product $product;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->productSyncDrain = $container->get(ProductSyncDrain::class);
        $this->productSyncOutboxRepository = $container->get(ProductSyncOutboxRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->indexName = self::createProductIndex();
        $this->product = $this->getProductEntity(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID);
    }

    protected function tearDown(): void
    {
        self::dropIndex($this->indexName);

        parent::tearDown();
    }

    private function enqueue(Ulid $productId, ProductSyncScope $scope): void
    {
        $this->productSyncOutboxRepository->enqueue(
            $this->indexName,
            $productId,
            [$scope],
            ProductSyncOperation::WRITE,
            new DateTimeImmutable(),
        );
    }

    private function enqueueDelete(Ulid $productId, DateTimeImmutable $markedAt): void
    {
        $this->productSyncOutboxRepository->enqueue(
            $this->indexName,
            $productId,
            [ProductSyncScope::EVERYTHING],
            ProductSyncOperation::DELETE,
            $markedAt,
        );
    }

    private function isDocumentIndexed(Ulid $productId): bool
    {
        self::$elasticsearchClient->indices()->refresh(['index' => $this->indexName]);

        $response = self::$elasticsearchClient->exists([
            'index' => $this->indexName,
            'id' => $productId->toBase32(),
        ]);
        self::assertInstanceOf(Elasticsearch::class, $response);

        return $response->asBool();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readSource(Ulid $productId): array
    {
        self::$elasticsearchClient->indices()->refresh(['index' => $this->indexName]);

        $response = ResponseBody::read(self::$elasticsearchClient->get([
            'index' => $this->indexName,
            'id' => $productId->toBase32(),
        ]));

        return ResponseBody::readArray($response, '_source');
    }

    public function testDrainingAnUnchangedProductTwiceReportsTheSecondPassAsUnchanged(): void
    {
        $this->enqueue($this->product->getId(), ProductSyncScope::EVERYTHING);
        $firstPass = $this->productSyncDrain->drain(10);

        $this->enqueue($this->product->getId(), ProductSyncScope::EVERYTHING);
        $secondPass = $this->productSyncDrain->drain(10);

        self::assertSame(1, $firstPass->writtenProductCount);
        self::assertSame(0, $firstPass->unchangedProductCount);
        self::assertSame(0, $secondPass->writtenProductCount);
        self::assertSame(1, $secondPass->unchangedProductCount);
    }

    public function testAPriceScopedRowForAnUnknownProductStillProducesACompleteDocument(): void
    {
        self::assertFalse($this->isDocumentIndexed($this->product->getId()));
        $this->enqueue($this->product->getId(), ProductSyncScope::PRICE);

        $drainResult = $this->productSyncDrain->drain(10);

        self::assertSame(1, $drainResult->writtenProductCount);
        self::assertSame($this->product->getName(), $this->readSource($this->product->getId())['name']);
    }

    public function testAPriceChangeOnAKnownProductSendsOnlyThatFieldAndKeepsTheRest(): void
    {
        $this->enqueue($this->product->getId(), ProductSyncScope::EVERYTHING);
        $this->productSyncDrain->drain(10);

        $reloadedProduct = $this->entityManager->find(Product::class, $this->product->getId());
        self::assertInstanceOf(Product::class, $reloadedProduct);
        $indexedName = $reloadedProduct->getName();
        $newPriceInMinorUnits = $reloadedProduct->getPriceInMinorUnits() + 1000;
        $reloadedProduct->setPriceInMinorUnits($newPriceInMinorUnits);
        $reloadedProduct->setName('Test Name');
        $this->entityManager->flush();
        $this->enqueue($this->product->getId(), ProductSyncScope::PRICE);

        $drainResult = $this->productSyncDrain->drain(10);

        self::assertSame(1, $drainResult->writtenProductCount);
        $document = $this->readSource($this->product->getId());
        self::assertSame($newPriceInMinorUnits / ProductDocumentFactory::MINOR_UNITS_PER_WHOLE, $document['price']);
        self::assertSame($indexedName, $document['name']);
        $categoryPaths = ResponseBody::readArray(ResponseBody::readArray($document, 'category'), 'path');
        self::assertSame($reloadedProduct->getCategory()->getSlugPath(), array_last($categoryPaths));
    }

    /**
     * A delete ahead of the writes leaves a gap in the filtered rows, and a gap makes the id list
     * encode as a JSON object rather than an array, which Elasticsearch answers with a 400.
     */
    public function testABatchWhoseFirstRowIsADeleteStillWritesTheRest(): void
    {
        $this->enqueueDelete(new Ulid(), new DateTimeImmutable('2026-09-18 10:00:00.000'));
        $this->enqueue($this->product->getId(), ProductSyncScope::EVERYTHING);

        $drainResult = $this->productSyncDrain->drain(10);

        self::assertSame(1, $drainResult->writtenProductCount);
        self::assertSame($this->product->getName(), $this->readSource($this->product->getId())['name']);
    }

    public function testADeleteRowRemovesTheDocument(): void
    {
        $this->enqueue($this->product->getId(), ProductSyncScope::EVERYTHING);
        $this->productSyncDrain->drain(10);
        self::assertTrue($this->isDocumentIndexed($this->product->getId()));

        $this->enqueueDelete($this->product->getId(), new DateTimeImmutable());
        $drainResult = $this->productSyncDrain->drain(10);

        self::assertSame(1, $drainResult->deletedProductCount);
        self::assertFalse($this->isDocumentIndexed($this->product->getId()));
    }

    public function testADrainWithoutTheIndexFailsAndKeepsTheRowPending(): void
    {
        self::dropIndex($this->indexName);
        $this->enqueue($this->product->getId(), ProductSyncScope::EVERYTHING);
        self::assertSame(1, $this->productSyncOutboxRepository->countPending($this->indexName));

        try {
            $this->productSyncDrain->drain(10);
            self::fail('The drain wrote with no index to write into.');
        } catch (SearchUnavailableException) {
        }

        self::assertSame(1, $this->productSyncOutboxRepository->countPending($this->indexName));
    }

    /**
     * The supplier file can list a product again after withdrawing it, and the outbox keeps one row per
     * product, so the later write replaces the delete.
     */
    public function testAWriteRowForAWithdrawnProductRemovesTheDocument(): void
    {
        $productId = $this->product->getId();
        $this->enqueue($productId, ProductSyncScope::EVERYTHING);
        $this->productSyncDrain->drain(10);
        self::assertTrue($this->isDocumentIndexed($productId));

        $reloadedProduct = $this->entityManager->find(Product::class, $productId);
        self::assertInstanceOf(Product::class, $reloadedProduct);
        $this->entityManager->remove($reloadedProduct);
        $this->entityManager->flush();
        $this->enqueueDelete($productId, new DateTimeImmutable());
        $this->enqueue($productId, ProductSyncScope::PRICE);
        self::assertSame(
            ProductSyncOperation::WRITE,
            $this->productSyncOutboxRepository->findPending($this->indexName, 10)[0]->operation,
        );

        $drainResult = $this->productSyncDrain->drain(10);

        self::assertSame(1, $drainResult->deletedProductCount);
        self::assertFalse($this->isDocumentIndexed($productId));
    }
}
