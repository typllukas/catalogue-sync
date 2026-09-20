<?php

declare(strict_types=1);

namespace App\Elasticsearch\DataTransformer;

use App\DTO\FacetBucket;
use App\DTO\FacetDimension;
use App\DTO\ProductHit;
use App\DTO\ProductSearchResult;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DocumentFactory\ProductDocumentFactory;
use App\Helper\MixedToString;
use DateTimeImmutable;
use LogicException;

use function array_last;
use function array_map;
use function array_values;
use function sprintf;

final class ArrayToProductSearchResult
{
    /**
     * @param array<array-key, mixed> $response
     */
    public static function transform(array $response): ProductSearchResult
    {
        $hitsEnvelope = ResponseBody::readArray($response, 'hits');

        return new ProductSearchResult(
            self::readTotal(ResponseBody::readArray($hitsEnvelope, 'total')),
            ResponseBody::readIntegerStrict($response, 'took'),
            array_map(self::buildHit(...), array_values(ResponseBody::readArray($hitsEnvelope, 'hits'))),
            self::buildFacets(ResponseBody::readArray($response, 'aggregations')),
        );
    }

    /**
     * @param array<array-key, mixed> $total
     */
    private static function readTotal(array $total): int
    {
        $relation = ResponseBody::readStringStrict($total, 'relation');
        if ($relation !== 'eq') {
            throw new LogicException(sprintf('Total relation %s, the query asks for an exact count.', $relation));
        }

        return ResponseBody::readIntegerStrict($total, 'value');
    }

    private static function buildHit(mixed $hit): ProductHit
    {
        $hit = ResponseBody::narrowToArray($hit);

        $documentFields = ResponseBody::readArray($hit, '_source');
        $categoryPaths = ResponseBody::readArray(ResponseBody::readArray($documentFields, 'category'), 'path');

        return new ProductHit(
            ResponseBody::readStringStrict($hit, '_id'),
            ResponseBody::readStringStrict($documentFields, 'sku'),
            ResponseBody::readStringStrict($documentFields, 'name'),
            ResponseBody::readStringStrict($documentFields, 'brand'),
            MixedToString::transformStrict(array_last($categoryPaths)),
            ResponseBody::readFloatStrict($documentFields, 'price'),
            ResponseBody::readIntegerStrict($documentFields, 'stock_quantity'),
            ResponseBody::readStringStrict($documentFields, 'ean'),
            new DateTimeImmutable(
                ResponseBody::readStringStrict($documentFields, ProductDocumentFactory::PRODUCT_UPDATED_AT_FIELD),
            ),
            self::buildHighlight($hit),
        );
    }

    /**
     * @param array<array-key, mixed> $hit
     *
     * @return array<string, array<int, string>>
     */
    private static function buildHighlight(array $hit): array
    {
        $highlight = [];
        foreach (ResponseBody::readArray($hit, 'highlight') as $fieldName => $fragments) {
            $highlight[MixedToString::transformStrict($fieldName)] = array_map(
                MixedToString::transformStrict(...),
                array_values(ResponseBody::narrowToArray($fragments)),
            );
        }

        return $highlight;
    }

    /**
     * Each facet is wrapped in a filter aggregation of the same name, so the buckets sit one level deeper.
     *
     * @param array<array-key, mixed> $aggregations
     *
     * @return array<string, FacetDimension>
     */
    private static function buildFacets(array $aggregations): array
    {
        $facets = [];
        foreach ($aggregations as $dimension => $aggregation) {
            $dimensionName = MixedToString::transformStrict($dimension);
            $countingAggregation = ResponseBody::readArray(
                ResponseBody::narrowToArray($aggregation),
                $dimensionName,
            );

            $facets[$dimensionName] = self::buildFacet($countingAggregation);
        }

        return $facets;
    }

    /**
     * @param array<array-key, mixed> $countingAggregation
     */
    private static function buildFacet(array $countingAggregation): FacetDimension
    {
        $buckets = [];
        foreach (ResponseBody::readArray($countingAggregation, 'buckets') as $bucket) {
            $bucketFields = ResponseBody::narrowToArray($bucket);
            $buckets[] = new FacetBucket(
                ResponseBody::readStringStrict($bucketFields, 'key'),
                ResponseBody::readIntegerStrict($bucketFields, 'doc_count'),
            );
        }

        return new FacetDimension($buckets);
    }
}
