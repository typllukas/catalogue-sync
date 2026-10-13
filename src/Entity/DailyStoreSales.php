<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DailyStoreSalesRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A row only for a day with a sale. No foreign key to product, which would need its own index over
 * every row; a deleted product's rows stay behind, and the suggestion joins product.
 */
#[ORM\Entity(repositoryClass: DailyStoreSalesRepository::class, readOnly: true)]
final class DailyStoreSales
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $storeNumber;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $productId;

    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $salesDate;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $quantity;
}
