<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\UndatedCollectibleInvoiceRepairer;
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

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair(apply: true);

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

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair();

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

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair(apply: true);

        $this->assertSame(0, $result->repaired);
        $this->assertSame(1, $result->skippedWithoutAnIssueDate);
        $this->assertTrue($result->leavesAnUndatedRemainder());
        $this->assertNull($undatable->refresh()->due_date);
    }

    /**
     * Only the collectible set is repaired, and each exclusion has a reason.
     *
     * A draft is owed by nobody, a paid invoice has no balance, and a
     * partially-paid invoice settled to zero is collectible by status while
     * owing nothing - so none of them is in the set whose two figures disagree,
     * and dating them would state a term for an invoice nobody is chasing.
     *
     * An invoice that already has a due date is untouched for the obvious
     * reason, asserted because overwriting one would replace a stated term with
     * a derived one and no other test here would notice.
     */
    public function test_it_leaves_every_invoice_outside_the_collectible_set_alone(): void
    {
        $draft = $this->invoice(status: 'draft', balance: 50000, issueDate: '2026-01-15');
        $paid = $this->invoice(status: 'paid', balance: 0, issueDate: '2026-01-16');
        $settled = $this->invoice(status: 'partially_paid', balance: 0, issueDate: '2026-01-17');
        $alreadyDated = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-18');
        $alreadyDated->forceFill(['due_date' => '2026-02-28'])->save();

        // One minor unit is a balance. The boundary is `> 0`, not "a
        // meaningful amount", and an invoice owing a cent is as collectible as
        // one owing a thousand - it appears in the same figure and is missing
        // from the same one.
        $owesOneUnit = $this->invoice(status: 'issued', balance: 1, issueDate: '2026-01-19');

        $result = app(UndatedCollectibleInvoiceRepairer::class)->repair(apply: true);

        $this->assertSame(1, $result->eligible);
        $this->assertSame(1, $result->repaired);
        $this->assertSame('2026-01-19', $owesOneUnit->refresh()->due_date?->format('Y-m-d'));
        $this->assertNull($draft->refresh()->due_date);
        $this->assertNull($paid->refresh()->due_date);
        $this->assertNull($settled->refresh()->due_date);
        $this->assertSame('2026-02-28', $alreadyDated->refresh()->due_date?->format('Y-m-d'));
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

    /** Declining the confirmation writes nothing. */
    public function test_the_command_writes_nothing_when_the_confirmation_is_declined(): void
    {
        $invoice = $this->invoice(status: 'issued', balance: 50000, issueDate: '2026-01-15');

        $this->artisan('svc:billing:backfill-invoice-due-dates', ['--apply' => true])
            ->expectsConfirmation('Set the due date to the issue date on 1 collectible invoice(s)?', 'no')
            ->assertSuccessful();

        $this->assertNull($invoice->refresh()->due_date);
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
