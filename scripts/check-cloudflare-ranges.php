<?php

/**
 * Fail when config/proxies.php no longer matches Cloudflare's published ranges.
 *
 * A stale list fails quietly: callers arriving through a new edge are keyed on
 * that edge's address and share one rate-limit budget again. Run weekly by the
 * cloudflare-ranges workflow; safe to run by hand.
 */
// Only env() is needed from the framework, so this runs without vendor/.
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
$config = require __DIR__.'/../config/proxies.php';
$pinned = $config['cloudflare'];

$published = [];
foreach (['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'] as $url) {
    $body = @file_get_contents($url);
    if ($body === false) {
        fwrite(STDERR, "Could not fetch {$url}\n");
        exit(2);
    }
    foreach (preg_split('/\s+/', trim($body)) ?: [] as $range) {
        if ($range !== '') {
            $published[] = $range;
        }
    }
}

sort($pinned);
sort($published);
$missing = array_values(array_diff($published, $pinned));
$retired = array_values(array_diff($pinned, $published));
if ($missing === [] && $retired === []) {
    echo 'Cloudflare ranges match config/proxies.php ('.count($pinned)." ranges).\n";
    exit(0);
}

echo "config/proxies.php is out of date with Cloudflare's published ranges.\n";
foreach ($missing as $range) {
    echo "  add:    {$range}\n";
}
foreach ($retired as $range) {
    echo "  remove: {$range}\n";
}
exit(1);
