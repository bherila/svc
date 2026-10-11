<?php

use App\Models\User;
use App\Services\Access\DelegatedStaffAccess;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config([
    'database.connections.race' => $input['connection'], 'database.default' => 'race',
    'bherila-auth.oauth_client.provider' => 'example-provider',
]);
DB::purge('race');
if (in_array($input['collision'], ['pending', 'claimed-email'], true)) {
    app(DelegatedStaffAccess::class)->handle('manager-subject', [
        'operation' => 'update', 'subject' => 'new-subject', 'expected_revision' => null,
        'access' => ['application_admin' => false, 'workspaces' => [['id' => $input['workspace'], 'role' => 'member']]],
        'display_name' => 'Pending synthetic person', 'operation_id' => DelegatedContract::operationId(),
    ]);
    if ($input['collision'] === 'claimed-email') {
        User::factory()->create(['email' => 'person@example.test']);
    }
} else {
    User::factory()->create([
        'oauth_provider' => 'example-provider', 'oauth_subject' => 'new-subject',
        'name' => 'Synthetic Person',
        'email' => $input['collision'] === 'matching' ? 'person@example.test' : 'different@example.test',
    ]);
}
echo "committed\n";
