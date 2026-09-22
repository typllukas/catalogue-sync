<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\ProductFixtures;
use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\ProductDriftChecker;
use App\Tests\ElasticsearchIndexTrait;
use App\Tests\EntityGettersTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

use function count;
use function iterator_to_array;

final class ProductDriftCheckerTest extends KernelTestCase
{
    use ElasticsearchIndexTrait;
    use EntityGettersTrait;

    private ProductDriftChecker $productDriftChecker;

    private ProductDocumentFactory $productDocumentFactory;

    private EntityManagerInterface $entityManager;

    private string $indexName;

    private Product $product;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->productDriftChecker = $container->get(ProductDriftChecker::class);
        $this->productDocumentFactory = $container->get(ProductDocumentFactory::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->indexName = self::createProductIndex();

        $this->product = $this->getProductEntity(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID);
    }

    protected function tearDown(): void
    {
        self::dropIndex($this->indexName);

        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function findDriftedIds(): array
    {
        return iterator_to_array($this->productDriftChecker->iterateDriftedProductIds(), false);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function indexDocument(array $document): void
    {
        self::indexDocuments([
            ['index' => ['_index' => $this->indexName, '_id' => $this->product->getId()->toBase32()]],
            $document,
        ]);
    }

    public function testAProductTheIndexDoesNotHoldIsDrifted(): void
    {
        self::assertContains($this->product->getId()->toBase32(), $this->findDriftedIds());
    }

    public function testADocumentWithAStalePriceIsDrifted(): void
    {
        $document = $this->productDocumentFactory->build($this->product);
        self::assertNotSame(99.9, $document['price']);
        $document['price'] = 99.9;
        $this->indexDocument($document);

        self::assertContains($this->product->getId()->toBase32(), $this->findDriftedIds());
    }

    public function testADocumentThatMatchesItsRowIsNotDrifted(): void
    {
        $this->indexDocument($this->productDocumentFactory->build($this->product));

        self::assertNotContains($this->product->getId()->toBase32(), $this->findDriftedIds());
    }

    /**
     * A whole crown price comes back from _source as a float, so the strict comparison holds.
     * A serialisation change here would report every such product as drifted.
     */
    public function testAWholeCrownPriceIsNotReportedAsDrift(): void
    {
        $this->product->setPriceInMinorUnits(20000);
        $this->entityManager->flush();
        $this->indexDocument($this->productDocumentFactory->build($this->product));

        self::assertNotContains($this->product->getId()->toBase32(), $this->findDriftedIds());
    }

    public function testADocumentWhoseProductIsGoneIsCountedAsSurplus(): void
    {
        $operations = [];
        foreach (self::getContainer()->get(ProductRepository::class)->findAll() as $product) {
            $operations[] = ['index' => ['_index' => $this->indexName, '_id' => $product->getId()->toBase32()]];
            $operations[] = $this->productDocumentFactory->build($product);
        }

        $operations[] = ['index' => ['_index' => $this->indexName, '_id' => new Ulid()->toBase32()]];
        $operations[] = $this->productDocumentFactory->build($this->product);
        self::indexDocuments($operations);

        self::assertSame([], $this->findDriftedIds());
        self::assertSame(1, $this->productDriftChecker->countSurplusDocuments());
    }

    public function testTheSampleCountsTheSameDrift(): void
    {
        $driftedIds = $this->findDriftedIds();
        self::assertNotSame([], $driftedIds);

        self::assertSame(count($driftedIds), $this->productDriftChecker->countDriftedInSample(1000));
    }
}
