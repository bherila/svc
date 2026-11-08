<?php

namespace Tests\StripeLive\Support;

use RuntimeException;

/**
 * Reads this scenario's events back from Stripe, waiting (bounded) until every
 * expectation is met by an event of this scenario.
 *
 * The wait is on predicates, not on types: a customer produces several
 * `customer.updated` events, and the one that carries the default payment
 * method is the one that matters, so an earlier one never ends the wait.
 */
final class StripeEventFeed
{
    public function __construct(private readonly StripeEventSource $source, private readonly StripePoll $poll = new StripePoll) {}

    /**
     * @param  int  $since  unix time the run started; older events are never listed
     * @param  list<string>  $ids  the customers, intents and methods the scenario created
     * @param  list<string|StripeExpectation>  $expected  what must show up before this returns
     * @param  list<string>  $replayable  every type worth delivering, a superset of the expected types is fine
     * @return list<RecordedEvent> oldest first
     */
    public function await(int $since, array $ids, array $expected, array $replayable, int $timeoutSeconds = 30): array
    {
        $expectations = array_map(StripeExpectation::of(...), $expected);
        $types = array_values(array_unique([
            ...array_map(static fn (StripeExpectation $expectation): string => $expectation->type, $expectations),
            ...$replayable,
        ]));
        $last = [];
        $found = $this->poll->until(function () use ($since, $ids, $types, $expectations, &$last): ?array {
            $last = StripeEventMatcher::matching($this->source->list($since, $types), $ids, $types);

            return StripeEventMatcher::missing($last, $expectations) === [] ? $last : null;
        }, $timeoutSeconds);

        if ($found === null) {
            throw new RuntimeException(sprintf(
                'Stripe did not list %s for %s within %d seconds (saw %s). The Events API can lag, and a restricted key needs Events read.',
                implode(', ', StripeEventMatcher::missing($last, $expectations)),
                implode(', ', $ids),
                $timeoutSeconds,
                $last === [] ? 'nothing' : implode(', ', array_map(static fn (RecordedEvent $event): string => $event->type, $last)),
            ));
        }

        return $found;
    }
}
