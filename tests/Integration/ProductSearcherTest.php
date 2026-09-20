<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\FacetBucket;
use App\DTO\ProductSearchInput;
use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\ProductSearcher;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Enum\ProductAvailability;
use App\Tests\ElasticsearchIndexTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;

final class ProductSearcherTest extends KernelTestCase
{
    use ElasticsearchIndexTrait;

    private ProductSearcher $productSearcher;

    private string $indexName;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->productSearcher = $container->get(ProductSearcher::class);
        $this->indexName = self::createProductIndex();

        $makita = new Brand()->setName('Makita');
        $bosch = new Brand()->setName('Bosch');
        $narex = new Brand()->setName('Narex');
        $drills = new Category()->setName('Vrtačky')->setSlugPath('naradi/elektro/vrtacky');
        $grinders = new Category()->setName('Brusky')->setSlugPath('naradi/elektro/brusky');
        $hammers = new Category()->setName('Kladiva')->setSlugPath('naradi/rucni/kladiva');
        $hoses = new Category()->setName('Hadice')->setSlugPath('zahrada/zavlaha/hadice');

        $productDocumentFactory = $container->get(ProductDocumentFactory::class);
        $operations = [];
        foreach (
            [
                ['MAK-DHP484Z', 'Makita DHP484Z příklepový šroubovák', $makita, $drills, 12, true, '8590000000001'],
                ['BOS-GWS18V10', 'Bosch GWS 18V-10 úhlová bruska', $bosch, $grinders, 0, true, '8590000000002'],
                ['NAR-0000003', 'Narex kladivo <300 g>', $narex, $hammers, 7, true, '8590000000003'],
                ['MAK-0000004', 'Makita zahradní hadice', $makita, $hoses, 4, true, '8590000000004'],
                ['MAK-0000005', 'Makita skrytý produkt', $makita, $drills, 9, false, '8590000000005'],
            ] as [$sku, $name, $brand, $category, $stockQuantity, $visible, $ean]
        ) {
            $product = new Product()
                ->setSku($sku)
                ->setName($name)
                ->setDescription('Popis produktu.')
                ->setEan($ean)
                ->setPriceInMinorUnits(19990)
                ->setStockQuantity($stockQuantity)
                ->setVisible($visible)
                ->setBrand($brand)
                ->setCategory($category);
            $product->stampUpdatedAt();

            $operations[] = ['index' => ['_index' => $this->indexName, '_id' => $product->getId()->toBase32()]];
            $operations[] = $productDocumentFactory->build($product);
        }

