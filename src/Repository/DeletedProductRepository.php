<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletedProduct;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

use function array_fill;
use function count;
use function implode;

/**
 * @extends ServiceEntityRepository<DeletedProduct>
 */
class DeletedProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletedProduct::class);
    }

    /**
     * @return iterable<Ulid>
     */
    public function iterateIdsOfProductsDeletedSince(DateTimeImmutable $since): iterable
    {
        $rows = $this->createQueryBuilder('deletedProduct')
            ->select('deletedProduct.id')
            ->where('deletedProduct.deletedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->toIterable();

        foreach ($rows as $row) {
            yield $row['id'];
        }
    }

    /**
     * A retried run writes the same ids again, and the catch-up only sees tombstones newer than the
     * reindex start. DQL has no INSERT, so the upsert is SQL.
     *
     * @param array<int, Ulid> $productIds
     */
    public function insertTombstones(array $productIds, DateTimeImmutable $deletedAt): void
    {
        if ($productIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '(?, ?)'));
        $parameters = [];
        $types = [];
        foreach ($productIds as $productId) {
            $parameters[] = $productId->toBinary();
            $types[] = ParameterType::BINARY;
            $parameters[] = $deletedAt->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'INSERT INTO deleted_product (id, deleted_at) VALUES ' . $placeholders
                    . ' ON DUPLICATE KEY UPDATE deleted_at = VALUES(deleted_at)',
                $parameters,
                $types,
            );
    }
}
