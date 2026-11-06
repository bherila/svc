<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/**
 * Stored billing values are snake-case protocol tokens: useful for comparing and
 * the wrong vocabulary to print on a document a client is asked to pay. Mirrors
 * the browser's `statusLabel`.
 */
final class StoredValueLabel
{
    public static function of(string $value): string
    {
        $words = trim((string) preg_replace('/[_-]+/', ' ', $value));

        return $words === '' ? '—' : ucfirst($words);
    }
}
