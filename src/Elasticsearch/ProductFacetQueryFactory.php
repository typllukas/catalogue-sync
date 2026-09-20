<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ProductSearchInput;
use App\Enum\ProductAvailability;

use function array_values;

final readonly class ProductFacetQueryFactory
{
    private const int BRAND_FACET_SIZE = 20;

    private const int CATEGORY_FACET_SIZE = 20;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildSelectionClauses(ProductSearchInput $input): array
    {
        $clauses = [];

        if ($input->brand !== null && $input->brand !== []) {
            $clauses['brand'] = ['terms' => ['brand' => array_values($input->brand)]];
        }

        if ($input->category !== null) {
            $clauses['category'] = ['term' => ['category.path' => $input->category]];
        }

        if ($input->availability instanceof ProductAvailability) {
            $clauses['availability'] = $this->buildAvailabilityClause($input->availability);
        }

        return $clauses;
    }

    /**
     * Each dimension is counted with the other dimensions' clauses only, so selecting one brand
     * leaves the other brands in the list with their counts.
     *
     * @return array<string, mixed>
     */
    public function buildAggregations(ProductSearchInput $input): array
    {
        $selectionClauses = $this->buildSelectionClauses($input);

        return [
            'brand' => $this->buildFacetAggregation(
                'brand',
                $selectionClauses,
                ['terms' => ['field' => 'brand', 'size' => self::BRAND_FACET_SIZE]],
            ),
            // one level below what is selected: naradi gives naradi/elektro, never the grandchildren
            'category' => $this->buildFacetAggregation('category', $selectionClauses, [
                'terms' => [
                    'field' => 'category.path',
                    'size' => self::CATEGORY_FACET_SIZE,
                    'include' => $input->category === null ? '[^/]+' : $input->category . '/[^/]+',
                ],
            ]),
            'availability' => $this->buildFacetAggregation(
                'availability',
                $selectionClauses,
                ['filters' => ['keyed' => false, 'filters' => $this->buildAvailabilityFilters()]],
            ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $selectionClauses
     * @param array<string, mixed> $bucketAggregation
     *
     * @return array<string, mixed>
     */
    private function buildFacetAggregation(string $dimension, array $selectionClauses, array $bucketAggregation): array
    {
        unset($selectionClauses[$dimension]);

        return [
            'filter' => ['bool' => ['filter' => array_values($selectionClauses)]],
            'aggs' => [$dimension => $bucketAggregation],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildAvailabilityFilters(): array
    {
        $filters = [];
        foreach (ProductAvailability::cases() as $availability) {
            $filters[$availability->value] = $this->buildAvailabilityClause($availability);
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAvailabilityClause(ProductAvailability $availability): array
    {
        return match ($availability) {
            ProductAvailability::IN_STOCK => ['range' => ['stock_quantity' => ['gt' => 0]]],
            ProductAvailability::OUT_OF_STOCK => ['term' => ['stock_quantity' => 0]],
        };
    }
}
