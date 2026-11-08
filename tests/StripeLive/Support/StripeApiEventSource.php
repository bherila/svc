<?php

namespace Tests\StripeLive\Support;

use Stripe\StripeClient;

/** Stripe's Events API, listing the types asked for since the run began. */
final class StripeApiEventSource implements StripeEventSource
{
    /** More than any one scenario produces; a busy account cannot make the suite page forever. */
    private const int MAX_EVENTS = 500;

    public function __construct(private readonly StripeClient $client) {}

    public function list(int $since, array $types): array
    {
        $events = [];
        foreach (array_chunk($types, 20) as $chunk) {
            $page = $this->client->events->all(['types' => $chunk, 'created' => ['gte' => $since], 'limit' => 100]);
            foreach ($page->autoPagingIterator() as $event) {
                $events[] = RecordedEvent::fromJson($event->toJSON());
                if (count($events) >= self::MAX_EVENTS) {
                    break 2;
                }
            }
        }

        return $events;
    }
}
