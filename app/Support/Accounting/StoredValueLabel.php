<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * A stored token as a person reads it: `partially_paid` is "Partially paid".
 *
 * The same sentence-case formatting `statusLabel` gives the interface
 * (resources/js/lib/labels.ts) and the invoice document prints, so a display
 * field in an accounting read never shows the column's own vocabulary. A
 * token this has never seen still reads as words.
 */
final class StoredValueLabel
{
    public static function of(string $value): string
    {
        $words = trim((string) preg_replace('/[_-]+/', ' ', $value));

        return $words === '' ? '—' : ucfirst($words);
    }
}
