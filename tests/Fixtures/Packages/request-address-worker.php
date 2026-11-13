<?php

use App\Exceptions\ServicePackageRefused;
use App\Models\ServicePackage;
use App\Models\Workspace;
use App\Services\Packages\PackageRequestWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * One public submission in its own MariaDB session, for
 * PackageRequestAddressConcurrencyTest. With `hold`, it stops once it holds the
 * address's lock until the test writes "release".
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.default' => 'request_address_probe', 'database.connections.request_address_probe' => $input['connection'], 'mail.default' => 'array']);
$workspace = Workspace::query()->findOrFail($input['workspace']);
$package = ServicePackage::query()->where('workspace_id', $workspace->id)->findOrFail($input['package']);
$connectionId = DB::connection()->getPdo()->query('select connection_id()')->fetchColumn();
echo "connection:{$connectionId}\n";
DB::connection()->beforeExecuting(function (string $sql): void {
    if (str_contains($sql, 'service_package_request_addresses')) {
        echo "attempting-lock\n";
        flush();
    }
});
DB::listen(function ($query) use ($input): void {
    if ($input['hold'] && str_contains($query->sql, 'service_package_request_addresses') && str_contains(strtolower($query->sql), 'for update')) {
        echo "locked\n";
        flush();
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Synthetic request probe not released.');
        }
    }
});
try {
    app(PackageRequestWorkflow::class)->submit($workspace, $package, [
        'company_name' => $input['company'],
        'contact_name' => 'Morgan Synthetic',
        'email' => 'morgan.lakeside@example.com',
        'answers' => ['website_address' => 'lakeside.example.com'],
    ], null, null);
    echo "sent\n";
} catch (ServicePackageRefused $refused) {
    echo $refused->refusalCode()."\n";
} catch (Throwable $exception) {
    echo $exception::class.': '.$exception->getMessage()."\n";
    exit(1);
}
