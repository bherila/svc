<?php

namespace App\Console\Commands;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Mcp\McpConnectionTokens;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Auth\PersonalAccessTokenIdentity;
use BWH\Auth\OAuth\Credentials\CredentialOwnerRefused;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Issue a short-lived agent (REST or MCP) access token for one exact person,
 * from a shell, for an operator who needs to act as an agent would without
 * walking a browser consent flow.
 *
 * The token is an ordinary Passport personal access token, issued through
 * {@see McpConnectionTokens} like the deployment smoke's: the same signed JWT,
 * bound to one protected resource, checked by the same resource repository and
 * provider identity policy as a token an agent obtains through OAuth. It holds
 * exactly the scopes named, and every request it makes still passes the
 * person's own workspace and project roles - a scope is a ceiling, never a
 * grant.
 *
 * A token is bound to one resource. Naming `mcp:use` makes it an MCP token
 * (APP_URL/api/v1/mcp); otherwise it is a REST token (APP_URL/api/v1). The
 * REST resource never admits `mcp:use`, and an MCP connection cannot open
 * without it, so the scopes leave only one choice.
 */
final class IssueAgentTokenCommand extends Command
{
    public const int MAX_TTL_MINUTES = 240;

    /**
     * Stored before the operator's name, as the browser's personal tokens
     * carry their own prefix, so revocation by name can only ever select a
     * token this command issued - never a person's own credential or an
     * OAuth-connected agent's that happens to share the name.
     */
    public const string NAME_PREFIX = 'operator-agent-token: ';

    private const string PROVIDER = 'agent-principals';

    /** Used only when no personal access client exists for agent principals yet. */
    private const string CLIENT_NAME = 'SVC operator agent tokens';

    protected $signature = 'svc:auth:issue-agent-token
        {user_public_id : Exact user public UUID}
        {name : Name for the agent token}
        {scopes* : Agent scopes; include mcp:use for an MCP token, omit it for a REST token}
        {--ttl=60 : Minutes the token remains valid, at most 240}';

    protected $description = 'Issue a short-lived, scope-limited agent (REST or MCP) access token for one exact user';

