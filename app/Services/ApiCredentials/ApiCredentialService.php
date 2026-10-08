<?php

namespace App\Services\ApiCredentials;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\WorkspaceClock;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use DomainException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use RuntimeException;

/**
 * Credentials a person issues for apps that use the REST API without MCP (#384).
 *
 * Two kinds, both owned by the signed-in user and both bound to this server's
 * one protected resource, so `/api/v1` accepts them and nothing else does:
 *
 * - a personal access token: chosen scopes, a bounded lifetime, shown once, for
 *   a connector that asks for "an API key";
 * - an OAuth app: exact redirect URIs, public (PKCE only) or confidential (with
 *   a secret shown once), for a connector that runs the authorization-code flow.
 *
 * Issuing is a browser-only act. These are never API operations, so an OAuth
 * credential cannot mint another one.
 */
final class ApiCredentialService
{
    public const string PERSONAL_CLIENT_NAME = 'SVC personal access tokens';

    public const string TOKEN_NAME_PREFIX = 'api-token: ';

    /** @var list<int> */
    public const array TOKEN_LIFETIMES_DAYS = [30, 90, 365];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * The scopes a REST credential may carry: those some REST operation in the
     * OpenAPI document actually requires, without the MCP transport.
     *
     * Read from the contract rather than the scope catalog, because a scope
     * whose operations exist only as MCP tools (client management, until its
     * REST routes land) would give a credential that cannot call anything. As
     * each REST operation is added, its scope becomes grantable with it.
     *
     * @return list<string>
     */
    public static function grantableScopes(): array
    {
        static $scopes = null;
        if ($scopes === null) {
            $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $used = [];
            foreach ($document['paths'] as $path) {
                foreach ($path as $operation) {
                    foreach ($operation['security'][0]['oauth2'] ?? [] as $scope) {
                        $used[$scope] = true;
                    }
                }
            }
            $scopes = array_values(array_filter(
                array_keys(AgentApiScopes::descriptions()),
                static fn (string $scope): bool => $scope !== AgentApiScopes::MCP_USE && isset($used[$scope]),
            ));
        }

        return $scopes;
    }

    /**
     * @param  list<string>  $scopes
     * @return array{id: string, token: string} the bearer token, which is not stored and cannot be shown again
     */
    public function issueToken(User $user, string $name, array $scopes, int $days): array
    {
        $this->assertGrantable($scopes);
        if (! in_array($days, self::TOKEN_LIFETIMES_DAYS, true)) {
            throw new DomainException('Choose one of the offered token lifetimes.');
        }
        $principal = $this->principal($user);
        $this->ensurePersonalClient();

        $request = request();
        $request->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, OAuthResourceIndicator::configuredCanonical());
        $previous = Passport::$personalAccessTokensExpireIn;
        Passport::personalAccessTokensExpireIn($this->clock->now('UTC')->addDays($days));
        try {
            $issued = $principal->createToken(self::TOKEN_NAME_PREFIX.trim($name), array_values(array_unique($scopes)));
        } finally {
            Passport::$personalAccessTokensExpireIn = $previous;
            $request->attributes->remove(OAuthResourceIndicator::REQUEST_ATTRIBUTE);
        }

        // Refuse to hand out a credential that is not what this says it is:
        // issued to this user and bound to this resource.
        Passport::token()->newQuery()
            ->whereKey($issued->accessTokenId)
            ->where('user_id', $user->id)
            ->where('resource_uri', OAuthResourceIndicator::resource())
            ->firstOrFail();

