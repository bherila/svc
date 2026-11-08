<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * The outcome of one webhook check: what was found and which required events
 * the endpoint lacks. Immutable and free of secrets.
 */
final readonly class WebhookHealth
{
    /**
     * @param  list<string>  $missing  required event types the endpoint is not subscribed to, in the required list's order
     * @param  string|null  $endpointId  the endpoint judged, when one was found
     * @param  list<string>  $subscribed  that endpoint's enabled events, kept in memory for a fix and never stored
     */
    public function __construct(
        public WebhookHealthStatus $status,
        public array $missing = [],
        public ?string $endpointId = null,
        public array $subscribed = [],
    ) {}
}
