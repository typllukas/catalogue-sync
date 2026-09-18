<?php

declare(strict_types=1);

namespace App\Helper;

use LogicException;

use function get_debug_type;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function strval;

/**
 * (string) $value turns an array into 'Array' and null into an empty string.
 */
final class MixedToString
{
    public static function transformStrict(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return strval($value);
        }

        throw new LogicException(sprintf('Expected a string, got %s.', get_debug_type($value)));
    }
}
