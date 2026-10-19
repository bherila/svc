<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareRequestFailed;
use App\Services\Square\SquareSettlementSync;
use App\Services\Square\SquareWebhookReceipts;
use App\Support\Square\SquareIdentifiers;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function __invoke(Request $request, SquarePaymentReconciler $reconciler, SquareSettlementSync $settlements, SquareWebhookReceipts $receipts): JsonResponse
    {
        $key = config('services.square.webhook_signature_key');
        $raw = $request->getContent();
        if (strlen($raw) > 1_048_576) {
            return response()->json(['message' => 'Webhook body is too large.'], 413);
        }
        $url = rtrim((string) config('app.url'), '/').'/api/webhooks/square';
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
        $configuration = SquareConfiguration::fromConfig();
        $workspace = $configuration->isConfigured() ? Workspace::query()->where('public_id', $configuration->workspacePublicId)->first() : null;
        if ($workspace === null) {
            return response()->json(['message' => 'Webhook connection is disabled.'], 404);
        }
        $claim = $receipts->claim($workspace, $id, $type);
        if ($claim === false) {
            return response()->json(['message' => 'Webhook reconciliation is in progress.'], 409);
        }
        if ($claim === null) {
            return response()->json(['received' => true]);
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

        return response()->json(['received' => true]);
    }
}
