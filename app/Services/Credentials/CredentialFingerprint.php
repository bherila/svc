<?php

namespace App\Services\Credentials;

/**
 * How every credential audit entry names a token: a SHA-256 of its stored id,
 * so entries about one credential can be matched without the log holding
 * anything that identifies or reconstructs it.
 */
final class CredentialFingerprint
{
    public static function of(string|int $id): string
    {
        /** @infection-ignore-all Equivalent: this file is not strict, so hash() coerces an integer id identically without the cast. */
        return hash('sha256', (string) $id);
    }

    /**
     * @param  iterable<string|int>  $ids
     * @return list<string>
     */
    public static function all(iterable $ids): array
    {
        $fingerprints = [];
        foreach ($ids as $id) {
            $fingerprints[] = self::of($id);
        }

        return $fingerprints;
    }
}
