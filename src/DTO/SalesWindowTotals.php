<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class SalesWindowTotals
{
    public function __construct(
        public int $storeNumber,
        public string $productSku,
        public int $quantitySum,
        public int $quantitySquareSum,
        public int $quantityOnHand,
        public int $quantityOnOrder,
    ) {
    }
}