        return ['id' => (string) $issued->accessTokenId, 'token' => (string) $issued->accessToken];
    }

    /** @return list<array{id: string, name: string, scopes: list<string>, created_at: string|null, expires_at: string|null}> */
    public function tokens(User $user): array
    {
        return array_values(Passport::token()->newQuery()
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->where('expires_at', '>', $this->clock->now('UTC'))
            ->where('name', 'like', self::TOKEN_NAME_PREFIX.'%')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Token $token): array => [
                'id' => (string) $token->getKey(),
                'name' => substr((string) $token->name, strlen(self::TOKEN_NAME_PREFIX)),
                'scopes' => array_values(array_map('strval', (array) $token->scopes)),
                'created_at' => $token->created_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->all());
    }

    public function revokeToken(User $user, string $tokenId): void
    {
        $revoked = Passport::token()->newQuery()
            ->whereKey($tokenId)
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->where('name', 'like', self::TOKEN_NAME_PREFIX.'%')
            ->update(['revoked' => true, 'updated_at' => $this->clock->now('UTC')]);
        abort_if($revoked === 0, 404);
    }

    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>  $scopes
     * @return array{client: Client, secret: string|null}
     */
    public function registerApp(User $user, string $name, array $redirectUris, bool $confidential, array $scopes): array
    {
        $this->assertGrantable($scopes);
        foreach ($redirectUris as $uri) {
            if (! self::validRedirectUri($uri)) {
                throw new DomainException('Redirect URIs must be https, or http on a loopback address, with no fragment or credentials.');
            }
        }

        // On Passport's own connection, which may not be the default one.
        return Passport::client()->getConnection()->transaction(function () use ($user, $name, $redirectUris, $confidential, $scopes): array {
            $client = $this->clients->createAuthorizationCodeGrantClient(
                trim($name),
                array_values(array_unique($redirectUris)),
                $confidential,
                $this->principal($user),
            );
            // Read once, here: the stored secret is hashed.
            $secret = $confidential ? $client->plainSecret : null;
            // The consent ceiling, as for a self-registered client.
            $client->forceFill(['scopes' => array_values(array_unique($scopes))])->save();

            return ['client' => $client, 'secret' => $secret];
        });
    }

    /** @return list<array{id: string, name: string, confidential: bool, redirect_uris: list<string>, scopes: list<string>, created_at: string|null}> */
    public function apps(User $user): array
    {
        return array_values($this->principal($user)->oauthApps()
            ->where('revoked', false)
            ->orderBy('name')
            ->get()
            ->map(static fn (Client $client): array => [
                'id' => (string) $client->getKey(),
                'name' => (string) $client->name,
                'confidential' => $client->confidential(),
                'redirect_uris' => array_values(array_map('strval', (array) $client->getAttribute('redirect_uris'))),
                'scopes' => array_values(array_map('strval', (array) $client->getAttribute('scopes'))),
                'created_at' => $client->created_at?->toIso8601String(),
            ])
            ->all());
    }

    /** Revoke the app and every token and refresh token issued to it. */
    public function deleteApp(User $user, string $clientId): void
    {
        $client = $this->principal($user)->oauthApps()->where('revoked', false)->whereKey($clientId)->first();
        abort_unless($client instanceof Client, 404);
        Passport::token()->getConnection()->transaction(function () use ($client): void {
            $tokenIds = Passport::token()->newQuery()->where('client_id', $client->getKey())->pluck('id');
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            Passport::token()->newQuery()->where('client_id', $client->getKey())->update(['revoked' => true, 'updated_at' => $this->clock->now('UTC')]);
            $client->forceFill(['revoked' => true])->save();
        });
    }

    public static function validRedirectUri(string $uri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false || strlen($uri) > 2048) {
            return false;
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        return $scheme === 'https' && $host !== ''
            || $scheme === 'http' && in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }

    /** @param list<string> $scopes */
    private function assertGrantable(array $scopes): void
    {
        if ($scopes === [] || array_diff($scopes, self::grantableScopes()) !== []) {
            throw new DomainException('Choose at least one of the offered permissions.');
        }
    }

    private function principal(User $user): AgentPrincipal
    {
        return AgentPrincipal::query()->whereKey($user->id)->firstOrFail();
    }

    /**
     * Passport issues every personal token through the newest personal-access
     * client for the provider, whichever that is - the deployment smoke
     * command's included - so tokens here are told apart by owner and name, not
     * by client. One is created only when none exists, so it is never newer
     * than a client another caller already relies on being newest.
     */
    private function ensurePersonalClient(): void
    {
        try {
            $this->clients->personalAccessClient('agent-principals');
        } catch (RuntimeException) {
            $this->clients->createPersonalAccessGrantClient(self::PERSONAL_CLIENT_NAME, 'agent-principals');
        }
    }
}
