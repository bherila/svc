<?php

namespace Tests\Unit\Credentials;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Credentials\AgentCredentialScope;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\AgentRevocation;
use App\Services\Credentials\AgentTokenKind;
use App\Services\Credentials\AgentTokenRequest;
use App\Services\Credentials\AmbiguousRevocation;
use App\Services\Credentials\CredentialFingerprint;
use App\Services\Credentials\InvalidAgentTokenRequest;
use App\Services\Credentials\IssuedAgentToken;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the one agent token issuer and the one revoker decide before touching
 * a database: which requests are refused, which resource scopes leave, how a
 * selection is bounded to its owner, and what an audit entry may carry.
 */
final class AgentTokenRequestTest extends TestCase
{
    public function test_a_request_keeps_the_registry_s_scopes_once_each_in_the_order_given(): void
    {
        $request = AgentTokenRequest::of(AgentTokenKind::Operator, '  Support session ', [' projects:read', 'identity:read', 'projects:read'], 60);

        $this->assertSame(AgentTokenKind::Operator, $request->kind);
        $this->assertSame('Support session', $request->label);
        $this->assertSame([AgentApiScopes::PROJECTS_READ, AgentApiScopes::IDENTITY_READ], $request->scopes);
        $this->assertSame('rest', $request->resource);
        $this->assertSame(60, $request->ttlMinutes);
        $this->assertSame('operator-agent-token: Support session', $request->storedName());
    }

    public function test_naming_the_connection_scope_makes_an_mcp_token(): void
    {
        $request = AgentTokenRequest::of(AgentTokenKind::DeploymentSmoke, 'authorized', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::MCP_USE], 15);

