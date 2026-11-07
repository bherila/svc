<?php

namespace Tests\StripeLive\Support;

use RuntimeException;
use Stripe\StripeClient;

/**
 * Reads this scenario's events back from Stripe's Events API, waiting (bounded)
 * for the ones it expects to appear.
 */
final class StripeEventFeed
{
    /** More than any one scenario produces; a busy account cannot make the suite page forever. */
    private const int MAX_EVENTS = 500;

    public function __construct(private readonly StripeClient $client, private readonly StripePoll $poll = new StripePoll) {}

    /**
     * @param  int  $since  unix time the run started; older events are never listed
     * @param  list<string>  $ids  the customers, intents and methods the scenario created
     * @param  list<string>  $expected  event types that must show up before this returns
     * @param  list<string>  $replayable  every type worth delivering, a superset of `$expected` is fine
     * @return list<RecordedEvent> oldest first
     */
    public function await(int $since, array $ids, array $expected, array $replayable, int $timeoutSeconds = 30): array
    {
        $types = array_values(array_unique([...$expected, ...$replayable]));
        $last = [];
        $found = $this->poll->until(function () use ($since, $ids, $types, $expected, &$last): ?array {
            $last = StripeEventMatcher::matching($this->list($since, $types), $ids, $types);

            return StripeEventMatcher::missing($last, $expected) === [] ? $last : null;
        }, $timeoutSeconds);

        if ($found === null) {
            throw new RuntimeException(sprintf(
                'Stripe did not list %s for %s within %d seconds (saw %s). The Events API can lag, and a restricted key needs Events read.',
                implode(', ', StripeEventMatcher::missing($last, $expected)),
                implode(', ', $ids),
                $timeoutSeconds,
                $last === [] ? 'nothing' : implode(', ', array_map(static fn (RecordedEvent $event): string => $event->type, $last)),
            ));
        }

        return $found;
    }

    /**
     * @param  list<string>  $types
     * @return list<RecordedEvent> newest first, as the API lists them
     */
    private function list(int $since, array $types): array
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
