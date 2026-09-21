<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductSyncRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductSyncRun>
 */
class ProductSyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductSyncRun::class);
    }

    /**
     * A ULID already sorts by creation time.
     */
    public function findLatest(): ?ProductSyncRun
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
