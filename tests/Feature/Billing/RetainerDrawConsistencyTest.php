<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLedgerBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Which work draws on the retainer?" has one answer everywhere it is asked.
 *
 * Three services ask it: the cadence generator, the ledger builder and the
 * interim overage generator. The predecessor answered it once, in
 * `scopeRetainerBillable()`, so all of its call sites agreed by construction.
 *
 * The port emptied that scope - the reasoning being that this schema has no
 * `subcontractor_billing_mode` column, which is true of the column and false of
 * the concept, since `subcontractor_cost_amount` is the same signal here. The
 * conditions were then reintroduced one caller at a time and reached one of the
 * three, so the ledger and the interim generator kept drawing on work the
 * cadence generator had already excluded.
 *
 * No migrated data is affected: every one of the 455 source time entries has a
 * null billing mode, so nothing has been mispriced. These tests pin the shape
 * before the first subcontractor entry makes it cost something.
 */
final class RetainerDrawConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Draw', 'slug' => 'draw']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Draw Client', 'slug' => 'draw-client',
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * Flat-hourly subcontractor work is billed as its own line at the rate
     * snapshotted on the entry. Letting it consume retainer pool as well means
     * the client pays for those hours twice: once against capacity they bought,
     * and once on the invoice line.
     */
    public function test_subcontractor_work_does_not_consume_retainer_pool_in_the_ledger(): void
    {
        $project = $this->project('Main');
        $agreement = $this->agreement();

        $this->entry($project, '2024-01-10', 300);
        $this->entry($project, '2024-01-15', 300, subcontractorCost: 9000);

        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $this->company,
            $agreement,
            Carbon::parse('2024-01-31'),
            false,
        );

        $hours = array_sum(array_map(static fn ($month): float => $month->hoursWorked, $ledger));

        $this->assertSame(5.0, $hours, 'Only the consultant 5h draws on the retainer');
    }

    /**
     * The same question, asked by the interim generator.
     */
    public function test_subcontractor_work_is_not_drawn_into_an_interim_overage(): void
    {
        $project = $this->project('Main');
        $agreement = $this->quarterlyAgreement();

        // Ten hours of subcontractor work against a two-hour retainer. If it
        // counted, the interim run would find eight hours of overage.
        $this->entry($project, '2024-01-10', 600, subcontractorCost: 9000);

        $invoice = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            $agreement,
        );

        $this->assertNull($invoice, 'Subcontractor hours are billed by the composer, not drawn as overage');
    }

    /**
     * A project-scoped agreement's interim overage counted every project's
     * work, while the cadence generator and the ledger both scoped correctly.
     */
    public function test_an_interim_overage_ignores_work_outside_the_agreements_project(): void
    {
        $mine = $this->project('Mine');
        $theirs = $this->project('Theirs');
        $agreement = $this->quarterlyAgreement($mine);

        // Only the other project is over the retainer.
        $this->entry($theirs, '2024-01-12', 600);

        $invoice = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            $agreement,
        );

        $this->assertNull($invoice, "The other project draws on its own agreement's retainer");
    }

    /**
     * A company-wide agreement is unchanged: with no project set, everything
     * the company did still counts, and the overage is real.
     */
    public function test_a_company_wide_agreement_still_bills_an_interim_overage(): void
    {
        $first = $this->project('First');
        $second = $this->project('Second');
        $agreement = $this->quarterlyAgreement();

        $this->entry($first, '2024-01-12', 300);
        $this->entry($second, '2024-01-13', 300);

        $invoice = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            $agreement,
        );

        $this->assertInstanceOf(ClientInvoice::class, $invoice);
    }

    /**
     * The structural guard. Any query that selects work for the retainer must
     * go through the scope rather than restating its conditions, because a
     * restatement is what diverged last time.
     */
    public function test_no_billing_service_restates_the_retainer_draw_conditions(): void
    {
        $offenders = [];

        foreach (['ClientInvoicingService', 'InterimOverageGenerator', 'InvoiceLedgerBuilder'] as $service) {
            $relative = "app/Services/Billing/{$service}.php";
            $contents = file_get_contents(base_path($relative));
            if ($contents === false) {
                $this->fail("Could not read {$relative}");
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (str_contains($line, 'subcontractor_cost_amount')
                    || (str_contains($line, "'client_project_id'") && str_contains($line, 'where'))) {
                    $offenders[] = sprintf('%s:%d  %s', $relative, $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These lines restate what draws on the retainer instead of asking the model.\n\n%s\n\n".
            'Use ClientTimeEntry::scopeRetainerBillable() and scopeForAgreementScope() so every '.
            'caller gets the same answer.',
            implode("\n", $offenders),
        ));
    }

    /**
     * The stronger guard, added after this same omission was found a fourth
     * time.
     *
     * Naming the conditions on the model was not enough: a caller could still
     * select time entries for an agreement and forget to narrow them to that
     * agreement's project. Four sites in the monthly path had, while the ledger
     * builder and the interim generator had not, so a project-scoped agreement
     * picked up a sibling project's hours as its own debt.
     *
     * Any query that selects entries for a specific agreement has to say which
     * agreement's work it means.
     */
    public function test_every_agreement_scoped_entry_query_narrows_to_the_agreement(): void
    {
        $offenders = [];

        foreach (['ClientInvoicingService', 'InterimOverageGenerator', 'InvoiceLedgerBuilder'] as $service) {
            $relative = "app/Services/Billing/{$service}.php";
            $contents = file_get_contents(base_path($relative));
            if ($contents === false) {
                $this->fail("Could not read {$relative}");
            }

            // Each ClientTimeEntry query is one statement; split on the
            // terminator so a query's clauses are examined together.
            foreach (explode(';', $contents) as $statement) {
                if (! str_contains($statement, 'ClientTimeEntry::query()')) {
                    continue;
                }
                if (! str_contains($statement, 'retainerBillable()') && ! str_contains($statement, 'billableForInvoicing()')) {
                    continue;
                }
                if (str_contains($statement, 'forAgreementScope(')) {
                    continue;
                }

                $offenders[] = $relative.': '.trim(explode("\n", trim($statement))[0]);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These queries select an agreement's billable work without narrowing it to that agreement.\n\n%s\n\n".
            'Add ->forAgreementScope($agreement); a project-scoped agreement must not draw on another project.',
            implode("\n", $offenders),
        ));
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => $name,
        ]);
    }

    private function agreement(?ClientProject $project = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project?->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);
    }

    private function quarterlyAgreement(?ClientProject $project = null): ClientAgreement
    {
        $agreement = $this->agreement($project);
        $agreement->forceFill([
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
            'retainer_minutes' => 120,
        ])->save();

        return $agreement->refresh();
    }

    private function entry(ClientProject $project, string $workedOn, int $minutes, ?int $subcontractorCost = null): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
            'subcontractor_cost_amount' => $subcontractorCost,
        ]);
    }
}
