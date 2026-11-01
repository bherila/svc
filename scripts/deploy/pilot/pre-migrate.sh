#!/usr/bin/env bash
# Immediately before migrations, prove from the candidate's own resolved Laravel configuration that
# the default database connection is the pilot's local server. Key checks elsewhere are early
# refusals; this is the authoritative one, because it also sees the host process environment,
# duplicate assignments, environment-specific files and anything the application changes at boot.
set -euo pipefail
[[ $# -eq 3 ]] || exit 2
candidate=$1
php=$2
stable=$3
[[ $stable =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $stable != *..* ]] || exit 2
[[ $candidate = ".deployments/$stable/releases/"* && $candidate != *..* ]] || exit 2
[[ $php = /* && -x $php ]] || exit 1
cd "$HOME/$candidate"
# Read-only. The action clears the config cache and then migrates, so a cached configuration would
# not be what migrations use; nothing caches one before this hook, and one present is refused.
# shellcheck disable=SC2016 # PHP variable names must remain literal.
"$php" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
$failed = ["configuration"];
try {
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    if ($app->configurationIsCached()) { throw new RuntimeException(); }
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $config = $app->make("config");
    $default = $config->get("database.default");
    $connection = is_string($default) ? $config->get("database.connections.".$default) : null;
    $empty = static fn (mixed $value): bool => $value === null || $value === "";
    $failed = array_keys(array_filter([
        "database.default" => $default !== "mariadb",
        "connection" => !is_array($connection),
        "driver" => ($connection["driver"] ?? null) !== "mariadb",
        "url" => !$empty($connection["url"] ?? null),
        "host" => ($connection["host"] ?? null) !== "127.0.0.1",
        "port" => !in_array($connection["port"] ?? null, ["3306", 3306], true),
        "unix_socket" => !$empty($connection["unix_socket"] ?? null),
        "read/write hosts" => isset($connection["read"]) || isset($connection["write"]),
    ]));
} catch (Throwable $error) {
    // Bootstrap errors may contain private values; never relay them.
}
if ($failed !== []) {
    fwrite(STDERR, "Resolved pilot database is not the local server; refused: ".implode(", ", $failed).".\n");
    exit(1);
}
echo "Resolved pilot database is the local server.\n";
'
