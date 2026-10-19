<?php

namespace App\Services\Credentials;

/**
 * How every credential audit entry names a token, so entries about one
 * credential can be matched without the log holding anything that identifies
 * or reconstructs it.
 */
final class CredentialFingerprint
{
    /**
     * A SHA-256 of a Passport token id: 80 random characters, so the digest
     * cannot be reversed by trying ids.
     */
    public static function of(string $id): string
    {
        return hash('sha256', $id);
    }

    /**
     * @param  iterable<string>  $ids
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

    /**
     * An HMAC, under the application key, of a sequential id (a Sanctum token
     * row's): a plain digest of a small integer is reversed by counting.
     */
    public static function keyed(int $id, string $key): string
    {
        return hash_hmac('sha256', 'personal_access_token:'.$id, $key);
    }
}
