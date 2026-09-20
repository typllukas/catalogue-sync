<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class FacetBucket
{
    public function __construct(
        public string $value,
        public int $count,
    ) {
    }
}
