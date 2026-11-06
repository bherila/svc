<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeGateway;
use App\Services\Billing\StripeWebhookService;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\StripeEventRoute;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Payments\PaymentProcessor;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * `/api/webhooks/stripe/{endpoint}` is one workspace's own endpoint: the
     * opaque token picks the connection, and the signature is checked with that
     * connection's secret and no other. An unknown token or a bad signature is
     * a 4xx and nothing is applied. `/api/webhooks/stripe` is the legacy
     * single-account endpoint, served from the environment until the migration
     * command has run and from the migrated connection afterwards.
     */
    public function __invoke(Request $request, StripeGateway $gateway, StripeWebhookService $service, PaymentConnectionResolver $resolver, WorkspacePaymentConnections $connections, ?string $endpoint = null): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $route = null;
        try {
            if ($endpoint !== null) {
                $connection = $connections->activeByEndpoint(PaymentProcessor::Stripe, $endpoint);
                $secret = $connection?->webhookSecret();
                if ($connection === null || $secret === null) {
                    return response()->json(['message' => 'Unknown Stripe webhook endpoint.'], 404);
                }
                // This must remain the first operation that can lead to persistence.
                $event = $gateway->constructWebhookEventWith($payload, $signature, $secret);
                $route = $resolver->stripeRoute($connection);
            } elseif ($connections->environmentMigrated(PaymentProcessor::Stripe)) {
                $connection = $connections->environmentConnection(PaymentProcessor::Stripe);
                $secret = $connection?->webhookSecret();
                if ($connection === null || $secret === null) {
                    return response()->json(['message' => 'Unknown Stripe webhook endpoint.'], 404);
                }
                $event = $gateway->constructWebhookEventWith($payload, $signature, $secret);
                $route = $resolver->stripeRoute($connection);
            } else {
                $event = $gateway->constructWebhookEvent($payload, $signature);
                $route = $resolver->legacyStripeRoute();
            }
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        }

        return $this->apply($service, $event, $payload, $route);
    }

    private function apply(StripeWebhookService $service, Event $event, string $payload, StripeEventRoute $route): JsonResponse
    {
        try {
            $processed = $service->process($event, $payload, $route);
        } catch (DomainException) {
            return response()->json(['message' => 'Conflicting Stripe webhook event.'], 409);
        } catch (StripeEventMustBeRetried) {
            return response()->json(['message' => 'Stripe webhook event must be retried.'], 503);
        }

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
