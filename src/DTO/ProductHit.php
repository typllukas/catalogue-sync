<?php

declare(strict_types=1);

namespace App\DTO;

use DateTimeImmutable;

final readonly class ProductHit
{
    /**
     * @param array<string, array<int, string>> $highlight matched fragments, keyed by field
     */
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        public string $brand,
        public string $categoryPath,
        public float $price,
        public int $stockQuantity,
        public string $ean,
        public DateTimeImmutable $updatedAt,
        public array $highlight,
    ) {
    }
}
