<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Stock at the end of the sales history's last day.
 */
#[ORM\Entity(readOnly: true)]
final class StoreStock
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $storeNumber;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $productId;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $quantityOnHand;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $quantityOnOrder;
}
