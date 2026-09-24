<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\EngagementException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementTerminationTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminating_keeps_the_row_and_ends_it_today_in_the_workspace_timezone(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $agreement = $this->activeAgreement('2026-01-01');

        $terminated = app(AgreementWorkflow::class)->terminate($agreement);

        $this->assertSame('terminated', $terminated->status);
        $this->assertSame('2026-09-20', $terminated->ends_on->toDateString());
        $this->assertSame(1, ClientAgreement::query()->count(), 'archive keeps the record');
    }

    public function test_it_records_what_moved_under_the_existing_transition_event(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $agreement = $this->activeAgreement('2026-01-01');

        app(AgreementWorkflow::class)->terminate($agreement);

        $activity = ClientCompanyActivity::query()->where('action', 'agreement.transitioned')->sole();
        $this->assertSame(['old' => 'active', 'new' => 'terminated'], $activity->payload['changes']['status']);
        $this->assertSame(['old' => null, 'new' => '2026-09-20'], $activity->payload['changes']['ends_on']);
    }

    public function test_an_explicit_date_is_taken_as_stated_so_a_lapse_can_be_backdated(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $agreement = $this->activeAgreement('2026-01-01');

        $terminated = app(AgreementWorkflow::class)->terminate($agreement, '2026-06-30');

        $this->assertSame('2026-06-30', $terminated->ends_on->toDateString());
    }

    public function test_an_end_date_already_in_the_past_is_never_extended(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $agreement = $this->activeAgreement('2026-01-01', endsOn: '2026-05-29');

        $terminated = app(AgreementWorkflow::class)->terminate($agreement);

        $this->assertSame('2026-05-29', $terminated->ends_on->toDateString());
    }

    public function test_an_agreement_that_has_not_started_ends_on_its_start_date_not_before_it(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $agreement = $this->activeAgreement('2026-12-01', status: 'draft');

        $terminated = app(AgreementWorkflow::class)->terminate($agreement);

        $this->assertSame('2026-12-01', $terminated->ends_on->toDateString());
    }

    public function test_an_end_before_the_start_is_refused_and_writes_nothing(): void
    {
        $agreement = $this->activeAgreement('2026-06-01');

        try {
            app(AgreementWorkflow::class)->terminate($agreement, '2026-05-31');
            $this->fail('An agreement cannot end before it starts.');
        } catch (EngagementException $exception) {
            $this->assertSame('The agreement cannot end before it starts.', $exception->getMessage());
        }

        $this->assertSame('active', $agreement->fresh()->status);
        $this->assertNull($agreement->fresh()->ends_on);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'agreement.transitioned')->count());
    }

    public function test_terminating_twice_is_idempotent_and_records_one_transition(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $agreement = $this->activeAgreement('2026-01-01');
        $workflow = app(AgreementWorkflow::class);

        $workflow->terminate($agreement);
        $this->travelTo('2026-10-05 12:00:00');
        $again = $workflow->terminate($agreement->fresh());

        $this->assertSame('2026-09-20', $again->ends_on->toDateString(), 'a repeat must not move the end date');
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'agreement.transitioned')->count());
    }

    public function test_an_expired_agreement_cannot_be_terminated(): void
    {
        $agreement = $this->activeAgreement('2026-01-01', status: 'expired');

        $this->expectException(EngagementException::class);
        $this->expectExceptionMessage('Only draft, active or paused agreements can be terminated.');

        app(AgreementWorkflow::class)->terminate($agreement);
    }

    public function test_a_terminated_agreement_cannot_be_reactivated(): void
    {
        $agreement = $this->activeAgreement('2026-01-01');
        $workflow = app(AgreementWorkflow::class);
        $workflow->terminate($agreement);

        $this->expectException(EngagementException::class);
        $this->expectExceptionMessage('Only draft or paused agreements can be activated.');

        $workflow->activate($agreement->fresh());
    }

    private function activeAgreement(string $startsOn, ?string $endsOn = null, string $status = 'active'): ClientAgreement
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Termination Workspace', 'slug' => 'synthetic-termination-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Termination Client',
            'slug' => 'synthetic-termination-client-'.uniqid(),
        ]);

        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Synthetic agreement',
            'status' => $status,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }
}
