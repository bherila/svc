<?php

namespace Tests\Feature\Expenses;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientExpenseSchedule;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class ExpenseRecurrenceTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    }

    public function test_manager_request_materializes_only_due_drafts_and_retries_do_not_recreate_discarded_occurrences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-03-31 12:00:00 UTC'));
        $workspace = $this->syntheticWorkspace('Recurring');
        $company = $this->syntheticCompany($workspace, 'Recurring');
        $manager = $this->syntheticMember($workspace, 'Recurring manager');
        $this->actingAs($manager)->createSchedule($workspace, $company, $this->facts() + ['starts_on' => '2028-01-31', 'cadence' => 'monthly'])->assertSuccessful();
        $schedule = ClientExpenseSchedule::query()->where('workspace_id', $workspace->id)->sole();
        $this->generateSchedule($workspace, $schedule)->assertSuccessful();
        $rows = ClientExpense::query()->where('workspace_id', $workspace->id)->orderBy('id')->get();
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], $rows->map(fn ($row): string => $row->spent_on->toDateString())->all());
        foreach ($rows as $row) {
            $this->assertSame('draft', $row->status);
            $this->assertNull($row->approved_at);
            $this->assertNull($row->approved_by_user_id);
            $this->assertSame(1200, $row->amount);
        }
        (new WorkspaceExpenses($workspace))->discard($rows->first());
        $schedule->refresh()->update(['next_occurrence' => 0]); // Simulate a recovered cursor; discarded rows remain proof.
        $this->generateSchedule($workspace, $schedule)->assertSuccessful();
        $this->assertSame(3, ClientExpense::withTrashed()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(3, $schedule->refresh()->next_occurrence);
    }

    public function test_batch_bound_pause_and_template_edits_preserve_cursor_and_existing_expenses(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-03-31 12:00:00 UTC'));
        $workspace = $this->syntheticWorkspace('Catchup');
        $company = $this->syntheticCompany($workspace, 'Catchup');
        $manager = $this->syntheticMember($workspace, 'Catchup manager');
        $repository = new WorkspaceExpenseSchedules($workspace);
        $schedule = $repository->create($company, null, new NewExpense(CarbonImmutable::parse('2020-01-31'), 1200, 'USD', 'Synthetic recurrence'), BillingCadence::Monthly);
        LockOrderRecorder::start();
        $this->assertSame(24, $repository->generate($schedule->public_id, $manager));
        $this->assertContains(LockResource::ClientExpenseSchedule, array_merge(...LockOrderRecorder::sequences()));
        LockOrderRecorder::stop();
        $this->actingAs($manager)->updateSchedule($workspace, $schedule, $this->facts(2400) + ['active' => false])->assertSuccessful();
        $this->assertSame(0, $repository->generate($schedule->public_id, $manager));
        $this->assertSame(24, $schedule->refresh()->next_occurrence);
        $this->updateSchedule($workspace, $schedule, $this->facts(2400) + ['active' => true, 'starts_on' => '2019-01-01'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->updateSchedule($workspace, $schedule, $this->facts(2400) + ['active' => true])->assertSuccessful();
        $this->assertSame(24, $repository->generate($schedule->public_id, $manager));
        $this->assertSame(24, ClientExpense::query()->where('workspace_id', $workspace->id)->where('amount', 1200)->count());
        $this->assertSame(24, ClientExpense::query()->where('workspace_id', $workspace->id)->where('amount', 2400)->count());
        $this->get(route('clients.expense-schedules', [$workspace, $company]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('schedules.0.pending', true)->where('schedules.0.next_on', '2024-01-31'));
    }

    public function test_workspace_date_controls_cutoff_and_rollback_restores_cursor_and_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-02-01 01:00:00 UTC'));
        $workspace = $this->syntheticWorkspace('Clock');
        $workspace->update(['timezone' => 'America/Los_Angeles']);
        $company = $this->syntheticCompany($workspace, 'Clock');
        $manager = $this->syntheticMember($workspace, 'Clock manager');
        $repository = new WorkspaceExpenseSchedules($workspace);
        $schedule = $repository->create($company, null, new NewExpense(CarbonImmutable::parse('2028-02-01'), 1200, 'USD', 'Synthetic clock'), BillingCadence::Monthly);
        $this->assertSame(0, $repository->generate($schedule->public_id, $manager));
        $this->travelTo(CarbonImmutable::parse('2028-02-01 12:00:00 UTC'));
        DB::beginTransaction();
        try {
            $this->assertSame(1, $repository->generate($schedule->public_id, $manager));
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $schedule->refresh()->next_occurrence);
        $this->assertSame(0, ClientExpense::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_full_http_routes_refuse_other_workspaces_members_and_foreign_projects(): void
    {
        $workspace = $this->syntheticWorkspace('Private recurrence');
        $company = $this->syntheticCompany($workspace, 'Private recurrence');
        $manager = $this->syntheticMember($workspace, 'Schedule owner');
        $schedule = (new WorkspaceExpenseSchedules($workspace))->create($company, null, new NewExpense(CarbonImmutable::parse('2028-01-01'), 1200, 'USD', 'Synthetic private'), BillingCadence::Monthly);
        $other = $this->syntheticWorkspace('Foreign recurrence');
        $otherCompany = $this->syntheticCompany($other, 'Foreign recurrence');
        $otherManager = $this->syntheticMember($other, 'Other schedule manager');
        $project = $this->syntheticProject($otherCompany, 'Foreign project');
        $this->actingAs($otherManager)->get(route('clients.expense-schedules', [$other, $company]))->assertNotFound();
        $this->updateSchedule($other, $schedule, $this->facts() + ['active' => false])->assertNotFound();
        $this->generateSchedule($other, $schedule)->assertNotFound();
        $this->actingAs($manager)->createSchedule($workspace, $company, $this->facts() + ['starts_on' => '2028-01-01', 'cadence' => 'monthly', 'project_id' => $project->public_id])->assertNotFound();
        $member = $this->syntheticMember($workspace, 'Ordinary member', 'member');
        $this->actingAs($member)->get(route('clients.expense-schedules', [$workspace, $company]))->assertForbidden();
        $this->createSchedule($workspace, $company, $this->facts() + ['starts_on' => '2028-01-01', 'cadence' => 'monthly'])->assertForbidden();
        $this->updateSchedule($workspace, $schedule, $this->facts() + ['active' => false])->assertForbidden();
        $this->generateSchedule($workspace, $schedule)->assertForbidden();
        $this->assertSame(0, ClientExpense::query()->count());
        $this->assertTrue($schedule->refresh()->is_active);
    }

    public function test_unique_occurrence_and_tenant_foreign_keys_are_enforced_by_database(): void
    {
        $workspace = $this->syntheticWorkspace('Keys');
        $company = $this->syntheticCompany($workspace, 'Keys');
        $schedule = (new WorkspaceExpenseSchedules($workspace))->create($company, null, new NewExpense(CarbonImmutable::parse('2028-01-01'), 1200, 'USD', 'Synthetic keys'), BillingCadence::Monthly);
        $first = $this->recordSyntheticExpense($workspace, $company);
        $second = $this->recordSyntheticExpense($workspace, $company);
        $first->forceFill(['client_expense_schedule_id' => $schedule->id, 'occurrence_on' => '2028-01-01'])->save();
        $this->expectException(QueryException::class);
        $second->forceFill(['client_expense_schedule_id' => $schedule->id, 'occurrence_on' => '2028-01-01'])->save();
    }

    public function test_database_refuses_cross_workspace_schedule_links(): void
    {
        $workspace = $this->syntheticWorkspace('Schedule key owner');
        $company = $this->syntheticCompany($workspace, 'Schedule key owner');
        $schedule = (new WorkspaceExpenseSchedules($workspace))->create($company, null, new NewExpense(CarbonImmutable::parse('2028-01-01'), 1200, 'USD', 'Synthetic foreign key'), BillingCadence::Monthly);
        $foreignWorkspace = $this->syntheticWorkspace('Schedule foreign key');
        $expense = $this->recordSyntheticExpense($foreignWorkspace, $this->syntheticCompany($foreignWorkspace, 'Schedule foreign key'));
        $this->expectException(QueryException::class);
        $expense->forceFill(['client_expense_schedule_id' => $schedule->id, 'occurrence_on' => '2028-01-01'])->save();
    }

    public function test_currency_validation_refuses_non_ascii_letters_with_a_field_error(): void
    {
        $workspace = $this->syntheticWorkspace('Currency validation');
        $company = $this->syntheticCompany($workspace, 'Currency validation');
        $manager = $this->syntheticMember($workspace, 'Currency manager');
        $this->actingAs($manager)->createSchedule($workspace, $company,
            array_replace($this->facts(), ['starts_on' => '2028-01-01', 'cadence' => 'monthly', 'currency' => 'αβγ']))
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertSame(0, ClientExpenseSchedule::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_first_occurrence_rejects_dates_below_the_database_range(): void
    {
        $workspace = $this->syntheticWorkspace('Date floor');
        $company = $this->syntheticCompany($workspace, 'Date floor');
        $manager = $this->syntheticMember($workspace, 'Date floor manager');
        foreach (['0001-01-01', '0999-12-31'] as $date) {
            $this->actingAs($manager)->createSchedule($workspace, $company,
                $this->facts() + ['starts_on' => $date, 'cadence' => 'monthly'])
                ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        }
        $this->assertSame(0, ClientExpenseSchedule::query()->where('workspace_id', $workspace->id)->count());
        $this->createSchedule($workspace, $company,
            $this->facts() + ['starts_on' => '1000-01-01', 'cadence' => 'monthly'])->assertSuccessful();
        $this->assertSame('1000-01-01', ClientExpenseSchedule::query()->where('workspace_id', $workspace->id)->sole()->starts_on->toDateString());
    }

    public function test_description_only_edit_preserves_an_amount_above_javascript_integer_precision(): void
    {
        $workspace = $this->syntheticWorkspace('Exact amount');
        $company = $this->syntheticCompany($workspace, 'Exact amount');
        $manager = $this->syntheticMember($workspace, 'Exact amount manager');
        $amount = '9007199254740993';
        $this->actingAs($manager)->createSchedule($workspace, $company,
            array_replace($this->facts(), ['amount' => $amount, 'starts_on' => '2028-01-01', 'cadence' => 'monthly']))->assertSuccessful();
        $schedule = ClientExpenseSchedule::query()->where('workspace_id', $workspace->id)->sole();
        $this->get(route('clients.expense-schedules', [$workspace, $company]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('schedules.0.amount_exact', $amount));
        $this->updateSchedule($workspace, $schedule,
            array_replace($this->facts(), ['amount' => $amount, 'description' => 'Synthetic edited description', 'active' => true]))->assertSuccessful();
        $this->assertSame(9007199254740993, $schedule->refresh()->amount);
        $this->assertSame('Synthetic edited description', $schedule->description);
    }

    /** @param array<string, mixed> $facts */
    private function createSchedule(Workspace $workspace, ClientCompany $company, array $facts): TestResponse
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            route('agent-api.v1.expense-schedules.store', $workspace),
            ['company_id' => $company->public_id, 'expected_version' => AgentApiVersion::for($company->fresh()), ...$facts],
        );
        app('auth')->shouldUse('web');

        return $response;
    }

    /** @param array<string, mixed> $facts */
    private function updateSchedule(Workspace $workspace, ClientExpenseSchedule $schedule, array $facts): TestResponse
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->patchJson(
            route('agent-api.v1.expense-schedules.update', [$workspace, $schedule->public_id]),
            ['expected_version' => AgentApiVersion::for($schedule->fresh()), ...$facts],
        );
        app('auth')->shouldUse('web');

        return $response;
    }

    private function generateSchedule(Workspace $workspace, ClientExpenseSchedule $schedule): TestResponse
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(
            route('agent-api.v1.expense-schedules.generate', [$workspace, $schedule->public_id]),
            ['expected_version' => AgentApiVersion::for($schedule->fresh()), 'confirm' => true],
        );
        app('auth')->shouldUse('web');

        return $response;
    }

    private function facts(int $amount = 1200): array
    {
        return ['amount' => $amount, 'currency' => 'USD', 'description' => 'Synthetic recurring cost'];
    }
}
