<?php

namespace App\Support\AgentApi\BearerRejection;

use BWH\Auth\OAuth\Credentials\OAuthCredentialOwners;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use Throwable;

/**
 * Names why a bearer was refused, for the rejection log line only: nothing
 * here decides whether a credential is accepted.
 *
 * A client id is read from a token only once its signature verifies against
 * this server's own key. Expiry does not matter - an expired token was still
 * issued here, to that client - but an unverified claim is whatever the
 * sender typed, so a forged or foreign JWT is logged without one.
 */
final class BearerRejectionClassifier
{
    /** The refusal Passport's resource-server validator reported. */
    public static function fromValidatorFailure(OAuthServerException $exception): BearerRejectionReason
    {
        $cause = $exception->getPrevious();
        if ($cause instanceof RequiredConstraintsViolated) {
            $violated = [];
            foreach ($cause->violations() as $violation) {
                $violated[(string) $violation->constraint][] = $violation->getMessage();
            }
            if (isset($violated[SignedWith::class])) {
                return BearerRejectionReason::BadSignature;
            }
            foreach ([LooseValidAt::class, StrictValidAt::class] as $timing) {
                if (in_array('The token is expired', $violated[$timing] ?? [], true)) {
                    return BearerRejectionReason::Expired;
                }
            }

            return BearerRejectionReason::InvalidToken;
        }
        // Parsing failed: no dots, undecodable segments, an unsupported header.
        if ($cause instanceof JwtException) {
            return BearerRejectionReason::InvalidStructure;
        }
        // The validator's word for every refusal after the signature and
        // expiry checks passed; the stored credential tells them apart.
        if ($exception->getHint() === 'Access token has been revoked') {
            return BearerRejectionReason::Revoked;
        }

        return BearerRejectionReason::InvalidToken;
    }

    /** The token and client ids of a JWT this server signed, expired or not. */
    public function verifiedClaims(#[\SensitiveParameter] string $token): ?VerifiedBearerClaims
    {
        if ($token === '') {
            return null;
        }
        try {
            $parsed = (new Parser(new JoseEncoder))->parse($token);
            $publicKey = $this->publicKey();
            if (! $parsed instanceof UnencryptedToken
                || $publicKey === ''
                || ! (new Validator)->validate($parsed, new SignedWith(new Sha256, InMemory::plainText($publicKey)))) {
                return null;
            }
            $tokenId = $parsed->claims()->get('jti');
            $clientId = $parsed->claims()->get('aud')[0] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($tokenId) && $tokenId !== '' && is_string($clientId) && $clientId !== ''
            ? new VerifiedBearerClaims($tokenId, $clientId)
            : null;
    }

    /**
     * Why a token this server issued was refused, read from its stored row in
     * the order the checks that refuse it run.
     */
    public function ofStoredCredential(Request $request, VerifiedBearerClaims $claims): BearerRejectionReason
    {
        $token = Passport::token()->newQuery()->find($claims->tokenId);
        if (! $token instanceof Token) {
            return BearerRejectionReason::UnknownToken;
        }
        if ((bool) $token->revoked) {
            return BearerRejectionReason::Revoked;
        }
        if ($token->expires_at === null || $token->expires_at->isPast()) {
            return BearerRejectionReason::Expired;
        }
        $client = Passport::client()->newQuery()->find($token->client_id);
        if ($client === null || (bool) $client->revoked) {
            return BearerRejectionReason::ClientRevoked;
        }
        $expected = OAuthResourceIndicator::expectedFor($request);
        $column = config('bherila-auth.oauth_server.resource_column', 'resource_uri');
        $stored = OAuthResourceIndicator::canonicalize($token->getAttribute(is_string($column) && $column !== '' ? $column : 'resource_uri'));
        if ($expected !== null && $stored !== $expected) {
            return BearerRejectionReason::WrongAudience;
        }
        try {
            // The shared provider observation the refusal itself just read:
            // this asks the provider again only once that observation is stale.
            if (app(OAuthCredentialOwners::class)->refused($token->user_id)
                || app(ProviderIdentityTokens::class)->revoked($token)) {
                return BearerRejectionReason::IdentityRefused;
            }
        } catch (Throwable) {
            // An unavailable provider is answered 503 elsewhere, never 401.
        }

        return BearerRejectionReason::Refused;
    }

    /** Passport's verification key, from where Passport itself reads it. */
    private function publicKey(): string
    {
        $key = str_replace('\\n', "\n", (string) config('passport.public_key'));

        return (new CryptKey($key !== '' ? $key : 'file://'.Passport::keyPath('oauth-public.key'), null, false))->getKeyContents();
    }
}
