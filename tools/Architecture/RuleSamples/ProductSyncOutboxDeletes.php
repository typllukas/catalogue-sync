<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use Doctrine\DBAL\Connection;

final readonly class ProductSyncOutboxDeletes
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function releaseWithoutTheMarkedAtPredicate(string $indexName): void
    {
        $this->connection->executeStatement(
            'DELETE FROM product_sync_outbox WHERE index_name = ? AND product_id = ?',
            [$indexName, 'a product id'],
        );
    }
}
