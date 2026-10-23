<?php

declare(strict_types=1);

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Credentials\AgentTokenIssuer;
use App\Services\Credentials\AgentTokenKind;
use App\Services\Credentials\AgentTokenRequest;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Contracts\Console\Kernel;

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

/**
 * Through the one agent token issuer, like the deployment smoke's; `mcp:use`
 * binds each to the MCP resource, and the MCP endpoint refuses a REST token.
 */
$issue = static fn (string $label, array $scopes): string => app(AgentTokenIssuer::class)
    ->issue($user, AgentTokenRequest::of(AgentTokenKind::CiSmoke, $label, $scopes, AgentTokenRequest::MAX_TTL_MINUTES))
    ->token();

echo json_encode([
    'token' => $issue('authorized', [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::IDENTITY_READ,
        AgentApiScopes::BILLING_READ,
    ]),
    // A connection carrying a different operation scope, so this lane exercises
    // the scope and session-isolation assertions too - a check that only ever
    // runs post-deploy is a check whose failures are expensive. It holds
    // `projects:read` rather than nothing so the refusal proves operation
    // scopes are independent instead of relying only on an empty discovery
    // surface.
    'wrong_scope_token' => $issue('wrong scope', [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::PROJECTS_READ,
    ]),
    'workspace_id' => $workspace->public_id,
    'agreement_id' => $agreement->public_id,
], JSON_THROW_ON_ERROR);
