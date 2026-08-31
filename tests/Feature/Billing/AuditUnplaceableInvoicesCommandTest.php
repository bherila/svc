<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The audit behind the fail-closed overage window.
 *
 * Four conditions decide whether an unplaceable invoice can reach a billed
 * overage sum, and each is asserted here by its own exclusion rather than by
 * one happy path. An audit that counted every invoice with a missing service
 * period would report a population several times the real one, and "none of
 * them touches money" is the answer this command exists to be trusted about.
 */
final class AuditUnplaceableInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create([
            'name' => 'Unplaceable Workspace',
            'slug' => 'unplaceable-workspace',
        ]);

        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Unplaceable Client',
            'slug' => 'unplaceable-client',
        ]);

        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Undated retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
    }

    public function test_a_charged_invoice_with_overage_and_no_period_is_counted(): void
    {
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5.5']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['affected']);
        $this->assertSame(5.5, $summary['overage_hours_at_stake']);
    }

    public function test_an_invoice_with_a_service_period_is_not_counted(): void
    {
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'service_period_end' => '2026-01-31',
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['invoices']);
        $this->assertSame(0, $summary['without_a_service_period']);
        $this->assertSame(0, $summary['affected']);
    }

    public function test_an_uncharged_invoice_is_not_counted(): void
    {
        // A draft is excluded from the overage sum by status before the date
        // window is ever reached, so a missing period on one costs nothing.
        $this->invoice(['status' => 'draft', 'hours_billed_at_rate' => '5']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['without_a_service_period'], 'It has no period');
        $this->assertSame(0, $summary['charged_of_those'], 'But it has charged nobody');
        $this->assertSame(0, $summary['affected']);
    }

    public function test_an_invoice_on_no_agreement_is_not_counted(): void
    {
        // The sum is taken per agreement. An invoice naming none is never one
        // of the rows it reads, whatever its period says.
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'client_agreement_id' => null,
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['charged_of_those'], 'Charged with no period');
        $this->assertSame(0, $summary['on_an_agreement_of_those'], 'But belongs to no sum');
        $this->assertSame(0, $summary['affected']);
    }

    public function test_an_invoice_carrying_no_overage_is_not_counted(): void
    {
        // It is inside the sum and contributes zero. Reporting it as at stake
        // would put the largest population in the report on rows that cannot
        // change any number.
        $this->invoice(['status' => 'paid', 'hours_billed_at_rate' => null]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['on_an_agreement_of_those'], 'It reaches the sum');
        $this->assertSame(0, $summary['affected'], 'And adds nothing to it');
        $this->assertSame(0.0, $summary['overage_hours_at_stake']);
    }

    public function test_negative_overage_hours_are_counted_not_hidden(): void
    {
        // The sum carries no sign condition: a negative row shrinks billed
        // overage exactly as a positive one grows it. A `> 0` filter here
        // would print the all-clear while a fallback-placed row was actively
        // moving balances - the one statement this command must not get wrong.
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5.5']);
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '-3']);

        $summary = $this->summary();

        $this->assertSame(2, $summary['affected']);
        $this->assertSame(8.5, $summary['overage_hours_at_stake'], 'Magnitude, so signs cannot cancel');
    }

    public function test_an_invoice_whose_agreement_is_dangling_or_foreign_is_not_counted(): void
    {
        // The sum filters on agreement id and workspace together, and the
        // agreement column is unconstrained lineage - a row can name an
        // agreement that no longer exists, or one in another tenant. No sum
        // ever reads such a row, so counting it would overstate the
        // population this command exists to bound.
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'client_agreement_id' => $this->agreement->id + 424242,
        ]);

        $elsewhere = Workspace::query()->create(['name' => 'Foreign Workspace', 'slug' => 'foreign-workspace']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $elsewhere->id, 'name' => 'Foreign Client', 'slug' => 'foreign-client',
        ]);
        $foreignAgreement = ClientAgreement::query()->create([
            'workspace_id' => $elsewhere->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Their retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '7',
            'client_agreement_id' => $foreignAgreement->id,
        ]);

        $summary = $this->summary();

        $this->assertSame(2, $summary['charged_of_those'], 'Both are charged with no period');
        $this->assertSame(0, $summary['on_an_agreement_of_those'], 'But neither belongs to any sum');
        $this->assertSame(0, $summary['affected']);
    }

    // ── The cycle columns (#141) ─────────────────────────────────────────────

    public function test_an_invoice_naming_both_cycle_dates_is_not_counted(): void
    {
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'cycle_start' => '2026-01-01',
            'cycle_end' => '2026-01-31',
        ]);

        $summary = $this->summary();

        $this->assertSame(0, $summary['without_a_cycle']);
        $this->assertSame(0, $summary['live_without_a_cycle']);
        $this->assertSame(0, $summary['cycle_affected']);
    }

    public function test_a_half_named_cycle_is_as_unmatchable_as_no_cycle_at_all(): void
    {
        // `cycleInvoices()` matches on both columns, so either one missing is
        // enough to make the row invisible to every caller. Counting only rows
        // that are missing both would report a fraction of the population.
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'cycle_start' => '2026-01-01',
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['without_a_cycle'], 'A start alone matches nothing');
        $this->assertSame(1, $summary['cycle_affected']);
    }

    public function test_an_invoice_of_a_kind_no_cycle_lookup_reads_is_not_counted(): void
    {
        // The condition real data put here. Every cycle lookup filters by kind
        // before it compares cycle dates, so an ad-hoc row is excluded before
        // its columns are read and a null there cannot reach a sum or a guard.
        // Counting it reported an exposed population where there was none.
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5', 'invoice_kind' => 'ad_hoc']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['without_a_cycle'], 'It names no cycle');
        $this->assertSame(0, $summary['of_a_kind_read_by_cycle'], 'But nothing looks it up by one');
        $this->assertSame(0, $summary['live_without_a_cycle']);
        $this->assertSame(0, $summary['cycle_affected']);
    }

    public function test_each_kind_matched_by_cycle_is_counted_on_its_own(): void
    {
        // Both kinds, separately. `cycleInvoices()` is called for interim
        // overage and the resell guards match cadence periods, so dropping
        // either from the rule would leave a whole class of exposed invoice
        // unreported while the other kept the count non-zero and plausible.
        // `terminal` is deliberately absent: no cycle lookup reads it.
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5', 'invoice_kind' => 'cadence_period']);

        $this->assertSame(1, $this->summary()['of_a_kind_read_by_cycle'], 'A cadence period is matched by cycle');

        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '3', 'invoice_kind' => 'interim_overage']);

        $this->assertSame(2, $this->summary()['of_a_kind_read_by_cycle'], 'And so is an interim overage');

        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '7', 'invoice_kind' => 'terminal']);

        $this->assertSame(2, $this->summary()['of_a_kind_read_by_cycle'], 'A terminal invoice is not');
    }

    public function test_a_migrated_invoice_carrying_no_kind_is_counted(): void
    {
        // The one exception, and it is deliberate: the cadence resell guard
        // reads a null kind on purpose, because a migrated invoice carries
        // none. `ClientInvoicingService:620` says so in as many words - that
        // exclusion was itself a defect once.
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5', 'invoice_kind' => null]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['of_a_kind_read_by_cycle'], 'A guard reads it');
        $this->assertSame(1, $summary['cycle_affected']);
    }

    public function test_a_draft_with_no_cycle_evades_the_guards_without_moving_a_sum(): void
    {
        // The two counts answer different questions and a draft separates them:
        // the duplicate guards read live statuses, so it is one of theirs, but
        // it has charged nobody and so cannot be billed a second time.
        $this->invoice(['status' => 'draft', 'hours_billed_at_rate' => '5']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['live_without_a_cycle'], 'A guard cannot see it');
        $this->assertSame(0, $summary['cycle_affected'], 'But no sum reads it either');
        $this->assertSame(0.0, $summary['cycle_overage_hours_at_stake']);
    }

    public function test_a_void_invoice_with_no_cycle_is_counted_by_neither(): void
    {
        // Void is neither live nor charged. It cannot be resold and cannot be
        // charged again, so reporting it would inflate both counts with rows
        // that no code path reads.
        $this->invoice(['status' => 'void', 'hours_billed_at_rate' => '5']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['without_a_cycle'], 'The row exists');
        $this->assertSame(0, $summary['live_without_a_cycle']);
        $this->assertSame(0, $summary['cycle_affected']);
    }

    public function test_the_cycle_count_and_the_period_count_do_not_leak_into_each_other(): void
    {
        // The structural risk in reporting two funnels from one command is that
        // one row satisfies both and is reported twice, or that a condition
        // written for one silently narrows the other. A row with a real service
        // period and no cycle belongs to the cycle funnel alone.
        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'service_period_end' => '2026-01-31',
        ]);

        $summary = $this->summary();

        $this->assertSame(0, $summary['without_a_service_period'], 'Its period is placed');
        $this->assertSame(0, $summary['affected']);
        $this->assertSame(0.0, $summary['overage_hours_at_stake']);

        $this->assertSame(1, $summary['cycle_affected'], 'Its cycle is not');
        $this->assertSame(5.0, $summary['cycle_overage_hours_at_stake']);
    }

    public function test_a_cycleless_invoice_on_a_foreign_agreement_is_not_counted(): void
    {
        // The cycle funnel reads the same lineage as the period one, and gets
        // the same treatment: the guards and sums filter agreement and
        // workspace together, so a row naming neither is not one of theirs.
        $elsewhere = Workspace::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $elsewhere->id, 'name' => 'Other Client', 'slug' => 'other-client',
        ]);
        $foreignAgreement = ClientAgreement::query()->create([
            'workspace_id' => $elsewhere->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Their retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
        ]);

        $this->invoice([
            'status' => 'issued',
            'hours_billed_at_rate' => '5',
            'client_agreement_id' => $foreignAgreement->id,
        ]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['without_a_cycle'], 'The row has no cycle');
        $this->assertSame(0, $summary['live_without_a_cycle'], 'But belongs to no guard');
        $this->assertSame(0, $summary['cycle_affected']);
    }

    public function test_the_report_names_no_workspace_company_agreement_or_invoice(): void
    {
        // The value of this command is that its output can be pasted into a
        // public issue. A count is safe to publish; the invoice number it
        // counted carries a client prefix and is not.
        $this->invoice(['status' => 'issued', 'hours_billed_at_rate' => '5', 'invoice_number' => 'UNPLACEABLE-1']);

        $this->assertSame(0, Artisan::call('svc:billing:audit-unplaceable-invoices'));
        $report = Artisan::output();

        $this->assertStringContainsString('Overage hours at stake', $report, 'The report ran');
        $this->assertStringContainsString('Cycle overage hours at stake', $report, 'Both funnels printed');

        $secrets = [
            'Unplaceable Workspace', 'Unplaceable Client', 'Undated retainer',
            'unplaceable-workspace', 'unplaceable-client', 'UNPLACEABLE-1',
        ];

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $report);
        }
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->artisan('svc:billing:audit-unplaceable-invoices --format=yaml')->assertExitCode(2);
    }

    /**
     * The summary as JSON.
     *
     * @return array<string, float|int>
     */
    private function summary(): array
    {
        $this->assertSame(0, Artisan::call('svc:billing:audit-unplaceable-invoices', ['--format' => 'json']));

        /** @var array{summary: array<string, float|int>} $decoded */
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['summary'];
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(array $attributes): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'UNPLACEABLE-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill($attributes)->save();

        return $invoice;
    }
}
