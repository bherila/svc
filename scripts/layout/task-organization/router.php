<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Loopback + per-run Host/token checks precede assets, bootstrap and any writes.
$runtime = getenv('SVC_LAYOUT_RUNTIME');
$settings = is_string($runtime) && is_file($runtime.'/harness.json')
    ? json_decode(file_get_contents($runtime.'/harness.json'), true, flags: JSON_THROW_ON_ERROR) : null;
if (PHP_SAPI !== 'cli-server' || ! is_array($settings)
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== $settings['host']
    || ! hash_equals($settings['token'], $_SERVER['HTTP_X_SVC_LAYOUT_TOKEN'] ?? '')) {
    http_response_code(403);
    exit('Task organization harness access refused.');
}
$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$public = dirname(__DIR__, 3).'/public';
$file = is_string($path) ? realpath($public.rawurldecode($path)) : false;
if ($method === 'GET' && $file !== false && str_starts_with($file, $public.'/build/') && is_file($file)) {
    return false;
}
$pagePaths = array_map(static fn (string $url): string => parse_url($url, PHP_URL_PATH), $fixture['pages']);
$pagePaths[] = parse_url($fixture['advisory_task_page'], PHP_URL_PATH);
$pagePaths[] = parse_url($fixture['advisory_assignees_page'], PHP_URL_PATH);
if (($method === 'GET' && ! in_array($path, $pagePaths, true))
    || ($method !== 'GET' && ! in_array($path, $fixture['writes'][$method] ?? [], true))) {
    http_response_code(403);
    exit('Task organization harness route refused.');
}
$app = require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/routes.php';
Auth::onceUsingId($fixture['user_id']);
$app->handleRequest(Request::capture());
