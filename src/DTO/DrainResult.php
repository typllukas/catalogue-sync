<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class DrainResult
{
    /**
     * @param int $unchangedProductCount products Elasticsearch found unchanged and did not write
     * @param int $stillPendingProductCount rows left in the outbox: conflicts plus rows re-marked during the batch
     */
    public function __construct(
        public int $writtenProductCount,
        public int $unchangedProductCount,
        public int $deletedProductCount,
        public int $conflictCount,
        public int $elapsedMilliseconds,
        public int $stillPendingProductCount,
    ) {
    }
}
