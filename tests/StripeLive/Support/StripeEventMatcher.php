<?php

namespace Tests\StripeLive\Support;

/**
 * Picks this run's events out of an account-wide stream. The Events API returns
 * every event of the account, so concurrent runs and unrelated activity are
 * ignored: an event counts only when it is of a wanted type and its object
 * points at an id this scenario created.
 */
final class StripeEventMatcher
{
    /**
     * Matching events in the order Stripe produced them (oldest first). The API
     * lists newest first, so events with equal timestamps keep the reverse of
     * its order.
     *
     * @param  list<RecordedEvent>  $events  as listed by the API, newest first
     * @param  list<string>  $ids  customers, intents and methods this scenario created
     * @param  list<string>  $types
     * @return list<RecordedEvent>
     */
    public static function matching(array $events, array $ids, array $types): array
    {
        $matched = [];
        foreach (array_reverse($events) as $event) {
            if (in_array($event->type, $types, true) && array_intersect($event->references(), $ids) !== []) {
                $matched[$event->id] = $event;
            }
        }
        $ordered = array_values($matched);
        usort($ordered, static fn (RecordedEvent $a, RecordedEvent $b): int => $a->created <=> $b->created);

        return $ordered;
    }

    /**
     * Expectations no matched event satisfies yet, described for a message. A
     * plain string is a type: any event of it counts. A {@see StripeExpectation}
     * can ask for more - the object, the default payment method - so an earlier
     * event of the same type cannot stand in for the one that is awaited.
     *
     * @param  list<RecordedEvent>  $matched
     * @param  list<string|StripeExpectation>  $expected
     * @return list<string>
     */
    public static function missing(array $matched, array $expected): array
    {
        $missing = [];
        foreach ($expected as $item) {
            $expectation = StripeExpectation::of($item);
            foreach ($matched as $event) {
                if ($expectation->isMetBy($event)) {
                    continue 2;
                }
            }
            $missing[$expectation->describe()] = $expectation->describe();
        }

        return array_values($missing);
    }
}
