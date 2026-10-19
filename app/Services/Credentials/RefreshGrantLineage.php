<?php

namespace App\Services\Credentials;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptTrait;
use Throwable;

/**
 * Which access token a refresh-token grant in this request descends from.
 *
 * Passport announces the replacement access token a refresh persists without
 * naming the one it replaces. The refresh token the client presented names it:
 * it is the payload League's grant decrypted with the server's encryption key,
 * which this decrypts the same way. Anything that is not a well-formed refresh
 * grant answers null.
 */
final class RefreshGrantLineage
{
    use CryptTrait;

    public function __construct(Encrypter $encrypter)
    {
        $this->setEncryptionKey(Passport::tokenEncryptionKey($encrypter));
    }

    public function redeemedAccessTokenId(?Request $request): ?string
    {
        $presented = $request?->input('refresh_token');
        if ($request?->input('grant_type') !== 'refresh_token' || ! is_string($presented)) {
            return null;
        }
        try {
            $payload = json_decode($this->decrypt($presented), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        $accessTokenId = is_array($payload) ? ($payload['access_token_id'] ?? null) : null;

        return is_string($accessTokenId) && $accessTokenId !== '' ? $accessTokenId : null;
    }
}
