<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class FacetDimension
{
    /**
     * @param array<int, FacetBucket> $buckets
     */
    public function __construct(
        public array $buckets,
    ) {
    }
}
