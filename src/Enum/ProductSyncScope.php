<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Values are bits, since the outbox ORs every touch of a product into one scope_mask.
 * EVERYTHING absorbs the narrower scopes: the drain then sends the whole document instead of fields.
 */
enum ProductSyncScope: int
{
    case PRICE = 1 << 0;
    case STOCK = 1 << 1;
    case EVERYTHING = 1 << 2;
}
