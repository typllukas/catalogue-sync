<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The entity needs #[ORM\HasLifecycleCallbacks], or nothing sets this and the insert fails on a
 * NOT NULL column.
 */
trait CreatedAtLifecycleCallbacksTrait
{
    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function stampCreatedAt(): void
    {
        $this->createdAt = new DateTimeImmutable();
    }
}
