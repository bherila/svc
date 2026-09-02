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
$issued = AgentPrincipal::query()->findOrFail($user->id)->createToken('SVC MCP smoke', [
    AgentApiScopes::MCP_USE,
    AgentApiScopes::IDENTITY_READ,
    AgentApiScopes::BILLING_READ,
]);
Passport::token()->newQuery()
    ->whereKey($issued->accessTokenId)
    ->where('client_id', $client->id)
    ->update(['resource_uri' => OAuthResourceIndicator::resource()]);

echo json_encode([
    'token' => $issued->accessToken,
    'workspace_id' => $workspace->public_id,
    'agreement_id' => $agreement->public_id,
], JSON_THROW_ON_ERROR);
