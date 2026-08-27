<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The replay harness has to be trustworthy in two opposite directions: it must
 * notice when the engine no longer reproduces history, and it must never write
 * anything while finding out. A harness that silently passes is useless; one
 * that mutates production data to run is dangerous.
 */
final class ReplayInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Replay', 'slug' => 'replay']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Replay Client', 'slug' => 'replay-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Replay Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_requires_a_workspace(): void
    {
        $this->artisan('svc:billing:replay')->assertFailed();
        $this->artisan('svc:billing:replay', ['--workspace' => 'nope'])->assertFailed();
    }

    /**
     * Invoices the current engine produced are, by definition, reproducible by
     * the current engine. This is the control: if it fails, the harness is
     * comparing the wrong things.
     */
    public function test_invoices_the_engine_produced_replay_to_the_cent(): void
    {
        $this->generatedHistory();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('money identical')
            ->assertSuccessful();
    }

    /**
     * The harness must fail when history and the engine disagree. Editing a
     * stored total stands in for the engine having drifted.
     */
    public function test_it_fails_when_a_stored_invoice_no_longer_reproduces(): void
    {
        $invoice = $this->generatedHistory();

        // History says this invoice was worth 1.00 more than the engine now says.
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->assertFailed();
    }

    /**
     * The safety property. The command deletes and regenerates every invoice to
     * do its work, so the only thing standing between it and production data is
     * the unconditional rollback.
     */
    public function test_it_leaves_the_database_exactly_as_it_found_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $before = $this->fingerprint();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertFailed();

        $this->assertSame($before, $this->fingerprint(), 'The replay must not change a single row');
    }

    /**
     * An operator-typed invoice has no generator that would reproduce it, so
     * counting it as a divergence would make every real run fail.
     */
    public function test_ad_hoc_invoices_are_set_aside_rather_than_failed(): void
    {
        $this->generatedHistory();

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2023-01-01',
            'service_period_end' => '2023-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => (int) ClientInvoice::query()->where('invoice_number', 'SVC-ADHOC')->value('id'),
            'type' => 'additional_hours', 'description' => 'One-off', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('ad-hoc')
            ->assertSuccessful();
    }

    /**
     * A whole-database fingerprint, so the safety assertion cannot pass by
     * checking only the rows that happened to be remembered.
     */
    private function fingerprint(): string
    {
        $parts = [];

        foreach (['client_invoices', 'client_invoice_lines', 'client_invoice_line_time_entries', 'client_time_entries', 'client_tasks', 'workspace_invoice_counters'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $parts[] = $table.':'.md5((string) json_encode($rows));
        }

        return implode('|', $parts);
    }

    private function generatedHistory(): ClientInvoice
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 2,
        ]);

        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-02-14',
            'minutes' => 900,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        // The whole history, as the original system would have produced it -
        // one hand-made invoice would leave every later cycle looking like a
        // divergence the moment the replay walked past it.
        Carbon::setTestNow(Carbon::parse('2024-06-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }

        return ClientInvoice::query()->orderByDesc('id')->firstOrFail();
    }
}
