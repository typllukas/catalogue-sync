<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ProductAvailability;
use App\Enum\ProductSort;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProductSearchInput
{
    /**
     * @param array<int, string>|null $brand brand names
     */
    public function __construct(
        #[Assert\Length(max: 200)]
        public ?string $text = null,
        #[Assert\All([new Assert\Type('string')])]
        public ?array $brand = null,
        #[Assert\Regex('#\A[a-z0-9-]+(/[a-z0-9-]+)*\z#')]
        public ?string $category = null,
        public ?ProductAvailability $availability = null,
        #[Assert\PositiveOrZero]
        public ?int $priceFromCrowns = null,
        #[Assert\PositiveOrZero]
        #[Assert\GreaterThanOrEqual(propertyPath: 'priceFromCrowns')]
        public ?int $priceToCrowns = null,
        public ProductSort $sort = ProductSort::RELEVANCE,
        #[Assert\Range(min: 1, max: 100)]
        public int $size = 24,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}
