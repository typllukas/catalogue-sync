<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ProductSearchResult
{
    /**
     * @param array<int, ProductHit> $hits
     * @param array<string, FacetDimension> $facets values with counts, keyed by dimension
     */
    public function __construct(
        public int $total,
        public int $elapsedMilliseconds,
        public array $hits,
        public array $facets,
    ) {
    }
}
