<?php

namespace App\Support\Auth;

use BWH\Auth\OAuth\Server\ResourceClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Scope;

/**
 * SVC's OAuth client model: the auth package's resource client (RFC 8707
 * enforcement, consent kept for dynamically registered clients) plus one
 * exception to the consent screen.
 *
 * A first-party application an operator registered (svc:auth:register-first-
 * party-client) and allow-listed (`agent_api.first_party_oauth_clients`) is
 * approved without a consent screen, so a person already signed in through the
 * identity provider connects it with no extra click. Everything else asks,
 * including a client on the list that is no longer such a client: one a person
 * registered (it has an owner), one registered dynamically, one revoked, or
 * one that can take any grant but the authorization code. The scope ceiling,
 * PKCE and resource checks run before this and are unaffected by it.
 */
class OAuthClient extends ResourceClient
{
    /**
     * @param  array<int, Scope>  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->isAllowListedFirstParty() || parent::skipsAuthorization($user, $scopes);
    }

    private function isAllowListedFirstParty(): bool
    {
        $allowed = config('agent_api.first_party_oauth_clients', []);
        if (! is_array($allowed) || $allowed === []) {
            return false;
        }
        $id = strtolower((string) $this->getKey());
        $grants = $this->getAttribute('grant_types');
        $listed = array_filter($allowed, static fn (mixed $entry): bool => is_string($entry) && hash_equals($id, strtolower(trim($entry))));

        return $listed !== []
            && $this->firstParty()
            && $this->confidential()
            && ! $this->revoked
            && $this->getAttribute((string) config('bherila-auth.oauth_server.dynamic_clients.registered_at_column', 'dynamically_registered_at')) === null
            && is_array($grants)
            && in_array('authorization_code', $grants, true)
            && array_diff($grants, ['authorization_code', 'refresh_token']) === [];
    }
}
