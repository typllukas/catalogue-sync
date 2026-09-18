<?php

declare(strict_types=1);

namespace App\Helper;

use LogicException;

use function get_debug_type;
use function intval;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * (int) $value turns 'abc' into zero and '12 pcs' into twelve.
 */
final class MixedToInteger
{
    public static function transformStrict(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return intval($value);
        }

        throw new LogicException(sprintf('Expected an integer, got %s.', get_debug_type($value)));
    }
}
