<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\StripeWebhookService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The one list of Stripe event types (StripeWebhookService::EVENTS and
 * REQUIRED_EVENTS) is exactly what the service handles. Adding a handler
 * without listing the type, or listing a type nothing handles, fails here -
 * and with it the health check and the endpoint SVC creates, which both read
 * the list.
 */
#[CoversClass(StripeWebhookService::class)]
final class StripeWebhookEventsTest extends TestCase
{
    /**
     * Every quoted Stripe event type in the service's source: a type is
     * `object.verb`, where the object is one of the Stripe objects whose events
     * the service receives. A prefix test (`'charge.'`) is not a type.
     *
     * @return list<string>
     */
    private function typesNamedInSource(): array
    {
        $source = file_get_contents((new ReflectionClass(StripeWebhookService::class))->getFileName() ?: '');
        $this->assertIsString($source);
        preg_match_all('/\'((?:payment_intent|charge|refund|payout|payment_method|setup_intent|customer)\.[a-z_.]*[a-z_])\'/', $source, $found);
        $types = array_values(array_unique($found[1]));
        sort($types);

        return $types;
    }

    public function test_the_list_is_exactly_the_types_the_service_names(): void
    {
        $listed = StripeWebhookService::EVENTS;
        sort($listed);

        $this->assertSame($this->typesNamedInSource(), $listed, 'List every Stripe event type the webhook service handles in StripeWebhookService::EVENTS (and REQUIRED_EVENTS unless it is a legacy alias).');
    }

    public function test_every_listed_type_is_read_in_more_than_the_list_itself(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(StripeWebhookService::class))->getFileName() ?: '');
        // A type listed through a group constant is used by the group's handler; the
        // rest must also appear where an event is dispatched on, so each is named at
        // least twice (the list and a handler) unless it is a member of a group.
        $grouped = ['charge.refund.updated', 'refund.updated', 'refund.failed', 'payout.paid', 'payout.failed', 'payout.canceled',
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'];
        foreach (StripeWebhookService::EVENTS as $type) {
            if (in_array($type, $grouped, true)) {
                continue;
            }
            $this->assertGreaterThan(1, substr_count($source, "'".$type."'"), $type.' is listed but no handler names it.');
        }
    }

    public function test_required_events_are_the_handled_ones_without_the_legacy_alias(): void
    {
        $this->assertSame(['charge.refund.updated'], array_values(array_diff(StripeWebhookService::EVENTS, StripeWebhookService::REQUIRED_EVENTS)));
        $this->assertSame(count(StripeWebhookService::REQUIRED_EVENTS), count(array_unique(StripeWebhookService::REQUIRED_EVENTS)));
        $this->assertSame(count(StripeWebhookService::EVENTS), count(array_unique(StripeWebhookService::EVENTS)));
    }

    public function test_the_events_the_money_paths_depend_on_are_required(): void
    {
        foreach ([
            'payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.canceled',
            'charge.refunded', 'refund.failed', 'refund.updated', 'charge.dispute.created', 'charge.dispute.closed',
            'payout.paid', 'payout.failed', 'payout.canceled', 'payment_method.attached', 'payment_method.detached',
            'setup_intent.succeeded', 'customer.updated',
        ] as $type) {
            $this->assertContains($type, StripeWebhookService::REQUIRED_EVENTS);
        }
    }

    public function test_a_stand_in_names_a_required_type_and_a_handled_one(): void
    {
        foreach (StripeWebhookService::STAND_INS as $required => $standIn) {
            $this->assertContains($required, StripeWebhookService::REQUIRED_EVENTS);
            $this->assertContains($standIn, StripeWebhookService::EVENTS);
            $this->assertNotContains($standIn, StripeWebhookService::REQUIRED_EVENTS);
        }
    }
}
