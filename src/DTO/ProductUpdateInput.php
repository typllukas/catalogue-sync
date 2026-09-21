<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProductUpdateInput
{
    /**
     * Both columns are a signed INT, and a larger value reaches MariaDB as a 500 rather than a violation.
     */
    private const int COLUMN_CEILING = 2147483647;

    public function __construct(
        #[Assert\Range(min: 0, max: self::COLUMN_CEILING)]
        public ?int $priceInMinorUnits = null,
        #[Assert\Range(min: 0, max: self::COLUMN_CEILING)]
        public ?int $stockQuantity = null,
    ) {
    }
}
