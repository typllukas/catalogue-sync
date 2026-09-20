<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\DTO\ProductSearchInput;
use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\ProductFacetQueryFactory;
use App\Elasticsearch\ProductSearchQueryFactory;
use PHPUnit\Framework\TestCase;

use function array_key_first;
use function array_keys;

/**
 * @see ProductSearchQueryFactory
 */
final class ProductSearchQueryFactoryTest extends TestCase
{
    private ProductSearchQueryFactory $productSearchQueryFactory;

    protected function setUp(): void
    {
        $this->productSearchQueryFactory = new ProductSearchQueryFactory(new ProductFacetQueryFactory());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function getValueAt(array $body, string|int ...$path): mixed
    {
        $value = $body;
        foreach ($path as $step) {
            self::assertIsArray($value);
            self::assertArrayHasKey($step, $value);
            $value = $value[$step];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function getArrayAt(array $body, string|int ...$path): array
    {
        $value = $this->getValueAt($body, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return list<array-key|null>
     */
    private function findTextClauseTypes(string $text): array
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput(text: $text));

        $clauseTypes = [];
        foreach ($this->getArrayAt($body, 'query', 'bool', 'should') as $clause) {
            self::assertIsArray($clause);
            $clauseTypes[] = array_key_first($clause);
        }

        return $clauseTypes;
    }

    public function testAnEmptySearchMatchesEveryVisibleProduct(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput());

        self::assertSame([], $this->getValueAt($body, 'query', 'bool', 'should'));
        self::assertSame(0, $this->getValueAt($body, 'query', 'bool', 'minimum_should_match'));
        self::assertArrayNotHasKey('post_filter', $body);
    }

    public function testAHiddenProductIsNeverSearchable(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput());

        self::assertContains(
            ['term' => ['visible' => true]],
            $this->getArrayAt($body, 'query', 'bool', 'filter'),
        );
    }

    /**
     * Every ancestor is indexed, so term is exact. A prefix would also match a sibling slug that
     * starts with the selected one.
     */
    public function testASelectedCategoryFiltersTheWholeSubtree(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput(category: 'naradi/elektro'));

        self::assertSame(
            [['term' => ['category.path' => 'naradi/elektro']]],
            $this->getArrayAt($body, 'post_filter', 'bool', 'filter'),
        );
    }

    public function testTheCategoryFacetCountsOnlyTheChildrenOneLevelDown(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput(category: 'naradi'));

        self::assertSame(
            'naradi/[^/]+',
            $this->getValueAt($body, 'aggs', 'category', 'aggs', 'category', 'terms', 'include'),
        );
    }

    public function testTheTopLevelCategoryFacetCountsOnlyTheRoots(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput());

        self::assertSame(
            '[^/]+',
            $this->getValueAt($body, 'aggs', 'category', 'aggs', 'category', 'terms', 'include'),
        );
    }

    public function testADimensionIsCountedWithoutItsOwnSelection(): void
    {
        $body = $this->productSearchQueryFactory->build(
            new ProductSearchInput(brand: ['Makita'], category: 'naradi'),
        );

        self::assertSame(
            [['term' => ['category.path' => 'naradi']]],
            $this->getArrayAt($body, 'aggs', 'brand', 'filter', 'bool', 'filter'),
        );
        self::assertSame(
            [['terms' => ['brand' => ['Makita']]]],
            $this->getArrayAt($body, 'aggs', 'category', 'filter', 'bool', 'filter'),
        );
    }

    public function testAvailabilityIsCountedForBothStates(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput());

        self::assertSame(
            ['in_stock', 'out_of_stock'],
            array_keys($this->getArrayAt($body, 'aggs', 'availability', 'aggs', 'availability', 'filters', 'filters')),
        );
    }

    public function testAnEanIsMatchedExactlyAndOnlyAgainstItsOwnField(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput(text: '8590123456789'));

        $expectedBoost = ProductSearchQueryFactory::EXACT_CODE_BOOST;
        $shouldClauses = $this->getArrayAt($body, 'query', 'bool', 'should');
        self::assertContains(
            ['term' => ['ean' => ['value' => '8590123456789', 'boost' => $expectedBoost, 'case_insensitive' => true]]],
            $shouldClauses,
        );
        self::assertNotContains(
            ['term' => ['sku' => ['value' => '8590123456789', 'boost' => $expectedBoost, 'case_insensitive' => true]]],
            $shouldClauses,
        );
    }

    public function testACodeIsLiftedOutOfTheWordsTypedBesideIt(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput(text: 'EAN8590123456789'));

        self::assertContains(
            [
                'term' => [
                    'ean' => [
                        'value' => '8590123456789',
                        'boost' => ProductSearchQueryFactory::EXACT_CODE_BOOST,
                        'case_insensitive' => true,
                    ],
                ],
            ],
            $this->getArrayAt($body, 'query', 'bool', 'should'),
        );
    }

    public function testAPriceRangeNarrowsTheFacetCountsToo(): void
    {
        $body = $this->productSearchQueryFactory->build(
            new ProductSearchInput(priceFromCrowns: 100, priceToCrowns: 500),
        );

        self::assertContains(
            ['range' => ['price' => ['gte' => 100, 'lte' => 500]]],
            $this->getArrayAt($body, 'query', 'bool', 'filter'),
        );
    }

    public function testTheThirdPageAsksForTheOffsetAfterTheFirstTwo(): void
    {
        $body = $this->productSearchQueryFactory->build(new ProductSearchInput(size: 24, page: 3));

        self::assertSame(48, $body['from']);
        self::assertSame(24, $body['size']);
    }

    public function testALastWordOfOneLetterIsNotMatchedAsAPrefix(): void
    {
        self::assertContains('match_bool_prefix', $this->findTextClauseTypes('makita sr'));
        self::assertNotContains('match_bool_prefix', $this->findTextClauseTypes('makita s'));
    }

    public function testPagingPastTheResultWindowIsRefused(): void
    {
        $this->expectException(ResultWindowExceededException::class);

        $this->productSearchQueryFactory->build(new ProductSearchInput(size: 100, page: 200));
    }
}
