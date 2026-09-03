<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\InvoiceDeliveryStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What Brevo says became of an invoice email.
 *
 * Our own `status` column can only ever record that we handed the message to
 * the provider. Whether it was delivered, bounced, blocked or landed in a spam
 * folder is a fact only Brevo learns, seconds or hours later, and it tells us
 * over this endpoint. Keeping the two apart is deliberate: an operator who
 * reads "sent" as "received" will chase a client who never got the invoice.
 *
 * ## Why a shared token, and why it fails closed
 *
 * Brevo does not sign its webhooks - there is no equivalent of Stripe's
 * signature header to verify - so the only thing separating this from the open
 * internet is a secret the caller has to present. It is compared with
 * `hash_equals`, and when no token is configured every request is refused
 * rather than admitted: an unconfigured secret is the state a fresh deployment
 * is in, and defaulting that to "let anyone write delivery statuses" is how a
 * safety measure becomes a formality.
 *
 * The token is never inferred from the body. A payload that names a message id
 * is not evidence of anything; anyone who can read an email header can produce
 * one.
 */
class BrevoWebhookController extends Controller
{
    public function __invoke(Request $request, InvoiceDeliveryStatusService $statuses): JsonResponse
    {
        $expected = config('services.brevo.webhook_token');
        $presented = $request->header('X-Webhook-Token') ?? $request->query('token');

        if (! is_string($expected) || $expected === ''
            || ! is_string($presented)
            || ! hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Unrecognised webhook caller.'], 401);
        }

        // Decoded here rather than read through the request's typed helpers,
        // because both shapes have to survive: Brevo posts a single event as an
        // object and a batch as a list, and which one arrives depends on
        // settings in someone else's console.
        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Unreadable webhook body.'], 400);
        }

        $events = array_is_list($payload) ? $payload : [$payload];

        $recorded = 0;

        foreach ($events as $event) {
            if (is_array($event) && $statuses->record($event)) {
                $recorded++;
            }
        }

        // 200 even for an event that matched nothing. A message id we do not
        // recognise is not an error the provider can act on, and answering with
        // a failure only makes them retry it until they give up.
        return response()->json(['received' => count($events), 'recorded' => $recorded]);
    }
}
