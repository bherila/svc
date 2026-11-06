<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareRequestFailed;
use App\Services\Square\SquareSettlementSync;
use App\Services\Square\SquareWebhookReceipts;
use App\Support\Payments\PaymentProcessor;
use App\Support\Square\SquareIdentifiers;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/** A signed hint, processed in this request; the event's financial fields are ignored. */
final class SquareWebhookController
{
    /**
     * A dispute's hint is its own id. `dispute.state.changed` is the older
     * name of `dispute.state.updated`; either may be subscribed.
     */
    private const array DISPUTE_EVENTS = ['dispute.created', 'dispute.state.updated', 'dispute.state.changed'];

    /** A payout's hint is its own id (S7); the payout is read from the Payouts API. */
    private const array PAYOUT_EVENTS = ['payout.sent', 'payout.paid', 'payout.failed'];

    /**
     * `/api/webhooks/square/{endpoint}` is one workspace's own endpoint: the
     * opaque token picks the connection, and the signature is checked with that
     * connection's signature key over that exact URL. `/api/webhooks/square` is
     * the legacy single-account endpoint, served from the environment until the
     * migration command has run and from the migrated connection afterwards.
     */
    public function __invoke(Request $request, SquarePaymentReconciler $reconciler, SquareSettlementSync $settlements, SquareWebhookReceipts $receipts, WorkspacePaymentConnections $connections, ?string $endpoint = null): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > 1_048_576) {
            return response()->json(['message' => 'Webhook body is too large.'], 413);
        }
        $base = rtrim((string) config('app.url'), '/').'/api/webhooks/square';
        $connection = null;
        if ($endpoint !== null) {
            $connection = $connections->activeByEndpoint(PaymentProcessor::Square, $endpoint);
            $key = $connection?->webhookSecret();
            if ($connection === null || $key === null) {
                return response()->json(['message' => 'Unknown Square webhook endpoint.'], 404);
            }
            $url = $base.'/'.$endpoint;
        } elseif ($connections->environmentMigrated(PaymentProcessor::Square)) {
            $connection = $connections->environmentConnection(PaymentProcessor::Square);
            $key = $connection?->webhookSecret();
            if ($connection === null || $key === null) {
                return response()->json(['message' => 'Unknown Square webhook endpoint.'], 404);
            }
            $url = $base;
        } else {
            $key = config('services.square.webhook_signature_key');
            $url = $base;
        }
        if (! is_string($key) || trim($key) === '' || ! hash_equals(base64_encode(hash_hmac('sha256', $url.$raw, $key, true)), (string) $request->header('x-square-hmacsha256-signature'))) {
            return response()->json(['message' => 'Webhook signature refused.'], 403);
        }
        try {
            $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid webhook envelope.'], 400);
        }
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid webhook envelope.'], 400);
        }
        $type = $event['type'] ?? null;
        if (! in_array($type, ['invoice.updated', 'invoice.payment_made', 'payment.updated', 'refund.updated', ...self::DISPUTE_EVENTS, ...self::PAYOUT_EVENTS], true)) {
            return response()->json(['received' => true]);
        }
        $id = $event['event_id'] ?? null;
        $hint = $type === 'refund.updated' ? data_get($event, 'data.object.refund.payment_id') : data_get($event, 'data.id');
        if (! is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,254}$/D', $id) !== 1
            || ! is_string($hint) || preg_match(str_starts_with($type, 'invoice.') ? SquareIdentifiers::INVOICE_ID_PATTERN : SquareClient::ID_PATTERN, $hint) !== 1) {
            return response()->json(['message' => 'Invalid webhook envelope.'], 400);
        }
        // The workspaces this endpoint serves: its own connection's workspace
        // and those that borrow it - or, on the unmigrated legacy path, the
        // environment's bound workspace.
        if ($connection !== null) {
            $merchant = $event['merchant_id'] ?? null;
            if (is_string($merchant) && $merchant !== $connection->external_account_id) {
                Log::warning('Square webhook event names a merchant this connection is not for; ignored.', ['event' => $id, 'type' => $type]);

                return response()->json(['received' => true, 'ignored' => true]);
            }
            $workspaces = Workspace::query()->whereIn('id', [
                $connection->workspace_id,
                ...$connections->dependents($connection)->pluck('workspace_id')->all(),
            ])->orderBy('id')->get()->all();
        } else {
            $legacy = SquareConfiguration::legacy();
            $workspace = $legacy->isConfigured() ? $legacy->workspace() : null;
            $workspaces = $workspace === null ? [] : [$workspace];
        }
        if ($workspaces === []) {
            return response()->json(['message' => 'Webhook connection is disabled.'], 404);
        }
        // Each workspace reconciles against its own links only: an event for a
        // record this workspace does not hold changes nothing.
        foreach ($workspaces as $workspace) {
            $claim = $receipts->claim($workspace, $id, $type);
            if ($claim === false) {
                return response()->json(['message' => 'Webhook reconciliation is in progress.'], 409);
            }
            if ($claim === null) {
                continue;
            }
            try {
                if (str_starts_with($type, 'invoice.')) {
                    $reconciler->invoice($workspace, $hint);
                } elseif (in_array($type, self::DISPUTE_EVENTS, true)) {
                    $reconciler->dispute($workspace, $hint);
                } elseif (in_array($type, self::PAYOUT_EVENTS, true)) {
                    $settlements->payout($workspace, $hint);
                } else {
                    $reconciler->payment($workspace, $hint);
                }
                $receipts->finish($workspace, $id, $claim, true);
            } catch (SquareRequestFailed|DomainException) {
                $receipts->finish($workspace, $id, $claim, false);

                return response()->json(['message' => 'Webhook reconciliation must be retried.'], 503);
            } catch (Throwable $exception) {
                $receipts->finish($workspace, $id, $claim, false);
                throw $exception;
            }
        }

        return response()->json(['received' => true]);
    }
}