    public function handle(McpConnectionTokens $tokens, PersonalAccessTokenIdentity $identity, LoggerInterface $log): int
    {
        $publicId = trim((string) $this->argument('user_public_id'));
        if (! Str::isUuid($publicId)) {
            $this->error('The user_public_id must be an exact public UUID.');

            return self::INVALID;
        }

        $name = trim((string) $this->argument('name'));
        $maxName = 255 - strlen(self::NAME_PREFIX);
        if ($name === '' || mb_strlen($name) > $maxName || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $this->error("The token name must be 1-{$maxName} characters with no control characters.");

            return self::INVALID;
        }

        $scopes = $this->scopes((array) $this->argument('scopes'));
        if ($scopes === null) {
            $this->error('Name at least one scope, each one of: '.implode(', ', array_keys(AgentApiScopes::descriptions())).'.');

            return self::INVALID;
        }
        $resourceName = in_array(AgentApiScopes::MCP_USE, $scopes, true) ? 'mcp' : 'rest';
        $ceiling = $resourceName === 'mcp' ? AgentApiScopes::mcp() : AgentApiScopes::rest();
        if (array_diff($scopes, $ceiling) !== []) {
            $this->error("A {$resourceName} token cannot carry: ".implode(', ', array_diff($scopes, $ceiling)).'.');

            return self::INVALID;
        }

        $ttl = $this->ttl((string) $this->option('ttl'));
        if ($ttl === null) {
            $this->error('--ttl must be a whole number of minutes from 1 to '.self::MAX_TTL_MINUTES.'.');

            return self::INVALID;
        }

        // The OAuth server's switch turns off issuing new credentials while
        // already-issued ones keep working; an operator token is one too.
        if (! (bool) config('bherila-auth.oauth_server.enabled', false)) {
            $this->error('Agent token issuance is disabled on this deployment (AGENT_API_OAUTH_SERVER_ENABLED).');

            return self::FAILURE;
        }

        $user = User::query()->where('public_id', $publicId)->first();
        if ($user === null) {
            $this->error('No user exists for that public UUID.');

            return self::FAILURE;
        }

        // The same provider check the finance token command makes: an identity
        // the provider reports inactive is never issued a credential.
        try {
            $stamp = $identity->stampForIssue($user);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ProviderStatusUnavailable) {
            $this->error('Provider identity verification is unavailable. Retry later.');

            return self::FAILURE;
        }
        $providerStamp = isset($stamp[ProviderIdentityTokens::SUBJECT_COLUMN], $stamp[ProviderIdentityTokens::GENERATION_COLUMN])
            ? ['subject' => $stamp[ProviderIdentityTokens::SUBJECT_COLUMN], 'generation' => $stamp[ProviderIdentityTokens::GENERATION_COLUMN]]
            : null;

        $principal = AgentPrincipal::query()->whereKey($user->getKey())->firstOrFail();

        try {
            $issued = $this->withLifetime($ttl, fn () => $tokens->issueToken(
                $principal,
                $this->personalAccessClient(),
                self::NAME_PREFIX.$name,
                $scopes,
                $resourceName,
                $providerStamp,
            ));
        } catch (CredentialOwnerRefused) {
            $this->error('This account cannot hold API credentials.');

            return self::FAILURE;
        }

        $row = Passport::token()->newQuery()->whereKey($issued['id'])->firstOrFail();
        $expiresAt = Carbon::parse($row->getAttribute('expires_at'));
        // Passport reads the lifetime from shared state when it builds its
        // server; never hand out a credential that outlives what was asked.
        $ceilingAt = (new DateTimeImmutable)->modify('+'.($ttl + 1).' minutes');
        if ($expiresAt->greaterThan($ceilingAt)) {
            Passport::token()->newQuery()->whereKey($issued['id'])->update(['revoked' => true]);
            $this->error('The token was issued with a longer lifetime than requested and has been revoked.');

            return self::FAILURE;
        }

        $resource = OAuthResourceIndicator::resource($resourceName);
        $log->info('agent_token.issued', [
            'actor' => 'console',
            'subject_id' => $publicId,
            'name' => $name,
            'scopes' => $scopes,
            'resource' => $resource,
            'expires_at' => $expiresAt->toIso8601String(),
            'credential_fingerprint' => hash('sha256', $issued['id']),
        ]);

        $this->line((string) json_encode([
            'token' => $issued['token'],
            'expires_at' => $expiresAt->toIso8601String(),
            'name' => $name,
            'scopes' => $scopes,
            'resource' => $resource,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Exactly the registry's scopes, deduplicated in the order given; null when
     * none is named or any is not one of them.
     *
     * @param  array<int, mixed>  $requested
     * @return non-empty-list<string>|null
     */
    private function scopes(array $requested): ?array
    {
        $known = array_keys(AgentApiScopes::descriptions());
        $scopes = [];
        foreach ($requested as $scope) {
            $scope = trim((string) $scope);
            if (! in_array($scope, $known, true)) {
                return null;
            }
            if (! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes === [] ? null : $scopes;
    }

    private function ttl(string $value): ?int
    {
        if (preg_match('/^\d{1,4}$/', trim($value)) !== 1) {
            return null;
        }
        $minutes = (int) trim($value);

        return $minutes >= 1 && $minutes <= self::MAX_TTL_MINUTES ? $minutes : null;
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
        Passport::personalAccessTokensExpireIn(new \DateInterval('PT'.$minutes.'M'));
        // The server reads the lifetime when it is built, so build it afresh.
        app()->forgetInstance(AuthorizationServer::class);
        try {
            return $issue();
        } finally {
            Passport::$personalAccessTokensExpireIn = $previous;
            app()->forgetInstance(AuthorizationServer::class);
        }
    }

    /**
     * The client Passport itself issues agent-principal personal tokens from.
     *
     * Passport picks the newest personal access client for the provider and
     * cannot be told otherwise, so this creates one only when there is none:
     * adding a second would silently change the client every other personal
     * token issuer (the deployment smoke included) receives.
     */
    private function personalAccessClient(): string
    {
        $clients = app(ClientRepository::class);
        try {
            return (string) $clients->personalAccessClient(self::PROVIDER)->getKey();
        } catch (RuntimeException) {
            return (string) $clients->createPersonalAccessGrantClient(self::CLIENT_NAME, self::PROVIDER)->getKey();
        }
    }
}