        $this->assertSame('mcp', $request->resource);
        $this->assertSame([AgentApiScopes::IDENTITY_READ, AgentApiScopes::MCP_USE], $request->scopes);
        $this->assertSame('Deployment MCP smoke authorized', $request->storedName());
    }

    public function test_the_label_fills_the_stored_column_under_its_prefix_and_no_further(): void
    {
        foreach (AgentTokenKind::cases() as $kind) {
            $max = 255 - strlen($kind->namePrefix());
            // Characters, not bytes: the column holds 255 characters.
            $fits = str_repeat('é', $max);
            $this->assertSame($fits, AgentTokenRequest::of($kind, $fits, [AgentApiScopes::IDENTITY_READ], 1)->label, $kind->name);

            $this->assertRefused("The token name must be 1-{$max} characters with no control characters.", $kind, str_repeat('n', $max + 1), [AgentApiScopes::IDENTITY_READ], 1);
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function badLabels(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'line break' => ["Line\nbreak"];
        yield 'nul' => ["Nul\x00byte"];
        yield 'delete' => ["Delete\x7F"];
        yield 'escape' => ["\x1B[31mRed"];
    }

    #[DataProvider('badLabels')]
    public function test_a_blank_label_or_one_with_control_characters_is_refused(string $label): void
    {
        $this->assertRefused('The token name must be 1-233 characters with no control characters.', AgentTokenKind::Operator, $label, [AgentApiScopes::IDENTITY_READ], 60);
    }

    /** @return iterable<string, array{0: array<array-key, mixed>}> */
    public static function badScopes(): iterable
    {
        yield 'none' => [[]];
        yield 'unknown' => [[AgentApiScopes::IDENTITY_READ, 'finance.read']];
        yield 'wildcard' => [['*']];
        yield 'blank' => [['']];
        yield 'not a string' => [[AgentApiScopes::IDENTITY_READ, 7]];
        yield 'case differs' => [['IDENTITY:READ']];
    }

    /** @param  array<array-key, mixed>  $scopes */
    #[DataProvider('badScopes')]
    public function test_anything_but_registry_scopes_is_refused(array $scopes): void
    {
        $this->assertRefused(
            'Name at least one scope, each one of: '.implode(', ', array_keys(AgentApiScopes::descriptions())).'.',
            AgentTokenKind::Operator,
            'Scopes',
            $scopes,
            60,
        );
    }

    public function test_the_lifetime_is_one_to_two_hundred_forty_minutes(): void
    {
        $this->assertSame(1, AgentTokenRequest::of(AgentTokenKind::Operator, 'Shortest', [AgentApiScopes::IDENTITY_READ], 1)->ttlMinutes);
        $this->assertSame(240, AgentTokenRequest::of(AgentTokenKind::Operator, 'Longest', [AgentApiScopes::IDENTITY_READ], 240)->ttlMinutes);
        $this->assertSame(240, AgentTokenRequest::MAX_TTL_MINUTES);

        foreach ([0, -1, 241] as $minutes) {
            $this->assertRefused('The lifetime must be a whole number of minutes from 1 to 240.', AgentTokenKind::Operator, 'Lifetime', [AgentApiScopes::IDENTITY_READ], $minutes);
        }
    }

    public function test_a_shell_lifetime_is_whole_minutes_within_the_bounds(): void
    {
        foreach (['1' => 1, ' 60 ' => 60, '240' => 240, '0240' => 240] as $input => $minutes) {
            $this->assertSame($minutes, AgentTokenRequest::ttlFromInput((string) $input), (string) $input);
        }
        foreach (['', '0', '241', '-5', '1.5', 'ten', '60m', '00060', '9999'] as $input) {
            $this->assertNull(AgentTokenRequest::ttlFromInput($input), $input);
        }
    }

    public function test_each_kind_has_its_own_prefix_and_actor(): void
    {
        $this->assertSame(['operator-agent-token: ', 'Deployment MCP smoke ', 'SVC MCP smoke '], array_map(static fn (AgentTokenKind $kind): string => $kind->namePrefix(), AgentTokenKind::cases()));
        $this->assertSame(['console', 'deploy-smoke', 'ci-smoke'], array_map(static fn (AgentTokenKind $kind): string => $kind->actor(), AgentTokenKind::cases()));
    }

    public function test_a_selection_is_bound_to_one_owner_and_a_name_matches_one_token_unless_all(): void
    {
        $owner = (new User)->forceFill(['id' => 41, 'public_id' => '00000000-0000-4000-8000-000000000041']);
        $principal = (new AgentPrincipal)->forceFill(['id' => 42, 'public_id' => '00000000-0000-4000-8000-000000000042']);

        $token = AgentCredentialSelection::token($principal, 'token-id');
        $this->assertSame([42, '00000000-0000-4000-8000-000000000042', AgentCredentialScope::Token, 'token-id', null, null], [$token->ownerId, $token->ownerPublicId, $token->scope, $token->tokenId, $token->atMost, $token->storedName]);

        $one = AgentCredentialSelection::named($owner, AgentTokenKind::Operator, 'Support', false);
        $this->assertSame([41, AgentCredentialScope::Name, 1, 'operator-agent-token: Support', 'Support'], [$one->ownerId, $one->scope, $one->atMost, $one->storedName, $one->label]);
        $this->assertNull(AgentCredentialSelection::named($owner, AgentTokenKind::Operator, 'Support', true)->atMost);

        $all = AgentCredentialSelection::connections($owner);
        $this->assertSame([41, AgentCredentialScope::Connections, null, null, null], [$all->ownerId, $all->scope, $all->clientId, $all->atMost, $all->storedName]);
        $this->assertSame('client-id', AgentCredentialSelection::connections($owner, 'client-id')->clientId);
        $this->assertSame(['token', 'name', 'connections'], array_map(static fn (AgentCredentialScope $scope): string => $scope->value, AgentCredentialScope::cases()));
    }

    public function test_audit_values_name_credentials_by_fingerprint_and_a_dump_never_shows_the_bearer(): void
    {
        $this->assertSame(hash('sha256', '17'), CredentialFingerprint::of('17'));
        $this->assertSame(hash_hmac('sha256', 'personal_access_token:17', 'key'), CredentialFingerprint::keyed(17, 'key'));
        $this->assertNotSame(CredentialFingerprint::keyed(17, 'key'), CredentialFingerprint::keyed(17, 'other key'));
        $this->assertSame([hash('sha256', 'a'), hash('sha256', 'b')], CredentialFingerprint::all(['a', 'b']));
        $this->assertSame([], CredentialFingerprint::all([]));

        $issued = new IssuedAgentToken('row-id', 'bearer-value', CarbonImmutable::parse('2026-01-01T00:00:00Z'), 'Label', [AgentApiScopes::IDENTITY_READ], 'https://svc.example.test/api/v1');
        $this->assertSame(hash('sha256', 'row-id'), $issued->fingerprint());
        $dump = print_r($issued, true);
        $this->assertStringNotContainsString('bearer-value', $dump);
        $this->assertStringNotContainsString('row-id', $dump);
        $this->assertSame([
            'fingerprint' => hash('sha256', 'row-id'),
            'token' => '[redacted]',
            'expiresAt' => '2026-01-01T00:00:00+00:00',
            'label' => 'Label',
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'resource' => 'https://svc.example.test/api/v1',
        ], $issued->__debugInfo());

        $revocation = new AgentRevocation(['f1', 'f2'], 3, 4);
        $this->assertSame(2, $revocation->accessTokens());
        $this->assertSame(['access_tokens' => 2, 'refresh_tokens' => 3, 'authorization_codes' => 4], $revocation->counts());

        $ambiguous = new AmbiguousRevocation(3);
        $this->assertSame(3, $ambiguous->matched);
        $this->assertSame('3 live tokens share that name.', $ambiguous->getMessage());
    }

    /** @param  array<array-key, mixed>  $scopes */
    private function assertRefused(string $message, AgentTokenKind $kind, string $label, array $scopes, int $ttl): void
    {
        try {
            AgentTokenRequest::of($kind, $label, $scopes, $ttl);
        } catch (InvalidAgentTokenRequest $refused) {
            $this->assertSame($message, $refused->getMessage());

            return;
        }
        $this->fail('The request was not refused: '.json_encode([$label, $scopes, $ttl]));
    }
}
