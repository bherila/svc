<?php

namespace App\Services\Credentials;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\Auth\PersonalAccessTokenIdentity;
use App\Support\WorkspaceClock;
use BWH\Auth\OAuth\Credentials\CredentialOwnerRefused;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use Carbon\CarbonImmutable;
use DateInterval;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The one way SVC mints an agent token itself: the operator's
 * `svc:auth:issue-agent-token`, the post-deploy smoke and the pre-merge smoke.
 *
 * The token is an ordinary Passport personal access token: the same signed JWT,
 * bound to one protected resource, checked by the same resource repository and
 * provider identity policy as a token an agent obtains through OAuth. It holds
 * exactly the scopes requested, and every request it makes still passes the
 * person's own workspace and project roles - a scope is a ceiling, never a
 * grant.
 *
 * Issuing here means, for every caller alike:
 *
 * - nothing is issued while `AGENT_API_OAUTH_SERVER_ENABLED` is off, as for any
 *   other new credential;
 * - a provider-bound person is stamped with their provider identity, and an
 *   identity the provider reports inactive is never issued anything;
 * - the token comes from the personal access client Passport itself issues
 *   agent-principal tokens from, created only when none exists - a second
 *   client would silently change the client every other issuer receives;
 * - the lifetime asked for is the lifetime signed: the authorization server is
 *   rebuilt around it and Passport's shared lifetime is put back afterwards,
 *   and a token that would outlive the request is revoked rather than returned;
 * - the resource is named both ways the access-token repository reads it before
 *   Passport signs the JWT. Never patch `resource_uri` afterwards: the signed
 *   audience would still name the other resource;
 * - an `agent_token.issued` entry is logged by credential fingerprint. The
 *   bearer value exists only in the returned {@see IssuedAgentToken}.
 */
