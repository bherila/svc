<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\UndatedCollectibleInvoiceRepairer;
use App\Support\Billing\EligibleSetChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #149's repair: date the collectible invoices that were never dated.
 *
 * A collectible invoice with a null `due_date` sits in collectible balances and
 * in no overdue figure, because SQL answers false for a null rather than
 * unknown, so the two reported numbers disagree with nothing to say why.
 *
 * The repair gives it the date `issue()` would have given it. That transition
 * defaults a null due date to the issue date and returns early for an invoice
 * already charged, which is precisely how an imported issued or paid row keeps
 * its null forever - so this applies the native contract to rows that arrived
 * past the point that states it, rather than inventing anything.
 *
 * What these tests pin is mostly what the repair must *not* touch. A repair
 * that dates too much is worse than the defect: it states a term nobody agreed
 * and moves invoices into a collections-adjacent report on no evidence.
 */
final class BackfillInvoiceDueDatesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Dues', 'slug' => 'dues']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Dues Client', 'slug' => 'dues-client',
        ]);
    }

    public function test_it_dates_a_collectible_invoice_from_its_issue_date(): void
    {
        $invoice = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true);

        $this->assertSame(1, $result->eligible);
        $this->assertSame(1, $result->repaired);
        $this->assertTrue($result->applied);
        $this->assertSame('2026-01-15', $invoice->refresh()->due_date?->format('Y-m-d'));
    }

    /**
     * The dry run is the same code path and writes nothing.
     *
     * This is what an operator reads before approving the repair, so it has to
     * count what the write would touch - a preview produced by a second
     * implementation is a preview of a different repair.
     */
    public function test_a_dry_run_counts_without_writing(): void
    {
        $invoice = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace);

        $this->assertSame(1, $result->eligible);
        $this->assertSame(0, $result->repaired);
        $this->assertFalse($result->applied);
        $this->assertNull($invoice->refresh()->due_date);
    }

    /**
     * An invoice with no issue date is counted and left alone.
     *
     * There is no defensible due date for it, and guessing one is the thing the
     * whole issue argues against. A non-zero count here is what would make
     * #149's option (2) - a separate `undated_collectible` bucket - necessary
     * rather than merely available.
     */
    public function test_an_invoice_with_no_issue_date_is_reported_not_guessed_at(): void
    {
        $undatable = $this->invoice(status: 'issued', balance: 50000, issueDate: null);

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true);

        $this->assertSame(0, $result->repaired);
        $this->assertSame(1, $result->skippedWithoutAnIssueDate);
        $this->assertTrue($result->leavesAnUndatedRemainder());
        $this->assertNull($undatable->refresh()->due_date);
    }

    /**
     * Every charged invoice is dated; a draft and a stated date are left alone.
     *
     * The repair is deliberately wider than the audit. A *paid* invoice with no
     * due date is in neither reported figure and looks harmless - until
     * `InvoiceLifecycleService::refreshStatus()` moves it back to
     * `partially_paid` after a refund, when it is collectible and undated and
     * the whole defect is back. The audit measures what disagrees today; the
     * repair covers what can disagree at all.
     *
     * A draft stays out: it is owed by nobody, and `issue()` states its due date
     * in the ordinary way if it is ever issued - dating it here would state a
     * term nobody has been given. And an invoice that already states a due date
     * is never overwritten, asserted because replacing a stated term with a
     * derived one is the one way this repair could destroy information.
     */
    public function test_it_dates_every_charged_invoice_but_no_draft(): void
    {
        $draft = $this->invoice(status: 'draft', balance: 50000, issueDate: '2026-01-15');
        $paid = $this->invoice(status: 'paid', balance: 0, issueDate: '2026-01-16');
        $settled = $this->invoice(status: 'partially_paid', balance: 0, issueDate: '2026-01-17');
        $alreadyDated = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-18');
        $alreadyDated->forceFill(['due_date' => '2026-02-28'])->save();

        // One minor unit is a balance. The boundary is `> 0`, not "a meaningful
        // amount", and an invoice owing a cent is as collectible as one owing a
        // thousand.
        $owesOneUnit = $this->invoice(status: 'issued', balance: 1, issueDate: '2026-01-19');

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true);

        $this->assertSame(3, $result->eligible);
        $this->assertSame(3, $result->repaired);

        // Charged, so dated - including the two a refund could reopen.
        $this->assertSame('2026-01-16', $paid->refresh()->due_date?->format('Y-m-d'));
        $this->assertSame('2026-01-17', $settled->refresh()->due_date?->format('Y-m-d'));
        $this->assertSame('2026-01-19', $owesOneUnit->refresh()->due_date?->format('Y-m-d'));

        // Not charged, or already stated.
        $this->assertNull($draft->refresh()->due_date);
        $this->assertSame('2026-02-28', $alreadyDated->refresh()->due_date?->format('Y-m-d'));
    }

    /**
     * A refund cannot reopen an undated invoice into the collectible set.
     *
     * The end-to-end form of the test above: date a paid invoice, then move it
     * back to collectible the way a refund does, and confirm it arrives with a
     * due date rather than recreating the defect.
     */
    public function test_a_repaired_paid_invoice_stays_dated_when_a_refund_reopens_it(): void
    {
        $paid = $this->invoice(status: 'paid', balance: 0, issueDate: '2026-01-16');

        app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true);

        // What a partial refund leaves behind.
        $paid->forceFill(['status' => 'partially_paid', 'paid_amount' => 20000, 'balance_amount' => 30000])->save();

        $this->assertSame('2026-01-16', $paid->refresh()->due_date?->format('Y-m-d'));
        $this->assertSame(0, app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace)->eligible);
    }

    /** The repair is scopeable, so a tenant-facing caller cannot reach another. */
    public function test_a_scoped_repair_touches_only_its_own_workspace(): void
    {
        $mine = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other-dues']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Other Client', 'slug' => 'other-dues-client',
        ]);
        $theirs = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15', workspace: $other, company: $otherCompany);

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true);

        $this->assertSame(1, $result->repaired);
        $this->assertSame('2026-01-15', $mine->refresh()->due_date?->format('Y-m-d'));
        $this->assertNull($theirs->refresh()->due_date);
    }

    public function test_the_command_writes_nothing_without_apply(): void
    {
        $invoice = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $this->artisan('svc:billing:backfill-invoice-due-dates')->assertSuccessful();
        $this->assertNull($invoice->refresh()->due_date);

        $this->artisan('svc:billing:backfill-invoice-due-dates', ['--apply' => true, '--force' => true])
            ->assertSuccessful();
        $this->assertSame('2026-01-15', $invoice->refresh()->due_date?->format('Y-m-d'));
    }

    /**
     * The JSON payload an operator or a script reads.
     *
     * Asserted key by key and value by value, because this is the only form the
     * repair reports in when it is not being watched - a missing or renamed key
     * is a silent failure for whatever consumes it, and the counts are the whole
     * point of the run.
     */
    public function test_the_json_output_reports_every_count(): void
    {
        $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');
        $this->invoice(status: 'issued', balance: 50000, issueDate: null);

        $this->artisan('svc:billing:backfill-invoice-due-dates', ['--apply' => true, '--force' => true, '--format' => 'json'])
            ->expectsOutput(json_encode([
                'applied' => true,
                'totals' => ['eligible' => 1, 'repaired' => 1, 'skipped' => 1],
                'workspaces' => [
                    'dues' => [
                        'eligible' => 1,
                        'repaired' => 1,
                        'skipped_without_an_issue_date' => 1,
                        'applied' => true,
                    ],
                ],
            ]))
            ->assertSuccessful();
    }

    /**
     * Nothing left undated means nothing left undated.
     *
     * The boundary is `> 0`, and the sibling test only ever asserts the true
     * side. Without this, reporting a remainder for a run that left none would
     * pass - and that flag is what decides whether #149's option (2) is needed.
     */
    public function test_a_clean_run_reports_no_undated_remainder(): void
    {
        $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true);

        $this->assertSame(0, $result->skippedWithoutAnIssueDate);
        $this->assertFalse($result->leavesAnUndatedRemainder());
        $this->assertSame([
            'eligible' => 1,
            'repaired' => 1,
            'skipped_without_an_issue_date' => 0,
            'applied' => true,
        ], $result->toArray());
    }

    /** Declining the confirmation writes nothing. */
    public function test_the_command_writes_nothing_when_the_confirmation_is_declined(): void
    {
        $invoice = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $this->artisan('svc:billing:backfill-invoice-due-dates', ['--apply' => true])
            ->expectsConfirmation('Set the due date to the issue date on 1 collectible invoice(s) in dues?', 'no')
            ->assertSuccessful();

        $this->assertNull($invoice->refresh()->due_date);
    }

    /**
     * The repair cannot be run across every tenant in one statement.
     *
     * An unscoped *read* is the operator's view of every workspace at once; an
     * unscoped *write* is a single update mutating billing records in every
     * tenant, with no way to validate one client first and no bound on a
     * mistake. So the workspace is required, and the command walks them.
     */
    public function test_the_command_walks_workspaces_and_reports_each(): void
    {
        $mine = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $other = Workspace::query()->create(['name' => 'Second', 'slug' => 'second-dues']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Second Client', 'slug' => 'second-dues-client',
        ]);
        $theirs = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-16', workspace: $other, company: $otherCompany);

        $this->artisan('svc:billing:backfill-invoice-due-dates', ['--apply' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame('2026-01-15', $mine->refresh()->due_date?->format('Y-m-d'));
        $this->assertSame('2026-01-16', $theirs->refresh()->due_date?->format('Y-m-d'));
    }

    /** `--workspace` bounds the repair to one tenant. */
    public function test_the_workspace_option_bounds_the_repair(): void
    {
        $mine = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $other = Workspace::query()->create(['name' => 'Third', 'slug' => 'third-dues']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Third Client', 'slug' => 'third-dues-client',
        ]);
        $theirs = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-16', workspace: $other, company: $otherCompany);

        $this->artisan('svc:billing:backfill-invoice-due-dates', [
            '--workspace' => 'dues', '--apply' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('2026-01-15', $mine->refresh()->due_date?->format('Y-m-d'));
        $this->assertNull($theirs->refresh()->due_date, 'A named workspace must not reach another.');
    }

    public function test_an_unknown_workspace_is_refused(): void
    {
        $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $this->artisan('svc:billing:backfill-invoice-due-dates', ['--workspace' => 'no-such-workspace'])
            ->expectsOutputToContain('No workspace matches')
            ->assertFailed();
    }

    /**
     * A run with nothing repairable still reports the undatable remainder.
     *
     * The case an operator most needs told: every affected invoice lacks an
     * issue date, so the repair can do nothing and the run looks clean while
     * the problem it was called for is entirely unaddressed.
     */
    public function test_an_all_undatable_population_is_warned_about_not_reported_as_clean(): void
    {
        $this->invoice(status: 'issued', balance: 50000, issueDate: null);

        $this->artisan('svc:billing:backfill-invoice-due-dates')
            ->doesntExpectOutputToContain('No collectible invoice is missing a due date')
            ->expectsOutputToContain('carry no issue date either')
            ->assertSuccessful();
    }

    /**
     * The count the operator approved is the count that gets written.
     *
     * The preview's transaction closes before the prompt is answered, so the
     * eligible set can move underneath it - a paid invoice refunded to
     * partially paid is enough. Writing the larger set would act on approval
     * nobody gave for it.
     */
    public function test_a_changed_eligible_set_aborts_rather_than_writing(): void
    {
        $first = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');
        $second = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-16');

        try {
            app(UndatedCollectibleInvoiceRepairer::class)->repair($this->workspace, apply: true, expected: 1);
            $this->fail('A repair approved for one invoice must not write two.');
        } catch (EligibleSetChanged $changed) {
            $this->assertSame(1, $changed->approved);
            $this->assertSame(2, $changed->found);
        }

        $this->assertNull($first->refresh()->due_date);
        $this->assertNull($second->refresh()->due_date);
    }

    private function invoice(
        string $status,
        int $balance,
        ?string $issueDate,
        ?Workspace $workspace = null,
        ?ClientCompany $company = null,
    ): ClientInvoice {
        return ClientInvoice::query()->create([
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'invoice_number' => 'DUE-'.str()->random(8),
            'status' => $status,
            'currency' => 'USD',
            'issue_date' => $issueDate,
            'due_date' => null,
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000 - $balance,
            'balance_amount' => $balance,
        ]);
    }
}
