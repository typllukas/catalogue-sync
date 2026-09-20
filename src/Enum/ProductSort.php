<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductSort: string
{
    case RELEVANCE = 'relevance';
    case PRICE_ASC = 'price_asc';
    case PRICE_DESC = 'price_desc';
}
