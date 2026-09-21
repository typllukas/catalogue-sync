<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class FeedResult
{
    /**
     * @param int $touchedRowCount lines in the simulated supplier file
     * @param int $pendingProductCount rows the outbox held afterwards, this file's and whatever was already queued
     */
    public function __construct(
        public int $touchedRowCount,
        public int $pendingProductCount,
        public int $discontinuedProductCount,
    ) {
    }
}
