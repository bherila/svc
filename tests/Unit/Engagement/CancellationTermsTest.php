<?php

namespace Tests\Unit\Engagement;

use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Support\Billing\BillingCadence;
use App\Support\Engagement\Cancellation\CancellationState;
use App\Support\Engagement\Cancellation\CancellationTerms;
use App\Support\Packages\CancellationMode;
use Carbon\CarbonImmutable;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * An agreement's cancellation terms (CAN-1 to CAN-4) read off the model,
 * without a database: the dates an agreement offers and what each reader is
 * told. The decisions over those terms (who may cancel, decide and undo, when
 * an end is due) need no application and live in {@see CancellationRulesTest}.
 */
final class CancellationTermsTest extends TestCase
{
    // ── CancellationTerms ───────────────────────────────────────────────────

    #[Requirement('CAN-1', 'CAN-2')]
    public function test_the_terms_are_read_off_the_agreement(): void
    {
        $terms = CancellationTerms::of($this->agreement(['notice_days' => 45, 'minimum_term_months' => 6, 'cancellation_mode' => 'request', 'billing_cadence' => 'quarterly', 'ends_on' => '2027-06-30']));

        $this->assertSame([45, 6, CancellationMode::Request, BillingCadence::Quarterly, '2026-01-01', '2027-06-30'],
            [$terms->noticeDays, $terms->minimumTermMonths, $terms->mode, $terms->cadence, $terms->startsOn->toDateString(), $terms->endsOn?->toDateString()]);
        $this->assertTrue($terms->recurring());
    }

    #[Requirement('CAN-1')]
    public function test_an_agreement_states_no_terms_until_given_some(): void
    {
        $terms = CancellationTerms::of($this->agreement([]));

        $this->assertSame([0, null, CancellationMode::None], [$terms->noticeDays, $terms->minimumTermMonths, $terms->mode]);
    }

    #[Requirement('CAN-2')]
    public function test_an_agreement_with_no_minimum_term_ends_by_notice_alone(): void
    {
        $terms = CancellationTerms::of($this->agreement(['notice_days' => 30, 'minimum_term_months' => null]));

        $offer = $terms->offer(CarbonImmutable::parse('2026-11-09'));

        $this->assertSame(['2026-12-31', '2027-01-31', '2027-02-28'], $offer?->offeredDateStrings());
        $this->assertNull($offer->minimumTermEndsOn);
    }

    #[Requirement('CAN-2')]
    public function test_waiving_notice_counts_it_as_none_and_the_minimum_term_still_holds(): void
    {
        $noTerm = CancellationTerms::of($this->agreement(['notice_days' => 30]));
        $withTerm = CancellationTerms::of($this->agreement(['notice_days' => 30, 'minimum_term_months' => 12]));
        $today = CarbonImmutable::parse('2026-11-09');

        $this->assertSame('2026-11-30', $noTerm->offer($today, waiveNotice: true)?->earliest->toDateString());
        $this->assertSame('2026-12-31', $noTerm->offer($today)?->earliest->toDateString());
        $this->assertSame('2026-12-31', $withTerm->offer($today, waiveNotice: true)?->earliest->toDateString());
    }

    #[Requirement('CAN-2')]
    public function test_no_date_on_or_after_the_current_end_is_offered(): void
    {
        $terms = CancellationTerms::of($this->agreement(['notice_days' => 30, 'ends_on' => '2027-01-31']));
        $today = CarbonImmutable::parse('2026-11-09');

        $this->assertSame(['2026-12-31'], $terms->offer($today)?->offeredDateStrings());
        // The end it had before a scheduled one, when the caller says so.
        $this->assertSame(['2026-12-31', '2027-01-31'], $terms->offer($today, currentEndsOn: CarbonImmutable::parse('2027-02-28'))?->offeredDateStrings());
    }

    #[Requirement('CAN-2')]
    public function test_an_agreement_that_does_not_bill_in_periods_offers_nothing(): void
    {
        $terms = CancellationTerms::of($this->agreement(['billing_cadence' => 'one_time', 'notice_days' => 30]));

        $this->assertFalse($terms->recurring());
        $this->assertNull($terms->offer(CarbonImmutable::parse('2026-11-09')));
    }

    // ── What every reader is told ───────────────────────────────────────────

    #[Requirement('CAN-2', 'CAN-3')]
    public function test_the_state_offers_dates_only_while_an_end_may_be_scheduled(): void
    {
        $today = CarbonImmutable::parse('2026-11-09');
        $open = CancellationState::of($this->agreement(['notice_days' => 30, 'cancellation_mode' => 'self_serve']), null, null, $today);

        $this->assertTrue($open->schedulable());
        $this->assertSame('2026-12-31', $open->earliest());
        $this->assertSame(['2026-11-30', '2026-12-31', '2027-01-31'], $open->offered(true));
        $this->assertSame(['mode' => 'self_serve', 'notice_days' => 30, 'minimum_term_months' => null, 'earliest_end_on' => '2026-12-31',
            'offered_end_dates' => ['2026-12-31', '2027-01-31', '2027-02-28'], 'pending_request' => null, 'scheduled_end' => null], $open->forClient());

        $end = new AgreementScheduledTermination;
        $end->forceFill(['public_id' => 'end-1', 'ends_on' => '2026-12-31', 'previous_ends_on' => null, 'source' => 'self_serve', 'scheduled_at' => '2026-11-09 12:00:00', 'status' => 'scheduled']);
        $scheduled = CancellationState::of($this->agreement(['notice_days' => 30, 'cancellation_mode' => 'self_serve', 'ends_on' => '2026-12-31']), null, $end, $today);
        $this->assertFalse($scheduled->schedulable());
        $this->assertSame([], $scheduled->offered());
        $this->assertNull($scheduled->earliest());
        $this->assertSame(['id' => 'end-1', 'ends_on' => '2026-12-31', 'previous_ends_on' => null, 'source' => 'self_serve', 'scheduled_at' => '2026-11-09T12:00:00Z'], $scheduled->forOperator()['scheduled_end']);

        // An owner may still schedule in none mode; a client is offered nothing there.
        $none = CancellationState::of($this->agreement(['notice_days' => 30, 'cancellation_mode' => 'none']), null, null, $today);
        $this->assertSame(['2026-12-31', '2027-01-31', '2027-02-28'], $none->forOperator()['offered_end_dates']);
        $this->assertSame([], $none->forClient()['offered_end_dates']);
        $this->assertNull($none->forClient()['earliest_end_on']);
    }

    /** @param array<string, mixed> $attributes */
    private function agreement(array $attributes): ClientAgreement
    {
        $agreement = new ClientAgreement;
        $agreement->forceFill(['starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'status' => 'active', ...$attributes]);

        return $agreement;
    }
}