final readonly class AgentTokenIssuer
{
    private const string PROVIDER = 'agent-principals';

    /** Used only when no personal access client exists for agent principals yet. */
    private const string CLIENT_NAME = 'SVC agent tokens';

    public function __construct(
        private Container $container,
        private ClientRepository $clients,
        private PersonalAccessTokenIdentity $identity,
        private CredentialRevoker $revoker,
        private LoggerInterface $log,
        private WorkspaceClock $clock,
    ) {}

    /**
     * @throws AgentTokenRefused when this deployment or this person may not be issued a token now
     */
    public function issue(User $person, AgentTokenRequest $request): IssuedAgentToken
    {
        if (! (bool) config('bherila-auth.oauth_server.enabled', false)) {
            throw new AgentTokenRefused('Agent token issuance is disabled on this deployment (AGENT_API_OAUTH_SERVER_ENABLED).');
        }

        $principal = AgentPrincipal::query()->whereKey($person->getKey())->firstOrFail();
        $resource = OAuthResourceIndicator::resource($request->resource);
        try {
            $providerStamp = $this->providerStamp($person);
            $issued = $this->withLifetime($request->ttlMinutes, fn (): array => $this->create($principal, $request, $resource, $providerStamp));
        } catch (CredentialOwnerRefused) {
            throw new AgentTokenRefused('This account cannot hold API credentials.');
        }

        $row = Passport::token()->newQuery()->whereKey($issued['id'])->firstOrFail(['expires_at', 'revoked']);
        // A revocation of this person's credentials is under way, and its
        // fence revoked the token as Passport persisted it.
        if ((bool) $row->getAttribute('revoked')) {
            throw new AgentTokenRefused('A revocation of this person\'s agent credentials is in progress, so the token was revoked as it was issued. Retry after '.AgentCredentialFence::SECONDS.' seconds.');
        }
        $expiresAt = CarbonImmutable::parse($row->getAttribute('expires_at'));
        // Passport reads the lifetime from shared state when it builds its
        // server; never hand out a credential that outlives what was asked.
        if ($expiresAt->greaterThan($this->clock->now()->addMinutes($request->ttlMinutes + 1))) {
            $this->revoker->revoke(AgentCredentialSelection::token($principal, $issued['id'], fence: false), $request->kind->actor());

            throw new AgentTokenRefused('The token was issued with a longer lifetime than requested and has been revoked.');
        }

        $token = new IssuedAgentToken($issued['id'], $issued['token'], $expiresAt, $request->label, $request->scopes, $resource);
        $this->log->info('agent_token.issued', [
            'actor' => $request->kind->actor(),
            'subject_id' => $principal->public_id,
            'name' => $request->label,
            'scopes' => $request->scopes,
            'resource' => $resource,
            'expires_at' => $expiresAt->toIso8601String(),
            'credential_fingerprint' => $token->fingerprint(),
        ]);

        return $token;
    }

    /**
     * The same provider check the finance token command makes.
     *
     * @return array{subject: string, generation: int}|null
     */
    private function providerStamp(User $person): ?array
    {
        try {
            $stamp = $this->identity->stampForIssue($person);
        } catch (DomainException $refused) {
            throw new AgentTokenRefused($refused->getMessage(), previous: $refused);
        } catch (ProviderStatusUnavailable $unavailable) {
            throw new AgentTokenRefused('Provider identity verification is unavailable. Retry later.', previous: $unavailable);
        }

        return isset($stamp[ProviderIdentityTokens::SUBJECT_COLUMN], $stamp[ProviderIdentityTokens::GENERATION_COLUMN])
            ? ['subject' => $stamp[ProviderIdentityTokens::SUBJECT_COLUMN], 'generation' => $stamp[ProviderIdentityTokens::GENERATION_COLUMN]]
            : null;
    }

    /**
     * Name the resource (and carry the provider stamp) on the request the
     * access-token repository reads, issue, and put the request back.
     *
     * @param  array{subject: string, generation: int}|null  $providerStamp
     * @return array{id: string, token: string}
     */
    private function create(AgentPrincipal $principal, AgentTokenRequest $request, string $resource, ?array $providerStamp): array
    {
        $clientId = $this->personalAccessClient();
        $http = request();
        $hadResource = $http->query->has('resource');
        $previousResource = $http->query->get('resource');
        $hadStamp = $http->attributes->has(ProviderIdentityTokens::REQUEST_ATTRIBUTE);
        $previousStamp = $http->attributes->get(ProviderIdentityTokens::REQUEST_ATTRIBUTE);
        $http->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $resource);
        $http->query->set('resource', $resource);
        if ($providerStamp !== null) {
            $http->attributes->set(ProviderIdentityTokens::REQUEST_ATTRIBUTE, $providerStamp);
        }
        try {
            $issued = $principal->createToken($request->storedName(), $request->scopes);
        } finally {
            $http->attributes->remove(OAuthResourceIndicator::REQUEST_ATTRIBUTE);
            $hadStamp
                ? $http->attributes->set(ProviderIdentityTokens::REQUEST_ATTRIBUTE, $previousStamp)
                : $http->attributes->remove(ProviderIdentityTokens::REQUEST_ATTRIBUTE);
            $hadResource ? $http->query->set('resource', $previousResource) : $http->query->remove('resource');
        }

        // The repository binds the row and the signed JWT during issuance.
        // Checking the client and resource here keeps a misconfigured Passport
        // client from producing a credential nobody can revoke or the endpoint
        // would refuse. A row that fails is revoked rather than left live.
        $bound = Passport::token()->newQuery()
            ->whereKey($issued->accessTokenId)
            ->where('user_id', $principal->getKey())
            ->where('client_id', $clientId)
            ->where('resource_uri', $resource)
            ->exists();
        if (! $bound) {
            $this->revoker->revoke(AgentCredentialSelection::token($principal, (string) $issued->accessTokenId, fence: false), $request->kind->actor());

            throw new AgentTokenRefused('The token was not issued from the expected client and resource, and has been revoked. Retry.');
        }

        return ['id' => (string) $issued->accessTokenId, 'token' => (string) $issued->accessToken];
    }

    /**
     * Run an issue under this lifetime, then put Passport's personal token
     * lifetime back so nothing else this process issues inherits it.
     *
     * @template T
     *
     * @param  callable(): T  $issue
     * @return T
     */
    private function withLifetime(int $minutes, callable $issue): mixed
    {
        $previous = Passport::$personalAccessTokensExpireIn;
        Passport::personalAccessTokensExpireIn(new DateInterval('PT'.$minutes.'M'));
        // The server reads the lifetime when it is built, so build it afresh.
        $this->container->forgetInstance(AuthorizationServer::class);
        try {
            return $issue();
        } finally {
            Passport::$personalAccessTokensExpireIn = $previous;
            $this->container->forgetInstance(AuthorizationServer::class);
        }
    }

    /**
     * The client Passport itself issues agent-principal personal tokens from.
     *
     * Passport picks the newest personal access client for the provider and
     * cannot be told otherwise, so one is created only when there is none -
     * under a lock, so two first issues cannot each create one.
     */
    private function personalAccessClient(): string
    {
        try {
            return (string) $this->clients->personalAccessClient(self::PROVIDER)->getKey();
        } catch (RuntimeException) {
            return Cache::lock('agent-token-issuer:personal-access-client', 10)->block(10, function (): string {
                try {
                    return (string) $this->clients->personalAccessClient(self::PROVIDER)->getKey();
                } catch (RuntimeException) {
                    return (string) $this->clients->createPersonalAccessGrantClient(self::CLIENT_NAME, self::PROVIDER)->getKey();
                }
            });
        }
    }
}
