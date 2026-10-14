<?php

use App\Models\Workspace;
use App\Services\Square\SquarePaymentReconciler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Disposable probe only. Every response is synthetic; unmatched HTTP is refused.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$decoded = base64_decode((string) ($argv[1] ?? ''), true);
if ($decoded === false) {
    exit(2);
}
$input = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
foreach (['ready', 'release'] as $key) {
    if (! is_string($input[$key]) || ! str_starts_with($input[$key], sys_get_temp_dir().'/svc-square-payment-race-')) {
        exit(2);
    }
}
config(['database.connections.square_payment_race' => $input['connection'], 'database.default' => 'square_payment_race', 'services.square' => $input['square']]);
DB::purge('square_payment_race');
Date::setTestNow('2030-02-02 12:00:00');
Http::preventStrayRequests();
Http::fake(static function (Request $request) use ($input) {
    $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
    if ($path === '/v2/payments/pay_fixture_1') {
        // Both syncs have fetched the same completed payment before either writes.
        $temporary = $input['ready'].'.tmp';
        if (file_put_contents($temporary, 'ready') === false || ! rename($temporary, $input['ready'])) {
            exit(3);
        }
        $deadline = microtime(true) + 25;
        while (! is_file($input['release']) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! is_file($input['release'])) {
            exit(3);
        }

        return Http::response(['payment' => $input['payment']]);
    }

    return match ($path) {
        '/v2/invoices/inv_fixture_1' => Http::response(['invoice' => $input['invoice']]),
        '/v2/orders/order_fixture_1' => Http::response(['order' => $input['order']]),
        default => Http::response([], 404),
    };
});
try {
    $workspace = Workspace::query()->findOrFail($input['workspace']);
    app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
    echo '{"outcome":"completed"}'."\n";
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'class' => $exception::class], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
