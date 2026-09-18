<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\CreatedAtLifecycleCallbacksTrait;
use App\Entity\Trait\UpdatedAtLifecycleCallbacksTrait;
use App\Repository\BrandRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\HasLifecycleCallbacks]
final class Brand
{
    use CreatedAtLifecycleCallbacksTrait;
    use UpdatedAtLifecycleCallbacksTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $name;

    public function __construct(?Ulid $id = null)
    {
        $this->id = $id ?? new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
