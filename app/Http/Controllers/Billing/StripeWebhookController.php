<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeGateway;
use App\Services\Billing\StripeWebhookService;
use App\Support\Billing\StripeEventMustBeRetried;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeGateway $gateway, StripeWebhookService $service): JsonResponse
    {
        $payload = $request->getContent();
        try {
            // This must remain the first operation that can lead to persistence.
            $event = $gateway->constructWebhookEvent($payload, $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        }

        try {
            $processed = $service->process($event, $payload);
        } catch (DomainException) {
            return response()->json(['message' => 'Conflicting Stripe webhook event.'], 409);
        } catch (StripeEventMustBeRetried) {
            return response()->json(['message' => 'Stripe webhook event must be retried.'], 503);
        }

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
