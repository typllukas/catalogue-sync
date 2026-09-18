<?php

declare(strict_types=1);

namespace App\Helper;

use LogicException;

use function floatval;
use function get_debug_type;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * (float) $value turns 'abc' into zero. An int is accepted: JSON gives no way to tell 14.0 from 14.
 */
final class MixedToFloat
{
    public static function transformStrict(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return floatval($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return floatval($value);
        }

        throw new LogicException(sprintf('Expected a float, got %s.', get_debug_type($value)));
    }
}
