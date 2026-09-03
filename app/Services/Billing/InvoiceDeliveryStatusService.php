<?php

namespace App\Services\Billing;

use App\Models\ClientInvoiceEmailDelivery;
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
     * Returns false for an event we cannot place - an unknown message id, an
     * event type we do not track, a body missing either. That is not an error:
     * this endpoint receives everything the provider sends about every message
     * the account has ever mailed, and most of it is about something else.
     *
     * @param  array<mixed>  $event
     */
    public function record(array $event): bool
    {
        $reference = $this->stringFrom($event, ['message-id', 'message_id', 'messageId']);
        $type = strtolower(trim((string) ($event['event'] ?? '')));

        if ($reference === null || ! array_key_exists($type, self::SEVERITY)) {
            return false;
        }

        $delivery = ClientInvoiceEmailDelivery::query()
            ->where('provider_message_reference', $reference)
            ->first();

        if (! $delivery instanceof ClientInvoiceEmailDelivery) {
            return false;
        }

        $known = $delivery->provider_status;

        if ($known !== null && (self::SEVERITY[$known] ?? 0) > self::SEVERITY[$type]) {
            return false;
        }

        $delivery->forceFill([
            'provider_status' => $type,
            'provider_status_at' => $this->eventTime($event) ?? $this->clock->now($delivery->workspace),
        ])->save();

        return true;
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
