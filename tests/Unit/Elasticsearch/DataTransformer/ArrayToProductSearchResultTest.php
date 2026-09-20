<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch\DataTransformer;

use App\Elasticsearch\DataTransformer\ArrayToProductSearchResult;
use DateTimeInterface;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @see ArrayToProductSearchResult
 */
final class ArrayToProductSearchResultTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function buildResponse(): array
    {
        return [
            'took' => 7,
            'hits' => [
                'total' => ['value' => 2, 'relation' => 'eq'],
                'hits' => [
                    [
                        '_id' => '01M2TDETR9FEV3HDJQVQWN59Z9',
                        '_source' => [
                            'sku' => 'MAK-1',
                            'name' => 'Produkt MAK-1',
                            'description' => 'Popis produktu.',
                            'brand' => 'Makita',
                            'category' => ['path' => ['naradi', 'naradi/elektro', 'naradi/elektro/vrtacky']],
                            'price' => 199.9,
                            'stock_quantity' => 5,
                            'ean' => '8590000000001',
                            'visible' => true,
                            'product_updated_at' => '2026-09-18T10:00:00.000000+00:00',
                        ],
                        'highlight' => ['name' => ['Produkt <mark>MAK-1</mark>']],
                    ],
                ],
            ],
            'aggregations' => [
                'brand' => [
                    'brand' => [
                        'sum_other_doc_count' => 3,
                        'buckets' => [['key' => 'Makita', 'doc_count' => 2]],
                    ],
                ],
                'availability' => [
                    'availability' => [
                        'buckets' => [
                            ['key' => 'in_stock', 'doc_count' => 2],
                            ['key' => 'out_of_stock', 'doc_count' => 0],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseWithoutHighlight(): array
    {
        $response = $this->buildResponse();
        $hits = $response['hits'];
        self::assertIsArray($hits);
        $hitList = $hits['hits'];
        self::assertIsArray($hitList);
        $firstHit = $hitList[0];
        self::assertIsArray($firstHit);

        unset($firstHit['highlight']);
        $hitList[0] = $firstHit;
        $hits['hits'] = $hitList;
        $response['hits'] = $hits;

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseWithAnApproximateTotal(): array
    {
        $response = $this->buildResponse();
        $hits = $response['hits'];
        self::assertIsArray($hits);
        $hits['total'] = ['value' => 2, 'relation' => 'gte'];
        $response['hits'] = $hits;

        return $response;
    }

    public function testTheHitTakesItsIdFromTheDocumentIdAndItsCategoryFromTheDeepestPath(): void
    {
        $result = ArrayToProductSearchResult::transform($this->buildResponse());

        self::assertSame(2, $result->total);
        self::assertSame(7, $result->elapsedMilliseconds);
        self::assertSame('01M2TDETR9FEV3HDJQVQWN59Z9', $result->hits[0]->id);
        self::assertSame('naradi/elektro/vrtacky', $result->hits[0]->categoryPath);
        self::assertSame(199.9, $result->hits[0]->price);
    }

    public function testTheHitCarriesWhenItsProductLastChanged(): void
    {
        $result = ArrayToProductSearchResult::transform($this->buildResponse());

        self::assertSame('2026-09-18T10:00:00+00:00', $result->hits[0]->updatedAt->format(DateTimeInterface::ATOM));
    }

    public function testAMatchedFragmentReachesTheHit(): void
    {
        $result = ArrayToProductSearchResult::transform($this->buildResponse());

        self::assertSame(['name' => ['Produkt <mark>MAK-1</mark>']], $result->hits[0]->highlight);
    }

    /**
     * Elasticsearch leaves the key out rather than sending null, so a hit that matched outside the
     * highlighted fields carries no 'highlight' key at all.
     */
    public function testAHitThatMatchedOutsideTheHighlightedFieldsCarriesNoFragments(): void
    {
        $result = ArrayToProductSearchResult::transform($this->buildResponseWithoutHighlight());

        self::assertSame([], $result->hits[0]->highlight);
    }

    public function testAFacetReadsItsBuckets(): void
    {
        $result = ArrayToProductSearchResult::transform($this->buildResponse());

        self::assertSame('Makita', $result->facets['brand']->buckets[0]->value);
        self::assertSame(2, $result->facets['brand']->buckets[0]->count);
    }

    /**
     * track_total_hits is on, so anything but an exact count means the query changed.
     */
    public function testAnApproximateTotalIsRefused(): void
    {
        $this->expectException(LogicException::class);

        ArrayToProductSearchResult::transform($this->buildResponseWithAnApproximateTotal());
    }
}
