<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\UndatedCollectibleInvoiceAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the audit counts, and whose.
 *
 * The population is the summary's own collectible set, restated in a second
 * place - so the tests that matter most are the ones pinning the boundaries of
 * that restatement. A definition that drifted from the reader it audits would
 * report a confident number about rows the disagreement does not touch.
 */
final class UndatedCollectibleInvoiceAuditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every stage narrows, and each narrowing removes a different row.
     *
     * Built so consecutive stages differ - 6 invoices, 4 collectible, 3 undated,
     * 2 datable - because a funnel that skipped a stage would report identical
     * totals for both and nothing would notice.
     */
    public function test_each_stage_of_the_funnel_narrows(): void
    {
        $workspace = $this->workspace('funnel');
        $company = $this->company($workspace, 'funnel');

        // Counted to the end: collectible, no due date, has an issue date.
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 5000, 'issue_date' => '2026-01-01']);
        $this->invoice($workspace, $company, ['status' => 'partially_paid', 'balance_amount' => 2500, 'issue_date' => '2026-02-01']);

        // Undated, but no issue date either - not repairable.
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 1000]);

        // Collectible, but states a due date.
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 9000, 'due_date' => '2026-03-01']);

        // Not collectible: a draft has been charged to nobody.
        $this->invoice($workspace, $company, ['status' => 'draft', 'balance_amount' => 7000]);

        // Not collectible: settled, so nobody owes it whatever its status says.
        $this->invoice($workspace, $company, ['status' => 'partially_paid', 'balance_amount' => 0]);

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($workspace);

        $this->assertSame(6, $counts->invoices);
        $this->assertSame(4, $counts->collectible);
        $this->assertSame(3, $counts->undated);
        $this->assertSame(2, $counts->withAnIssueDate);
        $this->assertSame(1, $counts->withoutAnIssueDate);
        $this->assertTrue($counts->isLive());
    }

    /**
     * A zero balance is not collectible, whatever the status says.
     *
     * The summary requires `balance_amount > 0` alongside the status, and an
     * audit keyed on status alone would count invoices nobody owes anything on -
     * inflating a figure whose whole purpose is to be believed when it says the
     * two reported numbers disagree.
     */
    public function test_a_settled_invoice_is_not_collectible(): void
    {
        $workspace = $this->workspace('settled');
        $company = $this->company($workspace, 'settled');

        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 0]);
        $this->invoice($workspace, $company, ['status' => 'partially_paid', 'balance_amount' => 0]);

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($workspace);

        $this->assertSame(2, $counts->invoices);
        $this->assertSame(0, $counts->collectible);
        $this->assertSame(0, $counts->undated);
        $this->assertFalse($counts->isLive());
    }

    /**
     * A paid or voided invoice is outside the set even with a balance.
     *
     * The status list is exactly the summary's two. Widening it - to everything
     * unpaid, say - would report a disagreement between two figures that never
     * looked at those rows.
     */
    public function test_only_the_two_collectible_statuses_are_counted(): void
    {
        $workspace = $this->workspace('statuses');
        $company = $this->company($workspace, 'statuses');

        foreach (['draft', 'paid', 'void', 'issued'] as $status) {
            $this->invoice($workspace, $company, ['status' => $status, 'balance_amount' => 5000]);
        }

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($workspace);

        $this->assertSame(4, $counts->invoices);
        $this->assertSame(1, $counts->collectible);
    }

    /**
     * Balances are reported per currency, not summed across them.
     *
     * Money in two denominations has no total. Asserted because a sum would
     * produce a plausible-looking number that means nothing, and the summary
     * this audits reports its own balances by currency for the same reason.
     */
    public function test_balances_are_kept_apart_by_currency(): void
    {
        $workspace = $this->workspace('currency');
        $company = $this->company($workspace, 'currency');

        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 5000, 'currency' => 'USD']);
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 2000, 'currency' => 'USD']);
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 3000, 'currency' => 'EUR']);

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($workspace);

        $this->assertSame(['EUR' => 3000, 'USD' => 7000], $counts->undatedBalances);
    }

    /**
     * The backfill preview counts only invoices a backfill would make late.
     *
     * An invoice issued today would be given today as its due date and would not
     * be overdue, so counting it would overstate what the repair changes - and
     * that number is the one an operator weighs before approving it.
     */
    public function test_only_invoices_issued_before_today_would_become_overdue(): void
    {
        $workspace = $this->workspace('backfill');
        $company = $this->company($workspace, 'backfill');

        $this->travelTo('2026-08-31');

        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 5000, 'issue_date' => '2026-01-01']);
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 1500, 'issue_date' => '2026-08-31']);
        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 2500, 'issue_date' => '2026-12-01']);

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($workspace);

        $this->assertSame(3, $counts->withAnIssueDate);
        $this->assertSame(1, $counts->wouldBecomeOverdueIfBackfilled);
        $this->assertSame(['USD' => 5000], $counts->wouldBecomeOverdueBalances);

        // The whole undated balance is larger than the part the repair moves,
        // which is the comparison the operator is being asked to make.
        $this->assertSame(['USD' => 9000], $counts->undatedBalances);
    }

    /**
     * "Today" is resolved per workspace, not once for the whole audit.
     *
     * An unscoped run spans tenants in different timezones. With the clock
     * frozen just after midnight UTC, an invoice issued "today" in Auckland was
     * issued yesterday in UTC terms, and one issued "today" in Los Angeles is
     * still tomorrow there - so a single boundary answers wrongly for one of
     * them whichever date it picks. This is the count someone reads before
     * approving a migration, so it resolves the boundary once per timezone
     * present and applies it to that timezone's rows.
     */
    public function test_today_is_resolved_in_each_workspaces_own_timezone(): void
    {
        $this->travelTo('2026-08-31 00:30:00');

        // 2026-08-31 12:30 locally: the invoice dated 2026-08-31 was issued
        // today there, so a backfill would not make it overdue.
        $auckland = $this->workspace('auckland');
        $auckland->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $aucklandCompany = $this->company($auckland, 'auckland');
        $this->invoice($auckland, $aucklandCompany, [
            'status' => 'issued', 'balance_amount' => 5000, 'issue_date' => '2026-08-31',
        ]);

        // 2026-08-30 17:30 locally: the same date is still in the future there,
        // so it certainly is not overdue either.
        $losAngeles = $this->workspace('la');
        $losAngeles->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $laCompany = $this->company($losAngeles, 'la');
        $this->invoice($losAngeles, $laCompany, [
            'status' => 'issued', 'balance_amount' => 3000, 'issue_date' => '2026-08-31',
        ]);

        // Genuinely old in either calendar, so it anchors the count above zero -
        // without it, a query that matched nothing at all would pass.
        $this->invoice($losAngeles, $laCompany, [
            'status' => 'issued', 'balance_amount' => 1000, 'issue_date' => '2026-01-01',
        ]);

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count();

        $this->assertSame(3, $counts->withAnIssueDate);
        $this->assertSame(1, $counts->wouldBecomeOverdueIfBackfilled);
        $this->assertSame(['USD' => 1000], $counts->wouldBecomeOverdueBalances);
    }

    public function test_a_scoped_audit_sees_only_its_own_workspace(): void
    {
        $mine = $this->exposedWorkspace('first');
        $this->exposedWorkspace('second');

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($mine);

        $this->assertSame(1, $counts->undated);
        $this->assertSame(['USD' => 5000], $counts->undatedBalances);

        $unscoped = app(UndatedCollectibleInvoiceAuditor::class)->count();
        $this->assertSame(2, $unscoped->undated);
        $this->assertSame(['USD' => 10000], $unscoped->undatedBalances);
    }

    /**
     * A clean workspace reports nothing while its neighbour is broken.
     *
     * Asserted separately because a scope that leaked would still pass the test
     * above by coincidence: both workspaces are affected there, so a wrong
     * number is a plausible number.
     */
    public function test_a_clean_workspace_reports_nothing_when_its_neighbour_is_broken(): void
    {
        $clean = $this->workspace('clean');
        $this->exposedWorkspace('broken');

        $counts = app(UndatedCollectibleInvoiceAuditor::class)->count($clean);

        $this->assertSame(0, $counts->invoices);
        $this->assertSame(0, $counts->undated);
        $this->assertSame([], $counts->undatedBalances);
        $this->assertFalse($counts->isLive());
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => $slug.'-undated-invoice-workspace',
        ]);
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-undated-invoice-client',
        ]);
    }

    /**
     * An invoice with no due date unless the overrides give it one.
     *
     * `forceFill` for the overrides because the audit's subject is columns a
     * normal create path would not leave in this state - a charged status, a
     * null due date, an imported issue date.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(Workspace $workspace, ClientCompany $company, array $overrides = []): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => strtoupper($workspace->slug).'-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill(['due_date' => null, 'issue_date' => null, ...$overrides])->save();

        return $invoice;
    }

    /**
     * One collectible invoice with no due date - the shape the audit exists to
     * count - in a workspace of its own.
     */
    private function exposedWorkspace(string $slug): Workspace
    {
        $workspace = $this->workspace($slug);
        $company = $this->company($workspace, $slug);

        $this->invoice($workspace, $company, ['status' => 'issued', 'balance_amount' => 5000]);

        return $workspace;
    }
}
