<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingScheduleService;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The scheduled cadence-draft run creates what invoices.generate_period would
 * for each current period billing_audit.stale_and_missing reports missing, and
 * nothing more: drafts only, once, workspace by workspace, and never on an
 * agreement whose billing schedule bills it.
 */
final class GenerateCadenceDraftsCommandTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 12:00:00');
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
    }

    public function test_preview_writes_nothing_and_apply_creates_each_missing_draft_once(): void
    {
        [$workspace, , $company, $agreement, $project] = $this->fixture();
        $this->entry($workspace, $company, $project, '2026-09-10', 700);

        $preview = $this->run_([]);
        $this->assertSame(0, $preview['exit']);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame([['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']], $preview['would_generate']);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());

        $applied = $this->run_(['--apply' => true]);
        $this->assertSame(0, $applied['exit']);
        $this->assertCount(1, $applied['generated']);
        $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($draft->public_id, $applied['generated'][0]['invoice_id']);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('cadence_period', $draft->invoice_kind);
        $this->assertSame('2026-10-01', $draft->cycle_start->toDateString());
        $this->assertSame('2026-09-30', $draft->service_period_end->toDateString());
        $this->assertSame($agreement->id, $draft->client_agreement_id);

        $again = $this->run_(['--apply' => true]);
        $this->assertSame(0, $again['exit']);
        $this->assertSame([], $again['generated']);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    /**
     * The command visits exactly the periods the audit reports missing, less
     * those a billing schedule owns, because both read one query.
     */
    public function test_it_generates_exactly_the_periods_the_audit_reports_missing(): void
    {
        [$workspace, $owner] = $this->fixture();
        // One client each: the engine refuses overlapping agreements of one client.
        $this->agreement($workspace, $this->client($workspace, 'quarterly'), ['title' => 'Synthetic Quarterly', 'billing_cadence' => 'quarterly', 'starts_on' => '2026-01-15', 'period_retainer_minutes' => 600, 'period_retainer_amount' => 300000]);
        $this->agreement($workspace, $this->client($workspace, 'ended'), ['title' => 'Synthetic Ended', 'ends_on' => '2026-09-30']);
        $this->agreement($workspace, $this->client($workspace, 'terminated'), ['title' => 'Synthetic Terminated', 'status' => 'terminated']);
        $this->agreement($workspace, $this->client($workspace, 'one-time'), ['title' => 'Synthetic One Time', 'billing_cadence' => 'one_time']);
        $this->agreement($workspace, $this->client($workspace, 'future'), ['title' => 'Synthetic Future', 'starts_on' => '2026-11-01']);

        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'billing:read']);
        $missing = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/billing-audits/stale-and-missing')->assertOk()->json('data.missing_periods');
        $this->assertCount(2, $missing);

        $preview = $this->run_([]);
        $this->assertEqualsCanonicalizing($missing, array_map(static fn (array $row): array => array_diff_key($row, ['workspace_id' => true]), $preview['would_generate']));

        $this->run_(['--apply' => true]);
        $this->assertSame([], $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/billing-audits/stale-and-missing')->assertOk()->json('data.missing_periods'));
    }

    public function test_a_period_already_billed_is_left_alone_and_a_voided_one_is_regenerated(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture();
        $this->entry($workspace, $company, $project, '2026-09-10', 60);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'billing:read', 'billing:write']);
        $id = $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true], ['Idempotency-Key' => 'operator'])
            ->assertCreated()->json('data.id');

        $this->assertSame([], $this->run_(['--apply' => true])['generated']);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());

        // A status this code does not recognise still claims the period: the
        // run cannot show it is safe to bill again.
        $claimed = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
        $claimed->forceFill(['status' => 'synthetic_unrecognised'])->save();
        $this->assertSame([], $this->run_(['--apply' => true])['generated']);
        $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement->refresh()), 'period_start' => '2026-10-01', 'confirm' => true], ['Idempotency-Key' => 'operator-again'])
            ->assertConflict();

        $claimed->forceFill(['status' => 'void'])->save();
        $this->assertCount(1, $this->run_(['--apply' => true])['generated']);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', 'draft')->count());
    }

    /**
     * A draft on a scheduled agreement would claim the period without billing
     * it, and the schedule halts on a pending draft - so the schedule must
     * still bill October after this command has run.
     */
    public function test_an_agreement_billed_by_its_schedule_is_left_to_the_schedule(): void
    {
        [$workspace, , $company] = $this->fixture();
        $scheduled = $this->agreement($workspace, $company, ['title' => 'Synthetic Scheduled', 'retainer_minutes' => null, 'retainer_amount' => null]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $scheduled->id,
            'cadence' => 'monthly', 'anchor_day' => 1, 'next_run_on' => '2026-10-01', 'due_days' => 30, 'currency' => 'USD', 'is_active' => true,
            'line_template' => [['type' => 'service', 'description' => 'Synthetic scheduled service', 'quantity' => '1', 'unit_amount' => 100]],
        ]);

        $applied = $this->run_(['--apply' => true]);
        $this->assertNotContains($scheduled->public_id, array_column($applied['generated'], 'agreement_id'));
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $scheduled->id)->count());

        $billed = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-10-08'));
        $this->assertCount(1, $billed);
        $this->assertSame('issued', $billed[0]->status);

        // An inactive schedule bills nothing, so its agreement is the command's again.
        $schedule->forceFill(['is_active' => false])->save();
        $billed[0]->forceFill(['status' => 'void'])->save();
        $this->assertContains($scheduled->public_id, array_column($this->run_([])['would_generate'], 'agreement_id'));
    }

    public function test_one_refusal_neither_blocks_nor_undoes_another_agreement_and_fails_the_run(): void
    {
        [$workspace, , $company, $agreement] = $this->fixture();
        $healthy = $this->agreement($workspace, $this->client($workspace, 'second'));
        // A partial overlap the engine refuses, as in the period preview tests.
        ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-OVERLAP', 'invoice_kind' => 'cadence_period', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 0, 'balance_amount' => 0, 'service_period_start' => '2026-09-10', 'service_period_end' => '2026-09-15']);

        $applied = $this->run_(['--apply' => true]);
        $this->assertSame(1, $applied['exit']);
        $this->assertSame([$agreement->public_id], array_column($applied['failed'], 'agreement_id'));
        $this->assertNotSame('', $applied['failed'][0]['reason']);
        $this->assertSame([$healthy->public_id], array_column($applied['generated'], 'agreement_id'));
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $agreement->id)->count());
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $healthy->id)->where('status', 'draft')->count());
    }

    public function test_workspace_option_and_limit_bound_the_run_and_nothing_is_issued_or_sent(): void
    {
        Mail::fake();
        [$workspace] = $this->fixture();
        $this->agreement($workspace, $this->client($workspace, 'second'), ['title' => 'Synthetic Second Retainer']);
        [$foreign, , , $foreignAgreement] = $this->fixture();

        $this->assertSame(2, Artisan::call('svc:billing:generate-cadence-drafts', ['--workspace' => 'synthetic-not-a-workspace', '--format' => 'json']));

        $limited = $this->run_(['--workspace' => $workspace->public_id, '--limit' => 1, '--apply' => true]);
        $this->assertCount(1, $limited['generated']);
        $this->assertSame(1, $limited['not_reached']);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $foreign->id)->count(), 'A workspace-scoped run touches no other workspace.');

        $rest = $this->run_(['--apply' => true]);
        $this->assertCount(2, $rest['generated']);
        $this->assertContains($foreignAgreement->public_id, array_column($rest['generated'], 'agreement_id'));
        $this->assertSame(0, ClientInvoice::query()->where('status', '!=', 'draft')->count());
        Mail::assertNothingSent();
    }

    public function test_the_hourly_schedule_is_withheld_until_scheduled_cadence_drafts_is_enabled(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains($event->command ?? '', 'svc:billing:generate-cadence-drafts')));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('--apply', (string) $events[0]->command);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);

        $this->assertFalse(config('svc.billing.scheduled_cadence_drafts'));
        $this->assertFalse($events[0]->filtersPass($this->app));
        config(['svc.billing.scheduled_cadence_drafts' => true]);
        $this->assertTrue($events[0]->filtersPass($this->app));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function run_(array $options): array
    {
        $exit = Artisan::call('svc:billing:generate-cadence-drafts', [...$options, '--format' => 'json']);
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return [...$data, 'exit' => $exit];
    }

    /** @return array{Workspace, User, ClientCompany, ClientAgreement, ClientProject} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Cadence Drafts', 'slug' => 'synthetic-cadence-drafts-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Drafts Client', 'slug' => 'synthetic-drafts-client', 'billing_email' => 'billing@synthetic.example.test']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Drafts Project']);

        return [$workspace, $owner, $company, $this->agreement($workspace, $company), $project];
    }

    private function client(Workspace $workspace, string $name): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name.' Client', 'slug' => 'synthetic-'.$name.'-client', 'billing_email' => $name.'@synthetic.example.test']);
    }

    /** @param array<string, mixed> $terms */
    private function agreement(Workspace $workspace, ClientCompany $company, array $terms = []): ClientAgreement
    {
        return ClientAgreement::query()->create($terms + ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Retainer', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 100000]);
    }

    private function entry(Workspace $workspace, ClientCompany $company, ClientProject $project, string $date, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->memberships()->where('role', 'owner')->value('user_id'), 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'worked_on' => $date, 'minutes' => $minutes, 'description' => 'Synthetic approved work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }
}
