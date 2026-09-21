<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class SyncStatus
{
    /**
     * @param int $pendingProductCount products waiting in the outbox
     * @param int $driftedProductCount sampled from the most recently changed products, where drift shows first
     */
    public function __construct(
        public int $pendingProductCount,
        public ?string $lastDrainAt,
        public int $lastDrainWrittenProductCount,
        public int $lastDrainUnchangedProductCount,
        public int $lastDrainElapsedMilliseconds,
        public int $driftedProductCount,
    ) {
    }
}
