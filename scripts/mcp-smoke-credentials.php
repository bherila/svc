<?php

declare(strict_types=1);

use App\Models\AgentPrincipal;
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

$client = app(ClientRepository::class)->createPersonalAccessGrantClient('SVC MCP smoke', 'agent-principals');
$issued = AgentPrincipal::query()->findOrFail($user->id)->createToken('SVC MCP smoke', [
    AgentApiScopes::MCP_USE,
    AgentApiScopes::IDENTITY_READ,
]);
Passport::token()->newQuery()
    ->whereKey($issued->accessTokenId)
    ->where('client_id', $client->id)
    ->update(['resource_uri' => OAuthResourceIndicator::resource()]);

echo json_encode(['token' => $issued->accessToken], JSON_THROW_ON_ERROR);
