<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InterimClaimRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An interim draft's overage figure is only true against the claims that were
 * charged when it was generated.
 *
 * The generator bills `cumulative excess - interim hours already charged`, and
 * a draft is not charged, so two drafts generated before either is issued each
 * claim the overage the other covers. The fixture is the legacy monthly-terms
 * branch - quarterly cadence, interim billing on, ten retainer hours a month,
 * no native period-retainer override - with 15 hours worked in each of January
 * and February: five hours over each month, ten cumulatively by the end of
 * February.
 *
 * Before `issue()` checked the cycle's claims, both drafts issued in either
 * order and 15 hours were charged against 10 of excess; the cycle's closing
 * invoice then recorded the 15 as "already billed" in a zero-value line and
 * corrected nothing.
 */
class InterimClaimValidityTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic interim', 'slug' => 'synthetic-interim']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Synthetic interim client', 'slug' => 'synthetic-interim-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic interim project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_the_fixture_generates_the_two_drafts_the_finding_describes(): void
    {
        $agreement = $this->legacyAgreement();
        $this->worked('2024-01-10', 900);
        $this->worked('2024-02-10', 900);

        // The ledger first: five hours over in each month, measured monthly.
        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough($this->company, $agreement, Carbon::parse('2024-02-29'), true);
        $this->assertSame([5.0, 5.0], array_map(fn ($month): float => (float) $month->closing->excessHours, array_values($ledger)));

        [$january, $february] = $this->drafts($agreement);

        $this->assertSame(['5.0000', '100000'], [(string) $january->hours_billed_at_rate, (string) $january->total_amount]);
        // Cumulative ten, less nothing charged: the January draft is not a charge.
        $this->assertSame(['10.0000', '200000'], [(string) $february->hours_billed_at_rate, (string) $february->total_amount]);
        $this->assertSame(['additional_hours'], $january->lines->pluck('type')->all());
        $this->assertSame(['additional_hours'], $february->lines->pluck('type')->all());
    }

    public function test_january_then_february_refuses_the_stale_february_draft_until_it_is_regenerated(): void
    {
        $agreement = $this->legacyAgreement();
        $this->worked('2024-01-10', 900);
        $this->worked('2024-02-10', 900);
        [$january, $february] = $this->drafts($agreement);

        $this->issue($january);
        $before = $this->fingerprint($february);

        $refusal = $this->refusal($february);
        $this->assertTrue($refusal->regenerate, 'Regenerating resolves this one: it recomputes against January, now charged');
        $this->assertStringContainsString('Regenerate', $refusal->getMessage());
        $this->assertSame($before, $this->fingerprint($february), 'A refusal changes nothing money-facing');

        // D: regenerate, then issue.
        $regenerated = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company, Carbon::parse('2024-02-01'), $agreement->fresh(), null, $february->fresh(),
        );
        $this->assertSame($february->id, $regenerated?->id, 'Refreshed in place, keeping its number');
        $this->assertSame('5.0000', (string) $regenerated->hours_billed_at_rate);
        $this->issue($regenerated);

        $this->assertSame(10.0, $this->chargedInterimHours($agreement), 'Charged exactly the cumulative excess');
        $this->assertCycleCloses($agreement, 10.0);
    }

    public function test_february_then_january_refuses_january_as_out_of_order_and_regeneration_does_not_help(): void
    {
        $agreement = $this->legacyAgreement();
        $this->worked('2024-01-10', 900);
        $this->worked('2024-02-10', 900);
        [$january, $february] = $this->drafts($agreement);

        $this->issue($february);
        $before = $this->fingerprint($january);

        $refusal = $this->refusal($january);
        $this->assertFalse($refusal->regenerate, 'February already charged the overage through February, including January\'s');
        $this->assertStringContainsString($february->invoice_number, $refusal->getMessage());
        $this->assertStringContainsString('Discard', $refusal->getMessage());
        $this->assertSame($before, $this->fingerprint($january));

        // D: regeneration recomputes the same five hours, and is refused again.
        $regenerated = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company, Carbon::parse('2024-01-01'), $agreement->fresh(), null, $january->fresh(),
        );
        $this->assertSame('5.0000', (string) $regenerated?->hours_billed_at_rate);
        $this->assertFalse($this->refusal($regenerated)->regenerate);

        // The supported next action.
        app(InvoiceLifecycleService::class)->discardDraft($january->fresh(), $this->workspace, 'Covered by the February interim.');
        $this->assertSame(10.0, $this->chargedInterimHours($agreement));
        $this->assertCycleCloses($agreement, 10.0);
    }

    public function test_a_charged_invoice_still_issues_idempotently(): void
    {
        $agreement = $this->legacyAgreement();
        $this->worked('2024-01-10', 900);
        $this->worked('2024-02-10', 900);
        [$january] = $this->drafts($agreement);
        $issued = $this->issue($january);

        $again = app(InvoiceLifecycleService::class)->issue($issued->fresh(), $this->workspace);

        $this->assertSame('issued', $again->status);
        $this->assertSame((string) $issued->issued_at, (string) $again->issued_at);
    }

    /**
     * The native pooled branch, separately. It does not produce the same
     * overcharge - a month's claim is capped by that month's own hours, and
     * the cycle pool's excess already includes the earlier month's - so the
     * guard must let both drafts issue, in either order.
     */
    /** @return iterable<string, array{list<string>}> */
    public static function orders(): iterable
    {
        yield 'january first' => [['january', 'february']];
        yield 'february first' => [['february', 'january']];
    }

    #[DataProvider('orders')]
    public function test_the_native_period_retainer_branch_issues_both_drafts_in_either_order(array $order): void
    {
        $agreement = $this->legacyAgreement();
        $agreement->forceFill(['period_retainer_minutes' => 1800, 'period_retainer_amount' => 450000])->save();
        $agreement = $agreement->fresh();
        $this->worked('2024-01-10', 2100); // 35h against a 30h quarter pool
        $this->worked('2024-02-10', 900);  // cumulative 50h, 20h over
        [$january, $february] = $this->drafts($agreement);
        $this->assertSame('5.0000', (string) $january->hours_billed_at_rate);
        $this->assertSame('15.0000', (string) $february->hours_billed_at_rate, 'Capped by February\'s own hours');

        foreach ($order as $month) {
            $this->issue($month === 'january' ? $january : $february);
        }

        $this->assertSame(20.0, $this->chargedInterimHours($agreement));
    }

    private function legacyAgreement(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic quarterly retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
        ])->fresh();
    }

    private function worked(string $on, int $minutes): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $on,
            'minutes' => $minutes,
            'description' => 'Synthetic work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    /** @return array{ClientInvoice, ClientInvoice} */
    private function drafts(ClientAgreement $agreement): array
    {
        $generator = app(InterimOverageGenerator::class);
        $january = $generator->generateInterimOverageInvoice($this->company, Carbon::parse('2024-01-01'), $agreement->fresh());
        $february = $generator->generateInterimOverageInvoice($this->company, Carbon::parse('2024-02-01'), $agreement->fresh());
        $this->assertInstanceOf(ClientInvoice::class, $january);
        $this->assertInstanceOf(ClientInvoice::class, $february);

        return [$january->fresh('lines'), $february->fresh('lines')];
    }

    private function issue(ClientInvoice $invoice): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->issue($invoice->fresh(), $this->workspace);
    }

    private function refusal(ClientInvoice $invoice): InterimClaimRefused
    {
        try {
            $issued = $this->issue($invoice);
        } catch (InterimClaimRefused $refusal) {
            return $refusal;
        }

        $this->fail('Issued '.$issued->invoice_number.' at '.$issued->hours_billed_at_rate.' hours; charged interim hours are now '.$this->chargedInterimHours($issued->agreement).'.');
    }

    private function chargedInterimHours(?ClientAgreement $agreement): float
    {
        return (float) ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_id', $agreement?->id)
            ->where('invoice_kind', 'interim_overage')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->sum('hours_billed_at_rate');
    }

    /**
     * Close the cycle and read what it says it reconciled. The line is an
     * informational zero-value reconciliation, not a correction: it has to
     * agree with the charged hours for no money to be owed either way.
     */
    private function assertCycleCloses(ClientAgreement $agreement, float $chargedHours): void
    {
        $closing = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'), $agreement->fresh(),
        );
        $reconciliation = $closing->fresh('lines')->lines->firstWhere('type', 'reconciliation');
        $this->assertNotNull($reconciliation);
        $this->assertSame($chargedHours, (float) $reconciliation->hours);
        $this->assertSame(0, (int) $reconciliation->total_amount);
        $this->assertSame(0, $closing->lines->where('type', 'credit')->count());
        $this->assertSame(0.0, (float) $closing->hours_billed_at_rate, 'No further overage: the interims billed it all');
        // Closing releases uncharged interim drafts - it may regenerate one for
        // a month that has none and release it at once - so any interim draft
        // left behind is empty: no line, no time, no money.
        $interimDrafts = ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('invoice_kind', 'interim_overage')
            ->where('status', 'draft')
            ->get();
        foreach ($interimDrafts as $draft) {
            $this->assertSame(0, $draft->lines()->count(), 'Closing the cycle leaves no interim draft holding work');
            $this->assertSame(0, (int) $draft->total_amount);
        }
    }

    /** @return array<string, mixed> */
    private function fingerprint(ClientInvoice $invoice): array
    {
        $row = ClientInvoice::query()->where('workspace_id', $invoice->workspace_id)->findOrFail($invoice->id);

        return [
            'status' => $row->status,
            'total' => (int) $row->total_amount,
            'hours' => (string) $row->hours_billed_at_rate,
            'lines' => $row->lines()->orderBy('id')->get(['id', 'type', 'total_amount'])->toArray(),
            'pivots' => DB::table('client_invoice_line_time_entries')->where('workspace_id', $invoice->workspace_id)
                ->whereIn('client_invoice_line_id', $row->lines()->pluck('id'))->orderBy('client_time_entry_id')->pluck('client_time_entry_id')->all(),
            'notifications' => DB::table('client_invoice_administrator_notifications')->where('client_invoice_id', $invoice->id)->count(),
            'deliveries' => DB::table('client_invoice_email_deliveries')->where('client_invoice_id', $invoice->id)->count(),
            'time_statuses' => DB::table('client_time_entries')->where('workspace_id', $invoice->workspace_id)->orderBy('id')->pluck('status')->all(),
        ];
    }
}
