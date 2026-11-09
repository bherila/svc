<?php

namespace Tests\Unit\StripeLive;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\StripeLive\Support\RecordedEvent;
use Tests\StripeLive\Support\StripeEventMatcher;

final class StripeEventMatcherTest extends TestCase
{
    /** @param array<string, mixed> $object */
    private function event(string $id, string $type, int $created, array $object): RecordedEvent
    {
        return RecordedEvent::fromJson(json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<RecordedEvent>  $events
     * @return list<string>
     */
    private function ids(array $events): array
    {
        return array_map(static fn (RecordedEvent $e): string => $e->id, $events);
    }

    public function test_references_collect_every_id_the_object_points_at(): void
    {
        $this->assertSame(['pi_1', 'cus_1', 'pm_1'], $this->event('evt_1', 'payment_intent.succeeded', 10, ['id' => 'pi_1', 'customer' => 'cus_1', 'payment_method' => 'pm_1', 'amount' => 500])->references());
        $this->assertSame(['ch_1', 'pi_2'], $this->event('evt_2', 'charge.succeeded', 10, ['id' => 'ch_1', 'payment_intent' => 'pi_2', 'customer' => null])->references());
        $this->assertSame(['seti_1'], $this->event('evt_3', 'setup_intent.succeeded', 10, ['id' => 'seti_1', 'setup_intent' => 'seti_1', 'customer' => ''])->references());
        $this->assertSame([], RecordedEvent::fromJson('{"id":"evt_4","type":"ping"}')->references());
    }

    public function test_only_this_scenarios_wanted_events_match_and_they_come_back_oldest_first(): void
    {
        // The API lists newest first.
        $listed = [
            $this->event('evt_other', 'payment_intent.succeeded', 30, ['id' => 'pi_other', 'customer' => 'cus_other']),
            $this->event('evt_wrong_type', 'charge.succeeded', 30, ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'customer' => 'cus_1']),
            $this->event('evt_succeeded', 'payment_intent.succeeded', 20, ['id' => 'pi_1', 'customer' => 'cus_1']),
            $this->event('evt_created', 'payment_intent.created', 20, ['id' => 'pi_1', 'customer' => 'cus_1']),
            $this->event('evt_attached', 'payment_method.attached', 10, ['id' => 'pm_1', 'customer' => 'cus_1']),
        ];

        $matched = StripeEventMatcher::matching($listed, ['cus_1'], ['payment_intent.succeeded', 'payment_intent.created', 'payment_method.attached']);

        // Equal timestamps keep the reverse of the API's order, so created precedes succeeded.
        $this->assertSame(['evt_attached', 'evt_created', 'evt_succeeded'], $this->ids($matched));
    }

    public function test_an_event_matches_by_a_method_id_when_the_customer_is_gone(): void
    {
        $detached = $this->event('evt_detached', 'payment_method.detached', 5, ['id' => 'pm_1', 'customer' => null]);

        $this->assertSame(['evt_detached'], $this->ids(StripeEventMatcher::matching([$detached], ['cus_1', 'pm_1'], ['payment_method.detached'])));
        $this->assertSame([], StripeEventMatcher::matching([$detached], ['cus_1'], ['payment_method.detached']));
    }

    public function test_a_repeated_listing_of_one_event_counts_once(): void
    {
        $event = $this->event('evt_1', 'payment_intent.succeeded', 1, ['id' => 'pi_1']);

        $this->assertCount(1, StripeEventMatcher::matching([$event, $event], ['pi_1'], ['payment_intent.succeeded']));
    }

    public function test_missing_names_the_expected_types_not_yet_seen(): void
    {
        $matched = [$this->event('evt_1', 'setup_intent.succeeded', 1, ['id' => 'seti_1'])];

        $this->assertSame(['payment_method.attached'], StripeEventMatcher::missing($matched, ['setup_intent.succeeded', 'payment_method.attached', 'payment_method.attached']));
        $this->assertSame([], StripeEventMatcher::missing($matched, ['setup_intent.succeeded']));
        $this->assertSame(['setup_intent.succeeded'], StripeEventMatcher::missing([], ['setup_intent.succeeded']));
    }

    public function test_an_event_without_an_id_or_type_or_valid_json_is_rejected(): void
    {
        foreach (['not json', '[]', '{"id":"evt_1"}', '{"type":"ping"}'] as $json) {
            try {
                RecordedEvent::fromJson($json);
                $this->fail('Expected rejection of '.$json);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_exact_json_is_kept_for_replay(): void
    {
        $json = '{"id":"evt_1","type":"ping","created":7,"data":{"object":{"metadata":{}}}}';

        $event = RecordedEvent::fromJson($json);

        $this->assertSame($json, $event->json);
        $this->assertSame(7, $event->created);
    }
}
