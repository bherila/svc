<?php

namespace Tests\Unit\Engagement;

use App\Models\AgreementCancellationRequest;
use App\Models\ClientAgreement;
use App\Models\User;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Engagement\Cancellation\CancellationState;
use App\Support\Engagement\Cancellation\CancellationTerms;
use App\Support\Packages\CancellationMode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * What each reader is told about one agreement's cancellation (CAN-2 to
 * CAN-4), in full: the owner's and admin's view and the client's, with a
 * pending request, and nothing offered while an end may not be scheduled.
 * Models are built in memory; their casts need the application.
 */
#[CoversClass(CancellationState::class)]
#[CoversClass(CancellationTerms::class)]
final class CancellationStateTest extends TestCase
{
    #[Requirement('CAN-2', 'CAN-4')]
    public function test_the_operator_is_told_both_date_lists_and_the_pending_request_in_full(): void
    {
        $pending = $this->pending();
        $pending->setRelation('requestedBy', (new User)->forceFill(['name' => 'Pat Contact']));
        $state = CancellationState::of($this->agreement(['notice_days' => 30, 'cancellation_mode' => 'request']), $pending, null, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame([
            'earliest_end_on' => '2026-12-31',
            'offered_end_dates' => ['2026-12-31', '2027-01-31', '2027-02-28'],
            'offered_end_dates_without_notice' => ['2026-11-30', '2026-12-31', '2027-01-31'],
            'pending_request' => [
                'id' => 'request-1',
                'version' => AgentApiVersion::for($pending),
                'requested_end_on' => '2027-01-31',
                'reason' => 'Moving on',
                'requested_at' => '2026-11-08T15:30:00Z',
                'requested_by' => 'Pat Contact',
            ],
            'scheduled_end' => null,
        ], $state->forOperator());
    }

    #[Requirement('CAN-4')]
    public function test_a_request_whose_requester_is_gone_names_no_one(): void
    {
        $pending = $this->pending();
        $pending->setRelation('requestedBy', null);
        $state = CancellationState::of($this->agreement(['notice_days' => 30, 'cancellation_mode' => 'request']), $pending, null, CarbonImmutable::parse('2026-11-09'));

        $request = $state->forOperator()['pending_request'];
        $this->assertIsArray($request);
        $this->assertArrayHasKey('requested_by', $request);
        $this->assertNull($request['requested_by']);
    }

    #[Requirement('CAN-3', 'CAN-4')]
    public function test_the_client_is_told_the_pending_request_and_offered_no_second_one(): void
    {
        $pending = $this->pending();
        $state = CancellationState::of($this->agreement(['notice_days' => 30, 'minimum_term_months' => 3, 'cancellation_mode' => 'request']), $pending, null, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame([
            'mode' => 'request',
            'notice_days' => 30,
            'minimum_term_months' => 3,
            'earliest_end_on' => null,
            'offered_end_dates' => [],
            'pending_request' => [
                'id' => 'request-1',
                'requested_end_on' => '2027-01-31',
                'reason' => 'Moving on',
                'requested_at' => '2026-11-08T15:30:00Z',
            ],
            'scheduled_end' => null,
        ], $state->forClient());
    }

    /** An agreement not yet active - a draft, or one not saved, which has no status yet - is offered no end. */
    #[Requirement('CAN-2')]
    public function test_an_agreement_that_is_not_active_is_offered_nothing(): void
    {
        $today = CarbonImmutable::parse('2026-11-09');
        foreach (['draft' => ['status' => 'draft'], 'unsaved' => []] as $case => $status) {
            $agreement = new ClientAgreement;
            $agreement->forceFill(['starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'notice_days' => 30, 'cancellation_mode' => 'self_serve', ...$status]);
            $state = CancellationState::of($agreement, null, null, $today);

            $this->assertFalse($state->schedulable(), $case);
            $this->assertSame([], $state->offered(), $case);
            $this->assertSame([], $state->offered(true), $case);
            $this->assertNull($state->earliest(), $case);
            $this->assertSame([], $state->forClient()['offered_end_dates'], $case);
        }
    }

    /** A row read without the cancellation columns states no terms, as an agreement given none does (CAN-1). */
    #[Requirement('CAN-1')]
    public function test_an_agreement_read_without_its_cancellation_columns_states_no_terms(): void
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes(['starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'status' => 'active'], true);

        $terms = CancellationTerms::of($agreement);

        $this->assertSame([0, null, CancellationMode::None], [$terms->noticeDays, $terms->minimumTermMonths, $terms->mode]);
    }

    private function pending(): AgreementCancellationRequest
    {
        $pending = new AgreementCancellationRequest;
        $pending->forceFill(['id' => 7, 'public_id' => 'request-1', 'status' => 'pending', 'requested_end_on' => '2027-01-31', 'reason' => 'Moving on', 'requested_at' => '2026-11-08 15:30:00', 'lock_version' => 3]);

        return $pending;
    }

    /** @param array<string, mixed> $attributes */
    private function agreement(array $attributes): ClientAgreement
    {
        $agreement = new ClientAgreement;
        $agreement->forceFill(['starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'status' => 'active', ...$attributes]);

        return $agreement;
    }
}
