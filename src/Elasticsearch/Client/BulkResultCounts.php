<?php

declare(strict_types=1);

namespace App\Elasticsearch\Client;

final readonly class BulkResultCounts
{
    /**
     * @param array<int, string> $conflictedIds documents a newer write won; their rows stay marked
     * @param array<string, string> $failures error description per document id the cluster refused
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $noop,
        public int $deleted,
        public array $conflictedIds,
        public array $failures,
    ) {
    }
}
