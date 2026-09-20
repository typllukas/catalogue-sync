<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductAvailability: string
{
    case IN_STOCK = 'in_stock';
    case OUT_OF_STOCK = 'out_of_stock';
}
