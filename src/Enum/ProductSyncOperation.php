<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductSyncOperation: string
{
    case WRITE = 'write';
    case DELETE = 'delete';
}
