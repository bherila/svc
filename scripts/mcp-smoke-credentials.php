<?php

declare(strict_types=1);

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Refusing to issue MCP smoke credentials in production.\n");
    exit(1);
}

$user = User::factory()->create([
    'name' => 'MCP smoke test',
    'email' => 'mcp-smoke@example.test',
]);
$workspace = Workspace::query()->create([
    'name' => 'MCP smoke workspace',
    'slug' => 'mcp-smoke-workspace',
]);
WorkspaceMembership::query()->create([
    'workspace_id' => $workspace->id,
    'user_id' => $user->id,
    'role' => 'admin',
]);
$company = ClientCompany::query()->create([
    'workspace_id' => $workspace->id,
    'name' => 'MCP smoke client',
    'slug' => 'mcp-smoke-client',
]);
$project = ClientProject::query()->create([
    'workspace_id' => $workspace->id,
    'client_company_id' => $company->id,
    'name' => 'MCP smoke project',
]);
$agreement = ClientAgreement::query()->create([
    'workspace_id' => $workspace->id,
    'client_company_id' => $company->id,
    'client_project_id' => $project->id,
    'title' => 'MCP smoke agreement',
    'status' => 'active',
    'starts_on' => '2026-01-01',
    'currency' => 'USD',
    'billing_cadence' => 'monthly',
]);

$client = app(ClientRepository::class)->createPersonalAccessGrantClient('SVC MCP smoke', 'agent-principals');
$principal = AgentPrincipal::query()->findOrFail($user->id);

/** Without the resource indicator the token is not addressed to this API. */
$issue = static function (string $name, array $scopes) use ($principal, $client): string {
    $issued = $principal->createToken($name, $scopes);
    Passport::token()->newQuery()
        ->whereKey($issued->accessTokenId)
        ->where('client_id', $client->id)
        ->update(['resource_uri' => OAuthResourceIndicator::resource()]);

    return $issued->accessToken;
};

echo json_encode([
    'token' => $issue('SVC MCP smoke', [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::IDENTITY_READ,
        AgentApiScopes::BILLING_READ,
    ]),
    // A connection carrying a different operation scope, so this lane exercises
    // the scope and session-isolation assertions too - a check that only ever
    // runs post-deploy is a check whose failures are expensive. It holds
    // `projects:read` rather than nothing because a connection authorized for
    // nothing cannot complete the handshake at all; see
    // DeploySmokeCredentialsCommand.
    'wrong_scope_token' => $issue('SVC MCP smoke wrong scope', [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::PROJECTS_READ,
    ]),
    'workspace_id' => $workspace->public_id,
    'agreement_id' => $agreement->public_id,
], JSON_THROW_ON_ERROR);
