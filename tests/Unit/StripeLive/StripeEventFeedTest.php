<?php

namespace Tests\Unit\StripeLive;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\StripeLive\Support\RecordedEvent;
use Tests\StripeLive\Support\StripeEventFeed;
use Tests\StripeLive\Support\StripeEventSource;
use Tests\StripeLive\Support\StripeExpectation;
use Tests\StripeLive\Support\StripePoll;

/**
 * The waiter that decides when a scenario may start delivering events. Stripe is
 * scripted: each listing is one poll of the Events API, newest first, and the
 * clock and the sleep are injected, so nothing waits and nothing is random.
 */
final class StripeEventFeedTest extends TestCase
{
    private const string CUSTOMER = 'cus_synthetic';

    private const string METHOD = 'pm_synthetic';

    private float $clock = 0.0;

    /** @var list<int> */
    private array $sleeps = [];

    /** @param array<string, mixed> $object */
    private function event(string $id, string $type, int $created, array $object): RecordedEvent
    {
        return RecordedEvent::fromJson(json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR));
    }

    private function customerUpdated(string $id, int $created, ?string $default, string $customer = self::CUSTOMER): RecordedEvent
    {
        return $this->event($id, 'customer.updated', $created, ['id' => $customer, 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $default]]);
    }

    /** Everything Stripe has produced by the first poll: the tag, the setup and the attach, but not the default. */
    private function firstPoll(): array
    {
        return [
            $this->event('evt_attached', 'payment_method.attached', 12, ['id' => self::METHOD, 'customer' => self::CUSTOMER]),
            $this->event('evt_setup', 'setup_intent.succeeded', 11, ['id' => 'seti_synthetic', 'customer' => self::CUSTOMER, 'payment_method' => self::METHOD]),
            $this->customerUpdated('evt_tagged', 10, null),
        ];
    }

    /**
     * @param  list<list<RecordedEvent>>  $polls  what each successive listing returns
     * @return array{0: StripeEventFeed, 1: object{calls: int}}
     */
    private function feed(array $polls): array
    {
        $source = new class($polls) implements StripeEventSource
        {
            public int $calls = 0;

            /** @param list<list<RecordedEvent>> $polls */
            public function __construct(private readonly array $polls) {}

            public function list(int $since, array $types): array
            {
                return $this->polls[min($this->calls++, count($this->polls) - 1)];
            }
        };
        $this->clock = 0.0;
        $this->sleeps = [];
        $poll = new StripePoll(fn (): float => $this->clock, function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
            $this->clock += $milliseconds / 1000;
        });

        return [new StripeEventFeed($source, $poll), $source];
    }

    /** @return list<string|StripeExpectation> */
    private function expected(): array
    {
        return ['setup_intent.succeeded', 'payment_method.attached', StripeExpectation::customerDefault(self::CUSTOMER, self::METHOD)];
    }

    /** @param list<RecordedEvent> $events */
    private function ids(array $events): array
    {
        return array_map(static fn (RecordedEvent $event): string => $event->id, $events);
    }

    public function test_the_metadata_update_and_the_setup_events_do_not_end_the_wait_for_the_default(): void
    {
        $withDefault = [$this->customerUpdated('evt_default', 13, self::METHOD), ...$this->firstPoll()];
        [$feed, $source] = $this->feed([$this->firstPoll(), $withDefault]);

        $events = $feed->await(0, [self::CUSTOMER, self::METHOD], $this->expected(), ['customer.updated'], 30);

        // Poll 1 holds a customer.updated, the setup intent and the attach - and still is not enough.
        $this->assertSame(2, $source->calls);
        $this->assertSame([1000], $this->sleeps);
        $this->assertSame(['evt_tagged', 'evt_setup', 'evt_attached', 'evt_default'], $this->ids($events));
    }

    public function test_a_poll_that_only_has_the_earlier_customer_event_fails_the_wait_instead_of_passing_it(): void
    {
        [$feed, $source] = $this->feed([$this->firstPoll()]);

        try {
            $feed->await(0, [self::CUSTOMER, self::METHOD], $this->expected(), ['customer.updated'], 3);
            $this->fail('The metadata update must not satisfy the wait for the default.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('customer.updated for '.self::CUSTOMER.' with default payment method '.self::METHOD, $failure->getMessage());
            $this->assertStringStartsWith('Stripe did not list customer.updated for '.self::CUSTOMER.' with default payment method '.self::METHOD.' for ', $failure->getMessage(), 'Only the default is missing; the setup events were seen.');
            $this->assertStringContainsString('saw customer.updated, setup_intent.succeeded, payment_method.attached', $failure->getMessage());
        }
        $this->assertGreaterThan(1, $source->calls, 'It kept polling until the deadline.');
    }

    public function test_a_single_poll_is_enough_when_it_already_holds_everything(): void
    {
        $all = [$this->customerUpdated('evt_default', 13, self::METHOD), ...$this->firstPoll()];
        [$feed, $source] = $this->feed([$all]);

        $events = $feed->await(0, [self::CUSTOMER, self::METHOD], $this->expected(), [], 30);

        $this->assertSame(1, $source->calls);
        $this->assertSame([], $this->sleeps);
        $this->assertCount(4, $events);
    }

    public function test_another_customers_default_or_another_method_does_not_satisfy_it(): void
    {
        $others = [
            $this->customerUpdated('evt_other_customer', 14, self::METHOD, 'cus_other'),
            $this->customerUpdated('evt_other_method', 13, 'pm_other'),
            ...$this->firstPoll(),
        ];
        [$feed, $source] = $this->feed([$others]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('with default payment method '.self::METHOD);

        $feed->await(0, [self::CUSTOMER, self::METHOD], $this->expected(), [], 2);
    }

    public function test_an_expanded_default_payment_method_object_counts(): void
    {
        $expanded = $this->event('evt_expanded', 'customer.updated', 13, ['id' => self::CUSTOMER, 'invoice_settings' => ['default_payment_method' => ['id' => self::METHOD, 'object' => 'payment_method']]]);
        [$feed] = $this->feed([[$expanded, ...$this->firstPoll()]]);

        $events = $feed->await(0, [self::CUSTOMER, self::METHOD], $this->expected(), [], 30);

        $this->assertContains('evt_expanded', $this->ids($events));
    }

    public function test_the_types_asked_of_stripe_include_every_expectation_and_the_replayable_ones(): void
    {
        $asked = [];
        $source = new class($asked) implements StripeEventSource
        {
            /** @param list<string> $asked */
            public function __construct(public array &$asked) {}

            public function list(int $since, array $types): array
            {
                $this->asked = $types;

                return [];
            }
        };

        try {
            (new StripeEventFeed($source, new StripePoll(fn (): float => 0.0, static function (int $milliseconds): void {})))
                ->await(0, [self::CUSTOMER], ['setup_intent.succeeded', StripeExpectation::customerDefault(self::CUSTOMER, null)], ['payment_method.detached'], 0);
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(['setup_intent.succeeded', 'customer.updated', 'payment_method.detached'], $asked);
    }
}
