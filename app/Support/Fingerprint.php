<?php

namespace App\Support;

/**
 * A deterministic hash of a database row, stable across key order and driver
 * date formatting.
 *
 * It was written for the external importer, to prove a source row had not moved
 * between reading it and writing it. The importer is gone; this is not, because
 * two suites use it for a different question - whether a permutation of the same
 * events leaves the same rows behind. That needs one canonical spelling of "the
 * same row", and the normalisation below is it: nested keys sorted, lists left
 * in order because their order is data, and dates rendered one way whatever the
 * driver handed back.
 */
final class Fingerprint
{
    /** @param array<string, mixed> $row */
    public static function row(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode(self::normalize($row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param iterable<array<string, mixed>> $rows */
    public static function rows(iterable $rows): string
    {
        $hash = hash_init('sha256');
        foreach ($rows as $row) {
            hash_update($hash, self::row($row));
            hash_update($hash, "\n");
        }

        return hash_final($hash);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            ksort($value);

            return array_map(self::normalize(...), $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return $value;
    }
}
