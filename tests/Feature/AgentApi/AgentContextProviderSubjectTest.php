<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Support\AgentApi\AgentApiContract;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\IssuesAgentOAuthTokens;
use Tests\TestCase;

/**
 * `context.get` names the caller's identity-provider subject (S5), so a
 * connecting application can check that the account it reached is the person
 * who connected. Only the authorized caller's own context carries it, and only
 * for a binding to the trusted provider.
 */
final class AgentContextProviderSubjectTest extends TestCase
{
    use CallsMcp;
    use IssuesAgentOAuthTokens;
    use RefreshDatabase;

    private const string PROVIDER = 'synthetic-provider';

    protected function setUp(): void
    {
        parent::setUp();
        config(['bherila-auth.oauth_client.provider' => self::PROVIDER]);
    }

    public function test_a_connection_reads_its_own_provider_subject_through_the_real_oauth_flow(): void
    {
        $user = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => '4711']);
        $tokens = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ]);

        $context = $this->withToken($tokens['access_token'])->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.provider_subject', '4711')
            ->assertJsonPath('data.contract_version', 2);
        $this->assertSame(2, AgentApiContract::VERSION);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($context->json(), AgentApiResponseSchemaCatalog::forOperation('context.get')));
    }

    public function test_the_real_oauth_mcp_context_publishes_the_same_contract_and_preserves_its_own_provider_subject(): void
    {
        $user = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => '4711']);
        $tokens = $this->issueAgentTokens($user, 'mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);
        $this->withToken($tokens['access_token']);
        $context = $this->callTool($this->initialize(), 'context.get', []);

        $this->assertArrayNotHasKey('error', $context);
        $body = $context['result']['structuredContent'];
        $this->assertSame(2, $body['data']['contract_version'] ?? null);
        $this->assertSame($user->public_id, $body['data']['id']);
        $this->assertSame('4711', $body['data']['provider_subject']);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation('context.get')));
    }

    /** @return array<string, array{bool, mixed}> */
    public static function invalidContractVersions(): array
    {
        return [
            'missing' => [false, null],
            'null' => [true, null],
            'numeric string' => [true, '1'],
            'boolean' => [true, true],
            'fraction' => [true, 1.5],
            'zero' => [true, 0],
            'unsupported integer' => [true, 1],
        ];
    }

    #[DataProvider('invalidContractVersions')]
    public function test_the_canonical_context_requires_the_exact_supported_integer_contract(bool $present, mixed $version): void
    {
        $context = ['id' => '0192f0e4-7b3a-7c3e-9a51-3f1d2b6c4a01', 'name' => 'Synthetic person', 'provider_subject' => null,
            'capabilities' => [], 'withheld_tools' => [], 'workspaces' => [], 'contract_version' => 2];
        $schema = AgentApiResponseSchemaCatalog::schema('Context');
        $validator = new SchemaValidator;
        $this->assertSame([], $validator->validateAgainstJsonSchema($context, $schema));
        unset($context['contract_version']);
        if ($present) {
            $context['contract_version'] = $version;
        }

        $this->assertNotSame([], $validator->validateAgainstJsonSchema($context, $schema));
    }

    public function test_an_unbound_or_foreign_binding_has_no_provider_subject(): void
    {
        $unbound = User::factory()->create(['oauth_provider' => null, 'oauth_subject' => null]);
        $this->actingAsMcp($unbound, [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.provider_subject', null);

        // Bound to some other provider: not a subject the trusted provider
        // vouches for, so none is named.
        $foreign = User::factory()->create(['oauth_provider' => 'another-provider', 'oauth_subject' => '4711']);
        $this->actingAsMcp($foreign, [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.provider_subject', null);
    }

    public function test_context_without_identity_read_or_a_credential_names_no_subject(): void
    {
        $user = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => '4711']);

        $anonymous = $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->assertStringNotContainsString('4711', (string) $anonymous->getContent());

        $this->actingAsMcp($user, [AgentApiScopes::BILLING_READ]);
        $refused = $this->getJson('/api/v1/context')->assertForbidden();
        $this->assertStringNotContainsString('4711', (string) $refused->getContent());
    }
}
