<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\PendingProductSync;
use App\Entity\ProductSyncOutbox;
use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Helper\MixedToInteger;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

use function array_fill;
use function array_map;
use function count;
use function implode;

/**
 * @extends ServiceEntityRepository<ProductSyncOutbox>
 */
class ProductSyncOutboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductSyncOutbox::class);
    }

    /**
     * @param list<ProductSyncScope> $scopes
     */
    public function enqueue(
        string $indexName,
        Ulid $productId,
        array $scopes,
        ProductSyncOperation $operation,
        DateTimeImmutable $markedAt,
    ): void {
        $this->enqueueMany($indexName, [$productId], $scopes, $operation, $markedAt);
    }

    /**
     * A product touched twice keeps one row with both masks ORed; marked_at moves to the newest
     * touch, which is what the release compares.
     *
     * @param array<int, Ulid> $productIds
     * @param list<ProductSyncScope> $scopes
     */
    public function enqueueMany(
        string $indexName,
        array $productIds,
        array $scopes,
        ProductSyncOperation $operation,
        DateTimeImmutable $markedAt,
    ): void {
        if ($productIds === []) {
            return;
        }

        $scopeMask = 0;
        foreach ($scopes as $scope) {
            $scopeMask |= $scope->value;
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '(?, ?, ?, ?, ?, ?)'));
        $parameters = [];
        $types = [];
        foreach ($productIds as $productId) {
            $parameters[] = new Ulid()->toBinary();
            $types[] = ParameterType::BINARY;
            $parameters[] = $indexName;
            $types[] = ParameterType::STRING;
            $parameters[] = $productId->toBinary();
            $types[] = ParameterType::BINARY;
            $parameters[] = $scopeMask;
            $types[] = ParameterType::INTEGER;
            $parameters[] = $operation->value;
            $types[] = ParameterType::STRING;
            $parameters[] = $markedAt->format(PendingProductSync::MARKED_AT_FORMAT);
            $types[] = ParameterType::STRING;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'INSERT INTO product_sync_outbox (id, index_name, product_id, scope_mask, operation, marked_at)'
                    . ' VALUES ' . $placeholders
                    . ' ON DUPLICATE KEY UPDATE scope_mask = scope_mask | VALUES(scope_mask),'
                    . ' operation = VALUES(operation), marked_at = VALUES(marked_at)',
                $parameters,
                $types,
            );
    }

    /**
     * Least recently marked first; a re-mark moves the row to the back.
     *
     * @return array<int, PendingProductSync>
     */
    public function findPending(string $indexName, int $limit): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(
                'SELECT product_id, scope_mask, operation, marked_at FROM product_sync_outbox'
                    . ' WHERE index_name = ? ORDER BY marked_at ASC LIMIT ?',
                [$indexName, $limit],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

        return array_map(PendingProductSync::fromRow(...), $rows);
    }

    /**
     * The marked_at predicate stops a release from losing an update that arrived during the batch.
     * ProductSyncOutboxDeleteRule fails the build on any other form.
     *
     * @param array<int, PendingProductSync> $pendingProductSyncs
     *
     * @return int how many rows were actually released
     */
    public function release(string $indexName, array $pendingProductSyncs): int
    {
        $connection = $this->getEntityManager()->getConnection();

        // one commit rather than one per row
        return $connection->transactional(
            static function (Connection $connection) use ($indexName, $pendingProductSyncs): int {
                $releasedCount = 0;
                foreach ($pendingProductSyncs as $pendingProductSync) {
                    $releasedCount += MixedToInteger::transformStrict($connection->executeStatement(
                        'DELETE FROM product_sync_outbox WHERE index_name = ? AND product_id = ? AND marked_at = ?',
                        [
                            $indexName,
                            $pendingProductSync->productId->toBinary(),
                            $pendingProductSync->markedAt->format(PendingProductSync::MARKED_AT_FORMAT),
                        ],
                        [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
                    ));
                }

                return $releasedCount;
            },
        );
    }

    public function countPending(string $indexName): int
    {
        return $this->count(['indexName' => $indexName]);
    }
}
