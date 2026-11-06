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
$expenseWrite = is_string($requestPath) && (
    ($method === 'POST' && $requestPath === ($fixture['expense_api_store'] ?? null))
    || (is_string($fixture['expense_api_prefix'] ?? null)
        && preg_match('#^'.preg_quote($fixture['expense_api_prefix'], '#').'[0-9a-f-]{36}(/approve|/unapprove)?$#D', $requestPath) === 1
        && (($method === 'POST' && (str_ends_with($requestPath, '/approve') || str_ends_with($requestPath, '/unapprove')))
            || (in_array($method, ['PATCH', 'DELETE'], true) && ! str_ends_with($requestPath, '/approve') && ! str_ends_with($requestPath, '/unapprove'))))
);
$draftWrite = in_array($requestPath, $fixture['draft_writes'][$method] ?? [], true);
if ($draftWrite && $method === 'POST' && $requestPath === '/api/v1/workspaces/'.($fixture['workspace_public_id'] ?? '').'/payments') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $draftWrite = is_array($body) && ($body['invoice_id'] ?? null) === $fixture['draft_public_id'];
}
$buydownWrite = $method === 'POST' && is_string($fixture['buydown_create'] ?? null) && $requestPath === $fixture['buydown_create'];
$taskWrite = ($method === 'POST' && $requestPath === ($fixture['task_create'] ?? null))
    || ($method === 'PATCH' && $requestPath === ($fixture['task_update'] ?? null));
$projectWrite = in_array($requestPath, $fixture['project_writes'][$method] ?? [], true);
$workspaceWrite = $method === 'POST' && $requestPath === ($fixture['workspace_create'] ?? null);
$clientWrite = getenv('SVC_LAYOUT_CLIENTS') === '1' && (
    ($method === 'POST' && $requestPath === ($fixture['contact_restore'] ?? null))
    || ($method === 'PATCH' && $requestPath === ($fixture['client_profile_update'] ?? null))
);
$receiptWrite = getenv('SVC_LAYOUT_RECEIPTS') === '1' && is_string($requestPath) && (
    ($method === 'POST' && $requestPath === ($fixture['receipt_upload'] ?? null))
    || ($method === 'DELETE' && isset($fixture['receipt_prefix']) && preg_match('#^'.preg_quote($fixture['receipt_prefix'], '#').'[0-9a-f-]{36}$#D', $requestPath) === 1)
);
// The payments page's validation refusal: a POST of a key that is not a Stripe
// key is refused before any processor is asked, so it can reach the real route.
$paymentsWrite = getenv('SVC_LAYOUT_PAYMENTS') === '1' && $method === 'POST' && is_string($requestPath)
    && preg_match('#^/workspaces/[0-9a-f-]{36}/payments/stripe$#D', $requestPath) === 1
    && is_array($paymentsBody = json_decode((string) file_get_contents('php://input'), true))
    && is_string($paymentsBody['secret'] ?? null)
    && preg_match('/^(?:rk|sk)_/', $paymentsBody['secret']) !== 1;
// The MCP setup page's "Show all tools" switches: a PUT of one seeded credential's
// own setting, which the check changes and puts back (--credentials).
$credentialWrite = getenv('SVC_LAYOUT_CREDENTIALS') === '1' && $method === 'PUT' && is_string($requestPath)
    && preg_match('#^/account/mcp-credentials/(connections|tokens)/[0-9A-Za-z-]{36,80}$#D', $requestPath) === 1;
if ($method !== 'GET' && ! $credentialWrite && ! $paymentsWrite && ! $projectWrite && ! $workspaceWrite && ! $receiptWrite && ! $scheduleWrite && ! $expenseWrite && ! $draftWrite && ! $buydownWrite && ! $taskWrite && ! $clientWrite) {
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
Auth::onceUsingId(match (true) {
    // The member who is not a manager reads My work as themselves.
    isset($fixture['my_work']) && $requestPath === $fixture['my_work'] => $fixture['work_member_id'],
    // A participant reads the shared project from their own workspace.
    isset($fixture['shared_participant']) && $requestPath === $fixture['shared_participant'] => $fixture['shared_participant_user_id'],
    // The invitee reads the invitation addressed to them.
    isset($fixture['shared_invitation']) && $requestPath === $fixture['shared_invitation'] => $fixture['shared_invitee_user_id'],
    // The client contact reads the portal as the client.
    in_array($requestPath, array_filter([$fixture['square_portal'], $fixture['task_portal'], $fixture['portal_home'] ?? null, $fixture['document_portal'] ?? null]), true) => $fixture['square_portal_user_id'],
    default => $fixture['user_id'],
});
$app->handleRequest(Request::capture());
