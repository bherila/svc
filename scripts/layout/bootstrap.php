<?php

use Illuminate\Contracts\Console\Kernel;

// This bootstrap is only used by the disposable browser harness, never routes/.
$runtime = getenv('SVC_LAYOUT_RUNTIME');
if (! is_string($runtime) || ! is_file($runtime.'/harness.json')) {
    throw new RuntimeException('Run pnpm test:layout to create an isolated harness.');
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($runtime);
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')
    || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $runtime.'/database.sqlite') {
    throw new RuntimeException('The layout harness requires its own testing SQLite database.');
}

config(['inertia.ssr.enabled' => false]);

return $app;
