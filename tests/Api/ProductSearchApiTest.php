<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Tests\ApiTestCase;
use App\Tests\ElasticsearchIndexTrait;
use Symfony\Component\HttpFoundation\Response;

use function array_keys;
use function sprintf;

/**
 * GET /api/products
 */
final class ProductSearchApiTest extends ApiTestCase
{
    use ElasticsearchIndexTrait;

    private ?string $indexName = null;

    protected function tearDown(): void
    {
        if ($this->indexName !== null) {
            self::dropIndex($this->indexName);
        }

        parent::tearDown();
    }

    private function indexCatalogue(): void
    {
        $this->indexName = self::createProductIndex();

        $category = new Category()->setName('Vrtačky')->setSlugPath('naradi/elektro/vrtacky');

        $productDocumentFactory = self::getContainer()->get(ProductDocumentFactory::class);
        $operations = [];
        foreach ([['MAK-1', 'Makita'], ['BOS-1', 'Bosch']] as $position => [$sku, $brandName]) {
            $brand = new Brand()->setName($brandName);

            $product = new Product()
                ->setSku($sku)
                ->setName('Produkt ' . $sku)
                ->setDescription('Popis produktu.')
                ->setEan(sprintf('85900000000%02d', $position))
                ->setPriceInMinorUnits(19990)
                ->setStockQuantity(5)
                ->setVisible(true)
                ->setBrand($brand)
                ->setCategory($category);
            $product->stampUpdatedAt();

            $operations[] = ['index' => ['_index' => $this->indexName, '_id' => $product->getId()->toBase32()]];
            $operations[] = $productDocumentFactory->build($product);
        }

        self::indexDocuments($operations);
    }

    public function testTheEndpointAnswersWithTheResultShape(): void
    {
        $client = self::createClient();
        $this->indexCatalogue();

        $client->request('GET', '/api/products');

        self::assertResponseIsSuccessful();
        $body = $this->getResponseBody($client);
        self::assertSame(2, $body['total']);
        self::assertArrayHasKey('elapsedMilliseconds', $body);

        $facets = $body['facets'];
        self::assertIsArray($facets);
        self::assertSame(['brand', 'category', 'availability'], array_keys($facets));

        $hits = $body['hits'];
        self::assertIsArray($hits);
        $firstHit = $hits[0];
        self::assertIsArray($firstHit);
        self::assertSame('naradi/elektro/vrtacky', $firstHit['categoryPath']);
    }

    public function testABrandListParameterNarrowsTheResult(): void
    {
        $client = self::createClient();
        $this->indexCatalogue();

        $client->request('GET', '/api/products?brand%5B%5D=Makita');

        self::assertResponseIsSuccessful();
        $body = $this->getResponseBody($client);
        self::assertSame(1, $body['total']);
        $hits = $body['hits'];
        self::assertIsArray($hits);
        self::assertIsArray($hits[0]);
        self::assertSame('MAK-1', $hits[0]['sku']);
    }

    public function testProductsTiedOnPriceComeBackInSkuOrder(): void
    {
        $client = self::createClient();
        $this->indexCatalogue();

        $client->request('GET', '/api/products?sort=price_asc');

        $hits = $this->getResponseBody($client)['hits'];
        self::assertIsArray($hits);
        $skus = [];
        foreach ($hits as $hit) {
            self::assertIsArray($hit);
            $skus[] = $hit['sku'];
        }

        self::assertSame(['BOS-1', 'MAK-1'], $skus);
    }

    public function testACategoryThatIsNotASlugPathIsRefused(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/products?category=Not%20A%20Path');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertContains('category', $this->getViolatedFields($client));
    }

    public function testAPriceRangeInTheWrongOrderIsRefused(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/products?priceFromCrowns=500&priceToCrowns=100');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertContains('priceToCrowns', $this->getViolatedFields($client));
    }

    public function testAnUnknownSortValueListsTheAllowedOnes(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/products?sort=cheapest');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $violations = $this->getResponseBody($client)['violations'];
        self::assertIsArray($violations);
        self::assertIsArray($violations[0]);
        self::assertSame('sort', $violations[0]['propertyPath']);
        self::assertIsString($violations[0]['title']);
        self::assertStringContainsString('price_asc', $violations[0]['title']);
    }

    public function testPagingPastTheResultWindowIsAProblemResponse(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/products?size=100&page=200');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame('urn:catalogue-sync:result-window-exceeded', $this->getResponseBody($client)['type']);
    }
}
