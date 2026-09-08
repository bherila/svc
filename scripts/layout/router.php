<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Not an application login route. Only the harness starts this CLI server,
// bound to loopback, with a per-run secret and a disposable database.
$runtime = getenv('SVC_LAYOUT_RUNTIME');
$settings = is_string($runtime) && is_file($runtime.'/harness.json')
    ? json_decode(file_get_contents($runtime.'/harness.json'), true, flags: JSON_THROW_ON_ERROR)
    : null;

if (PHP_SAPI !== 'cli-server'
    || ! is_array($settings)
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== $settings['host']
    || ! hash_equals($settings['token'], $_SERVER['HTTP_X_SVC_LAYOUT_TOKEN'] ?? '')
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(403);
    exit('Layout harness access refused.');
}

$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($path) ? realpath($public.rawurldecode($path)) : false;
if ($file !== false && str_starts_with($file, $public.'/build/') && is_file($file)) {
    return false;
}

$app = require __DIR__.'/bootstrap.php';
$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
Auth::onceUsingId($fixture['user_id']);
$app->handleRequest(Request::capture());
