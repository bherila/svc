<?php

namespace App\Services\Billing;

use App\Models\ClientInvoiceEmailDelivery;
use App\Support\Billing\InvoiceDeliveryStatusOutcome;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;

/**
 * Recording what the mail provider reported about a message we sent.
 *
 * One delivery row can attract several events - queued, delivered, then opened
 * days later, or delivered and then a complaint. Only the ones that change what
 * an operator would do are kept, and a later event never overwrites a worse
 * earlier one: an invoice that bounced and was then marked "opened" by a
 * scanner did not arrive, and a row that says `opened` would say it did.
 */
final class InvoiceDeliveryStatusService
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /**
     * Brevo's event names, ranked by how much they should worry a reader.
     *
     * Ranking rather than last-write-wins, because these arrive out of order:
     * the provider retries, and a delivered event can land after the hard
     * bounce that followed it. The worst thing known about a message is the
     * thing worth showing.
     *
     * @var array<string, int>
     */
    private const SEVERITY = [
        'delivered' => 1,
        'opened' => 1,
        'unique_opened' => 1,
        'click' => 1,
        'deferred' => 2,
        'soft_bounce' => 3,
        'blocked' => 4,
        'spam' => 4,
        'invalid_email' => 4,
        'unsubscribed' => 4,
        'hard_bounce' => 5,
        'error' => 5,
    ];

    /**
     * Attach one provider event to the delivery it names.
     *
     * Returns a bounded outcome rather than provider data so the transport can
     * report aggregate operational telemetry without logging message ids,
     * recipients, or webhook payloads.
     *
     * @param  array<mixed>  $event
     */
    public function record(array $event): InvoiceDeliveryStatusOutcome
    {
        $reference = $this->stringFrom($event, ['message-id', 'message_id', 'messageId']);
        // Through the same reader as the message id rather than cast: this body
        // is whatever the provider posted, so `$event['event']` can be an array
        // or a number as easily as a string, and casting one would be a warning
        // in production and a wrong answer here.
        $type = $this->stringFrom($event, ['event']);

        if ($reference === null || $type === null) {
            return InvoiceDeliveryStatusOutcome::Ignored;
        }

        $type = strtolower($type);

        if (! array_key_exists($type, self::SEVERITY)) {
            return InvoiceDeliveryStatusOutcome::Ignored;
        }

        // The provider gives us no workspace selector, so there is no honest
        // tenant scope to apply before this lookup. Refuse ambiguity instead:
        // a reference shared by two workspaces must never let one event choose
        // whichever tenant row the database happens to return first.
        $deliveries = ClientInvoiceEmailDelivery::query()
            ->where('provider_message_reference', $reference)
            ->limit(2)
            ->get();

        if ($deliveries->isEmpty()) {
            return InvoiceDeliveryStatusOutcome::Unmatched;
        }

        if ($deliveries->count() !== 1) {
            return InvoiceDeliveryStatusOutcome::Ambiguous;
        }

        /** @var ClientInvoiceEmailDelivery $delivery */
        $delivery = $deliveries->first();

        $known = $delivery->provider_status;

        if ($known !== null && (self::SEVERITY[$known] ?? 0) > self::SEVERITY[$type]) {
            return InvoiceDeliveryStatusOutcome::Superseded;
        }

        $delivery->forceFill([
            'provider_status' => $type,
            'provider_status_at' => $this->eventTime($event) ?? $this->clock->now($delivery->workspace),
        ])->save();

        return InvoiceDeliveryStatusOutcome::Recorded;
    }

    /**
     * The provider's own timestamp, when it sent one we can read.
     *
     * Preferred over our clock because the gap between the event happening and
     * this request arriving can be hours after a retry, and "bounced at" is
     * about the bounce rather than about when we heard.
     *
     * @param  array<mixed>  $event
     */
    private function eventTime(array $event): ?CarbonImmutable
    {
        $timestamp = $event['ts_event'] ?? $event['ts'] ?? null;

        if (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) {
            return CarbonImmutable::createFromTimestampUTC((int) $timestamp);
        }

        return null;
    }

    /**
     * @param  array<mixed>  $event
     * @param  list<string>  $keys
     */
    private function stringFrom(array $event, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $event[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
