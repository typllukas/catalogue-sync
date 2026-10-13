<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ReplenishmentSuggestion
{
    public function __construct(
        public int $storeNumber,
        public string $productSku,
        public float $averageDailyDemand,
        public float $safetyStock,
        public int $quantityOnHand,
        public int $quantityOnOrder,
        public int $suggestedOrderQuantity,
    ) {
    }
}
