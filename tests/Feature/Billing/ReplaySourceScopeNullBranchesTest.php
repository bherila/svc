<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Replay builds no source boundary for an invoice missing either end of its
 * service period, and each end has to be shown to do that on its own.
 *
 * `ReplayInvoicesCommand::sourceScopeForInvoice()` returns null unless it has an
 * agreement *and* both boundaries. That null is not inert: the snapshot's
 * `source_agreement_rate_minutes` is the aggregate that proves a priced line is
 * backed by eligible work, and with no scope every entry fails the containment
 * test and the aggregate is zero. The invoice then proves itself against zero
 * source minutes rather than against its own, so replay reports it as
 * unsupported - or, worse, accepts a rebuild that dropped the work, because
 * "nothing eligible" is what both look like.
 *
 * `source_minutes`, the total allocation, is deliberately unaffected: it stays
 * at the real figure, and the gap between the two aggregates is the shape of
 * the failure. Both tests assert it, so a change that quietly dropped the
 * allocation as well would not pass as this one.
 *
 * The two boundaries are separate branches of one `||`, which is exactly the
 * construction #143 refuses to accept a shared citation for: nulling both at
 * once leaves either half deletable with the test still green. So each test
 * here nulls one boundary and holds the other - and the agreement - set.
 */
final class ReplaySourceScopeNullBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private ClientInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Replay Scope', 'slug' => 'replay-scope']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Replay Scope Client',
            'slug' => 'replay-scope-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Replay Scope Project',
        ]);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'title' => 'Replay Scope Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
        ]);
        $this->invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'REPLAY-SCOPE',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-01-01',
            'cycle_end' => '2026-01-31',
            'service_period_start' => '2026-01-01',
            'service_period_end' => '2026-01-31',
            'subtotal_amount' => 20000,
            'tax_amount' => 0,
            'total_amount' => 20000,
        ]);

        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $this->invoice->id,
            'client_agreement_id' => $this->agreement->id,
            'client_project_id' => $this->project->id,
            'type' => 'additional_hours',
            'description' => 'Synthetic replay scope work',
            'quantity' => '1.0000',
            'hours' => '1.0000',
            'line_date' => '2026-01-15',
            'unit_amount' => 20000,
            'tax_amount' => 0,
            'total_amount' => 20000,
            'sort_order' => 1,
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-01-15',
            'minutes' => 60,
            'description' => 'Synthetic replay scope source',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
    }

    /** No stated period start, so no scope, so nothing is provably in scope. */
    public function test_an_invoice_with_no_period_start_proves_no_source_minutes(): void
    {
        $this->assertSame(60, $this->snapshotLine()['source_agreement_rate_minutes']);

        $this->invoice->forceFill(['service_period_start' => null])->save();

        $line = $this->snapshotLine();
        $this->assertSame(0, $line['source_agreement_rate_minutes'], 'A half-dated invoice can prove nothing');
        $this->assertSame(60, $line['source_minutes'], 'The allocation itself is untouched');
    }

    /** And the same for the other end, which is a separate branch of the guard. */
    public function test_an_invoice_with_no_period_end_proves_no_source_minutes(): void
    {
        $this->assertSame(60, $this->snapshotLine()['source_agreement_rate_minutes']);

        $this->invoice->forceFill(['service_period_end' => null])->save();

        $line = $this->snapshotLine();
        $this->assertSame(0, $line['source_agreement_rate_minutes'], 'A half-dated invoice can prove nothing');
        $this->assertSame(60, $line['source_minutes'], 'The allocation itself is untouched');
    }

    /** @return array<string, mixed> */
    private function snapshotLine(): array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = (new ReflectionMethod(ReplayInvoicesCommand::class, 'snapshot'))->invoke(
            app(ReplayInvoicesCommand::class),
            $this->workspace,
            collect([$this->company]),
        );

        /** @var array<string, mixed> $line */
        $line = array_values($rows)[0]['lines'][0];

        return $line;
    }
}
