<?php

namespace App\Support\Accounting;

/** The type boundary from stored dictionaries to signed integer minor units. */
final class MinorUnits
{
    /**
     * @infection-ignore-all Stored amounts are integers or integer strings. Without strict_types, the int return type performs the same coercion if this explicit cast is removed; it exists for static analysis.
     */
    public static function from(mixed $value): int
    {
        return (int) $value;
    }
}
