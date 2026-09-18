<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The entity needs #[ORM\HasLifecycleCallbacks], or nothing sets this and the insert fails on a
 * NOT NULL column.
 */
trait UpdatedAtLifecycleCallbacksTrait
{
    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function stampUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
