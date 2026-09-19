<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeletedProductRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A tombstone: the reindex catches up changes by updated_at, and a deleted row is in no such query.
 * Only DeletedProductRepository::insertTombstones() writes it.
 */
#[ORM\Entity(repositoryClass: DeletedProductRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_deleted_at', fields: ['deletedAt'])]
final class DeletedProduct
{
    /** The deleted product's own id, the index catch-up deletes by it. */
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column]
    private DateTimeImmutable $deletedAt;
}
