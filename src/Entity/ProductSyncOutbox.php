<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProductSyncOperation;
use App\Repository\ProductSyncOutboxRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One row per index and product, so five hundred touches in one import are one row. Only SQL in
 * ProductSyncOutboxRepository writes it (an upsert); the mapping is here so migrations own the table.
 */
#[ORM\UniqueConstraint(name: 'uniq_product_sync_outbox', columns: ['index_name', 'product_id'])]
#[ORM\Index(name: 'idx_product_sync_outbox_marked_at', fields: ['markedAt'])]
#[ORM\Entity(repositoryClass: ProductSyncOutboxRepository::class, readOnly: true)]
final class ProductSyncOutbox
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 64)]
    private string $indexName;

    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $productId;

    #[ORM\Column]
    private int $scopeMask;

    #[ORM\Column(length: 16, enumType: ProductSyncOperation::class)]
    private ProductSyncOperation $operation;

    /** With DATETIME two marks inside one second are the same, and the release would drop a re-mark. */
    #[ORM\Column(columnDefinition: 'DATETIME(3) NOT NULL')]
    private DateTimeImmutable $markedAt;
}
