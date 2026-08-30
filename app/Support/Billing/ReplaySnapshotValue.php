<?php

namespace App\Support\Billing;

/** Validated scalar extraction at the untyped replay-report boundary. */
final class ReplaySnapshotValue
{
    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    public static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /** @return list<array<string, mixed>> */
    public static function arrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $arrays = [];
        foreach ($value as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $row = [];
            foreach ($candidate as $key => $item) {
                if (is_string($key)) {
                    $row[$key] = $item;
                }
            }
            $arrays[] = $row;
        }

        return $arrays;
    }
}
