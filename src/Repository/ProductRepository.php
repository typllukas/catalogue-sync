<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function count;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return iterable<list<Ulid>>
     */
    public function iterateIdBatches(int $batchSize): iterable
    {
        yield from $this->batchIds(
            $this->createQueryBuilder('product')->select('product.id')->getQuery()->toIterable(),
            $batchSize,
        );
    }

    /**
     * @return iterable<list<Ulid>>
     */
    public function iterateIdBatchesForChangesSince(DateTimeImmutable $since, int $batchSize): iterable
    {
        yield from $this->batchIds(
            $this->createQueryBuilder('product')
                ->select('product.id')
                ->join('product.brand', 'brand')
                ->join('product.category', 'category')
                ->where('product.updatedAt >= :since')
                ->orWhere('brand.updatedAt >= :since')
                ->orWhere('category.updatedAt >= :since')
                ->setParameter('since', $since)
                ->getQuery()
                ->toIterable(),
            $batchSize,
        );
    }

    /**
     * A ULID list bound without toBinary() and ArrayParameterType::BINARY matches nothing, no
     * error, zero rows; the column is BINARY(16).
     *
     * @param array<int, Ulid> $ids
     *
     * @return array<int, Product>
     */
    public function findForIndexing(array $ids): array
    {
        $binaryIds = array_map(static fn (Ulid $id): string => $id->toBinary(), $ids);

        return $this->createQueryBuilder('product')
            ->addSelect('brand', 'category')
            ->join('product.brand', 'brand')
            ->join('product.category', 'category')
            ->where('product.id IN (:ids)')
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    /**
     * Not by id: a ULID orders by creation, and a catalogue seeded once never rotates that set, so a
     * sample taken from it can miss every product a feed touched.
     *
     * @return list<Ulid>
     */
    public function findRecentlyChangedIds(int $limit): array
    {
        $rows = $this->createQueryBuilder('product')
            ->select('product.id')
            ->orderBy('product.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Ulid => $row['id'], $rows);
    }

    /**
     * Ids only: each batch is then hydrated by findForIndexing() and the manager cleared, so memory
     * stays flat over a million rows.
     *
     * @param iterable<array{id: Ulid}> $rows
     *
     * @return iterable<list<Ulid>>
     */
    private function batchIds(iterable $rows, int $batchSize): iterable
    {
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row['id'];

            if (count($batch) !== $batchSize) {
                continue;
            }

            yield $batch;

            $batch = [];
        }

        if ($batch === []) {
            return;
        }

        yield $batch;
    }
}