        self::indexDocuments($operations);
    }

    protected function tearDown(): void
    {
        self::dropIndex($this->indexName);

        parent::tearDown();
    }

    /**
     * @param array<int, FacetBucket> $buckets
     *
     * @return array<int, array{string, int}>
     */
    private function readBuckets(array $buckets): array
    {
        return array_map(
            static fn (FacetBucket $bucket): array => [$bucket->value, $bucket->count],
            $buckets,
        );
    }

    public function testTheCategoryFacetCountsTheRootsWhenNothingIsSelected(): void
    {
        $facet = $this->productSearcher->search(new ProductSearchInput())->facets['category'];

        self::assertSame([['naradi', 3], ['zahrada', 1]], $this->readBuckets($facet->buckets));
    }

    public function testTheCategoryFacetCountsTheChildrenOfTheSelectedCategory(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(category: 'naradi'));

        self::assertSame(3, $result->total);
        self::assertSame(
            [['naradi/elektro', 2], ['naradi/rucni', 1]],
            $this->readBuckets($result->facets['category']->buckets),
        );
    }

    public function testASelectedCategoryReturnsTheWholeSubtree(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(category: 'naradi/elektro'));

        self::assertSame(2, $result->total);
    }

    public function testAHiddenProductIsNotFound(): void
    {
        self::assertSame(4, $this->productSearcher->search(new ProductSearchInput())->total);
    }

    public function testTheBrandFacetSurvivesItsOwnSelection(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(brand: ['Makita']));

        self::assertSame(2, $result->total);
        self::assertSame(
            [['Makita', 2], ['Bosch', 1], ['Narex', 1]],
            $this->readBuckets($result->facets['brand']->buckets),
        );
    }

    public function testAvailabilityCountsBothStates(): void
    {
        $facet = $this->productSearcher->search(new ProductSearchInput())->facets['availability'];

        self::assertSame(
            [[ProductAvailability::IN_STOCK->value, 3], [ProductAvailability::OUT_OF_STOCK->value, 1]],
            $this->readBuckets($facet->buckets),
        );
    }

    public function testAFoldedNameWithSwappedLettersFinds(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'prikelpovy'))->total);
    }

    public function testASearchBeforeTheFirstReindexIsUnavailable(): void
    {
        self::dropIndex($this->indexName);

        $this->expectException(SearchUnavailableException::class);

        $this->productSearcher->search(new ProductSearchInput(text: 'makita'));
    }

    public function testAnEanFindsItsProduct(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: '8590000000002'))->total);
    }

    public function testAnEanBesideAnotherWordFindsItsProduct(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'EAN 8590000000002'))->total);
    }

    public function testASkuTypedInLowerCaseBesideAnotherWordFindsItsProduct(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'hadice mak-0000004'))->total);
    }

    public function testMarkupInANameComesBackEscapedAroundTheMark(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(text: 'kladivo'));

        self::assertSame(['name' => ['Narex <mark>kladivo</mark> &lt;300 g&gt;']], $result->hits[0]->highlight);
    }

    public function testADigitTooManyIsNotAnEan(): void
    {
        self::assertSame(0, $this->productSearcher->search(new ProductSearchInput(text: '85900000000025'))->total);
    }

    public function testAnEanGluedToAnotherWordFindsItsProduct(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'EAN8590000000002'))->total);
    }

    /**
     * A code is matched whole, so a space the paste brought with it would otherwise find nothing.
     */
    public function testASurroundingSpaceDoesNotChangeTheResult(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: ' 8590000000002 '))->total);
    }

    public function testATextOfOnlySpacesSearchesTheWholeCatalogue(): void
    {
        self::assertSame(4, $this->productSearcher->search(new ProductSearchInput(text: '   '))->total);
    }

    public function testAFragmentOfANameFinds(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'sroub'))->total);
    }

    public function testAPartialSkuMatchesTheSkuField(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(text: 'MAK-0000'));

        self::assertSame(1, $result->total);
        self::assertSame('MAK-0000004', $result->hits[0]->sku);
    }

    /**
     * A keyword has one token, so the mark covers the whole code however little of it was typed.
     */
    public function testAPartialSkuIsHighlightedWhole(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(text: 'MAK-0000'));

        self::assertSame(['sku' => ['<mark>MAK-0000004</mark>']], $result->hits[0]->highlight);
    }

    /**
     * A model code is not a SKU, so only the name can answer it.
     */
    public function testAModelCodeInsideANameFinds(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(text: 'GWS-18'));

        self::assertSame(1, $result->total);
        self::assertSame('BOS-GWS18V10', $result->hits[0]->sku);
    }

    public function testTwoWordsMustBothMatch(): void
    {
        self::assertSame(0, $this->productSearcher->search(new ProductSearchInput(text: 'makita kladivo'))->total);
    }

    public function testASingleLetterDoesNotMatchByPrefix(): void
    {
        self::assertSame(0, $this->productSearcher->search(new ProductSearchInput(text: 's'))->total);
    }

    public function testTwoLettersMatchByPrefix(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'sr'))->total);
    }

    public function testAFragmentOfANameIsHighlighted(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(text: 'sroub'));

        self::assertSame(
            ['name' => ['Makita DHP484Z příklepový <mark>šroubovák</mark>']],
            $result->hits[0]->highlight,
        );
    }

    public function testAWholeWordBesideAFragmentMarksBoth(): void
    {
        $result = $this->productSearcher->search(new ProductSearchInput(text: 'makita sroub'));

        self::assertSame(
            ['name' => ['<mark>Makita</mark> DHP484Z příklepový <mark>šroubovák</mark>']],
            $result->hits[0]->highlight,
        );
    }

    /**
     * 'kladiva' is not a prefix of 'kladivo', so only the stemmed field can answer this one.
     */
    public function testAnInflectedWholeWordFinds(): void
    {
        self::assertSame(1, $this->productSearcher->search(new ProductSearchInput(text: 'kladiva'))->total);
    }
}
