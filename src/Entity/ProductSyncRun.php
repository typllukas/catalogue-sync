<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductSyncRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One row per drain batch.
 */
#[ORM\Entity(repositoryClass: ProductSyncRunRepository::class, readOnly: true)]
final readonly class ProductSyncRun
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UlidType::NAME)]
        private Ulid $id,
        #[ORM\Column]
        private DateTimeImmutable $ranAt,
        #[ORM\Column]
        private int $writtenProductCount,
        #[ORM\Column]
        private int $unchangedProductCount,
        #[ORM\Column]
        private int $elapsedMilliseconds,
    ) {
    }

    public function getRanAt(): DateTimeImmutable
    {
        return $this->ranAt;
    }

    public function getWrittenProductCount(): int
    {
        return $this->writtenProductCount;
    }

    public function getUnchangedProductCount(): int
    {
        return $this->unchangedProductCount;
    }

    public function getElapsedMilliseconds(): int
    {
        return $this->elapsedMilliseconds;
    }
}
