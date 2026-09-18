<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\CreatedAtLifecycleCallbacksTrait;
use App\Entity\Trait\UpdatedAtLifecycleCallbacksTrait;
use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_updated_at', fields: ['updatedAt'])]
#[ORM\HasLifecycleCallbacks]
final class Product
{
    use CreatedAtLifecycleCallbacksTrait;
    use UpdatedAtLifecycleCallbacksTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 2000)]
    private string $description;

    #[ORM\Column(length: 13, unique: true)]
    private string $ean;

    #[ORM\Column]
    private int $priceInMinorUnits;

    #[ORM\Column]
    private int $stockQuantity;

    #[ORM\Column]
    private bool $visible;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Brand $brand;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    public function __construct(?Ulid $id = null)
    {
        $this->id = $id ?? new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;

        return $this;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getEan(): string
    {
        return $this->ean;
    }

    public function setEan(string $ean): static
    {
        $this->ean = $ean;

        return $this;
    }

    public function getPriceInMinorUnits(): int
    {
        return $this->priceInMinorUnits;
    }

    public function setPriceInMinorUnits(int $priceInMinorUnits): static
    {
        $this->priceInMinorUnits = $priceInMinorUnits;

        return $this;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function setStockQuantity(int $stockQuantity): static
    {
        $this->stockQuantity = $stockQuantity;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function getBrand(): Brand
    {
        return $this->brand;
    }

    public function setBrand(Brand $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }
}
