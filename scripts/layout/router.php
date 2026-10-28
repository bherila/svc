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
) {
    http_response_code(403);
    exit('Layout harness access refused.');
}

$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
// Only allowlisted schedule and draft-time acceptance actions may mutate this
// run's disposable database. Other writes remain refused, even with the token.
$scheduleWrite = is_string($requestPath) && (
    ($method === 'POST' && $requestPath === $fixture['expense_schedule_store'])
    || (preg_match('#^'.preg_quote($fixture['expense_schedule_prefix'], '#').'[0-9a-f-]{36}(/generate)?$#D', $requestPath) === 1
        && (($method === 'PATCH' && ! str_ends_with($requestPath, '/generate'))
            || ($method === 'POST' && str_ends_with($requestPath, '/generate'))))
);
$draftWrite = in_array($requestPath, $fixture['draft_writes'][$method] ?? [], true);
if ($draftWrite && $method === 'POST' && $requestPath === '/api/v1/workspaces/'.($fixture['workspace_public_id'] ?? '').'/payments') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $draftWrite = is_array($body) && ($body['invoice_id'] ?? null) === $fixture['draft_public_id'];
}
$buydownWrite = $method === 'POST' && is_string($fixture['buydown_create'] ?? null) && $requestPath === $fixture['buydown_create'];
$taskWrite = ($method === 'POST' && $requestPath === ($fixture['task_create'] ?? null))
    || ($method === 'PATCH' && $requestPath === ($fixture['task_update'] ?? null));
$workspaceWrite = $method === 'POST' && $requestPath === ($fixture['workspace_create'] ?? null);
$clientWrite = getenv('SVC_LAYOUT_CLIENTS') === '1' && $method === 'POST' && $requestPath === ($fixture['contact_restore'] ?? null);
if ($method !== 'GET' && ! $workspaceWrite && ! $scheduleWrite && ! $draftWrite && ! $buydownWrite && ! $taskWrite && ! $clientWrite) {
    http_response_code(403);
    exit('Layout harness write refused.');
}

$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($path) ? realpath($public.rawurldecode($path)) : false;
if ($file !== false && str_starts_with($file, $public.'/build/') && is_file($file)) {
    return false;
}

$app = require __DIR__.'/bootstrap.php';
Auth::onceUsingId(in_array($requestPath, [$fixture['square_portal'], $fixture['task_portal']], true) ? $fixture['square_portal_user_id'] : $fixture['user_id']);
$app->handleRequest(Request::capture());
