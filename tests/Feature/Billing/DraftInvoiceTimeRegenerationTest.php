<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class DraftInvoiceTimeRegenerationTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Draft regeneration', 'slug' => 'draft-regeneration']);
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'admin']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Draft Client',
            'slug' => 'draft-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Draft Project',
            'status' => 'active',
        ]);
    }

    public function test_editing_approved_time_rebuilds_its_cadence_draft_in_the_same_invoice(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = $this->generateJuly($agreement);
        $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'adjustment',
            'description' => 'Operator adjustment',
            'quantity' => '1.0000',
            'unit_amount' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'sort_order' => 99,
        ]);
        $invoice->recalculateTotals();

        $updated = app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
        );

        $invoice->refresh();
        $linkedLine = $invoice->lines()->whereHas('timeEntries', fn ($query) => $query->whereKey($updated->id))->sole();
        $this->assertSame($invoice->id, $linkedLine->client_invoice_id);
        $this->assertSame('approved', $updated->status);
        $this->assertSame(90, $updated->minutes);
        $this->assertSame(18000, $linkedLine->total_amount);
        $this->assertSame(18500, $invoice->total_amount);
        $this->assertDatabaseHas('client_invoice_lines', [
            'client_invoice_id' => $invoice->id,
            'type' => 'adjustment',
            'description' => 'Operator adjustment',
            'total_amount' => 500,
        ]);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_deleting_approved_time_rebuilds_the_cadence_draft_without_it(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = $this->generateJuly($agreement);

        app(TimeEntryMutationService::class)->delete(
            $this->workspace,
            $entry,
            $this->manager,
            AgentApiVersion::for($entry),
        );

        $this->assertSoftDeleted($entry);
        $this->assertDatabaseMissing('client_invoice_line_time_entries', [
            'workspace_id' => $this->workspace->id,
            'client_time_entry_id' => $entry->id,
        ]);
        $this->assertSame(0, $invoice->refresh()->total_amount);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_moving_time_between_periods_rebuilds_both_existing_cadence_drafts(): void
    {
        $agreement = $this->agreement();
        $moved = $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 60]);
        $this->approvedEntry(['worked_on' => '2026-08-14', 'minutes' => 60, 'description' => 'August work']);
        $july = $this->generateJuly($agreement);
        $august = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $agreement,
        )->refresh();

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $moved,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($moved),
                'worked_on' => '2026-08-10',
            ],
        );

        $this->assertSame(0, $july->refresh()->total_amount);
        $this->assertSame(24000, $august->refresh()->total_amount);
        $this->assertTrue($moved->fresh()?->invoiceLines()
            ->where('client_invoice_id', $august->id)
            ->exists());
        $this->assertSame(2, ClientInvoice::query()->count());
    }

    /**
     * A companion draft with no period start is never rebuilt for the new date.
     *
     * `DraftInvoiceTimeRegenerator::regenerate()` finds the *other* drafts a
     * moved entry now belongs to with `whereDate('service_period_start', '<=',
     * ...)`. SQL compares a null to a date as UNKNOWN and `WHERE` drops the
     * row, so a draft missing only that boundary is invisible to the search - it
     * keeps whatever it was last built from while the invoice that used to own
     * the entry correctly gives it up. The entry ends up on no invoice at all,
     * and nothing says so.
     *
     * The control runs first and on the same rows: with the boundary present
     * the destination draft absorbs the move, so the difference below is the
     * null and not the fixture. Its sibling boundary `service_period_end` stays
     * set and matching throughout, which is what makes this the start branch.
     */
    public function test_a_companion_draft_with_no_period_start_is_not_rebuilt_for_a_moved_entry(): void
    {
        $agreement = $this->agreement();
        $moved = $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 60]);
        $this->approvedEntry(['worked_on' => '2026-08-14', 'minutes' => 60, 'description' => 'August work']);
        $july = $this->generateJuly($agreement);
        $august = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $agreement,
        )->refresh();

        $move = function (ClientTimeEntry $entry, string $date): void {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry->refresh(),
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($entry->refresh()),
                    'worked_on' => $date,
                ],
            );
        };

        // Control: a stated start absorbs the move.
        $move($moved, '2026-08-10');
        $this->assertSame(24000, $august->refresh()->total_amount);
        $this->assertSame(0, $july->refresh()->total_amount);

        // Put it back, so the null case starts from the same arrangement.
        $move($moved, '2026-07-14');
        $this->assertSame(12000, $august->refresh()->total_amount);
        $this->assertSame(12000, $july->refresh()->total_amount);

        // Only the start goes. The end still covers the destination date.
        $august->forceFill(['service_period_start' => null])->save();
        $this->assertNull($august->refresh()->service_period_start);
        $this->assertSame('2026-08-31', $august->service_period_end?->format('Y-m-d'));

        $move($moved, '2026-08-10');

        $this->assertSame(0, $july->refresh()->total_amount, 'The owning draft still gives the entry up.');
        $this->assertSame(12000, $august->refresh()->total_amount, 'The undated draft never sees the arrival.');
        $this->assertFalse($moved->fresh()?->invoiceLines()->exists(), 'The moved work is now billed by nothing.');
    }

    public function test_moving_time_across_an_agreement_renewal_rebuilds_the_destination_draft(): void
    {
        $firstAgreement = $this->agreement();
        $firstAgreement->forceFill(['ends_on' => '2026-07-31'])->save();
        $secondAgreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Renewed hourly monthly agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-08-01',
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 12000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
        $moved = $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 60]);
        $this->approvedEntry(['worked_on' => '2026-08-14', 'minutes' => 60, 'description' => 'Renewal work']);
        $july = $this->generateJuly($firstAgreement);
        $august = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $secondAgreement,
        )->refresh();

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $moved,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($moved),
                'worked_on' => '2026-08-10',
            ],
        );

        $this->assertSame(0, $july->refresh()->total_amount);
        $this->assertSame(24000, $august->refresh()->total_amount);
        $this->assertTrue($moved->fresh()?->invoiceLines()
            ->where('client_invoice_id', $august->id)
            ->exists());
    }

    public function test_editing_selected_time_reprices_an_ad_hoc_draft_and_preserves_manual_lines(): void
    {
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = app(InvoiceFromTimeService::class)->create(
            $this->workspace,
            $this->company,
            ['invoice_number' => 'SVC-AD-HOC', 'currency' => 'USD'],
            [$entry->public_id],
            [[
                'type' => 'adjustment',
                'description' => 'Manual line',
                'quantity' => '1.0000',
                'unit_amount' => 700,
                'tax_amount' => 0,
                'sort_order' => 0,
            ]],
        );

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 30,
                'description' => 'Corrected selected work',
            ],
        );

        $invoice->refresh();
        $timeLine = $invoice->lines()->whereHas('timeEntries', fn ($query) => $query->whereKey($entry->id))->sole();
        $this->assertSame('Corrected selected work', $timeLine->description);
        $this->assertSame(6000, $timeLine->total_amount);
        $this->assertSame(6700, $invoice->total_amount);
        $this->assertSame(1, $invoice->lines()->where('description', 'Manual line')->count());
    }

    public function test_editing_time_claimed_by_an_interim_draft_regenerates_that_draft(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $originalTotal = (int) $invoice->total_amount;
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $billedEntry,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($billedEntry),
                'minutes' => max(1, $billedEntry->minutes - 60),
            ],
        );

        $this->assertSame($invoice->id, $invoice->refresh()->id);
        $this->assertLessThan($originalTotal, (int) $invoice->total_amount);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_removing_the_last_interim_overage_clears_its_cached_charge(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $this->assertGreaterThan(0, (int) $invoice->total_amount);
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();

        app(TimeEntryMutationService::class)->delete(
            $this->workspace,
            $billedEntry,
            $this->manager,
            AgentApiVersion::for($billedEntry),
        );

        $invoice->refresh();
        $this->assertSame(0, $invoice->lines()->count());
        $this->assertSame(0, (int) $invoice->subtotal_amount);
        $this->assertSame(0, (int) $invoice->total_amount);
        $this->assertSame(0, (int) $invoice->balance_amount);
        $this->assertSame(0.0, (float) $invoice->hours_billed_at_rate);
    }

    public function test_removing_the_last_interim_overage_reapplies_credit_to_manual_lines(): void
    {
        $settled = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'OVERPAID-HISTORY',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 15000,
            'balance_amount' => 0,
        ]);
        $settled->payments()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'succeeded',
            'amount' => 15000,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'method' => 'ach',
            'received_on' => '2026-06-30',
        ]);
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $this->assertTrue($invoice->lines()->where('type', 'credit')->exists());
        $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'adjustment',
            'description' => 'Manual fee that remains',
            'quantity' => '1.0000',
            'unit_amount' => 3000,
            'tax_amount' => 0,
            'total_amount' => 3000,
            'sort_order' => 99,
        ]);
        $invoice->recalculateTotals();
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();

        app(TimeEntryMutationService::class)->delete(
            $this->workspace,
            $billedEntry,
            $this->manager,
            AgentApiVersion::for($billedEntry),
        );

        $invoice->refresh();
        $credit = $invoice->lines()->where('type', 'credit')->sole();
        $this->assertSame(-3000, (int) $credit->total_amount);
        $this->assertSame(0, (int) $invoice->total_amount);
        $this->assertSame(1, $invoice->lines()->where('type', 'adjustment')->count());
    }

    public function test_disabling_interim_billing_makes_an_existing_interim_draft_fail_closed(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();
        $originalMinutes = $billedEntry->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $agreement->forceFill(['bill_overage_interim' => false])->save();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $billedEntry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($billedEntry),
                    'minutes' => max(1, $originalMinutes - 60),
                ],
            );
            $this->fail('A disabled interim path must not commit a time edit against its stale draft.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('Interim overage billing is disabled', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $billedEntry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
        $this->assertTrue($billedEntry->fresh()?->invoiceLines()
            ->where('client_invoice_id', $invoice->id)
            ->exists());
    }

    public function test_an_interim_draft_without_cycle_dates_fails_closed(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();
        $originalMinutes = $billedEntry->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill(['cycle_start' => null, 'cycle_end' => null])->save();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $billedEntry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($billedEntry),
                    'minutes' => max(1, $originalMinutes - 60),
                ],
            );
            $this->fail('An interim draft without a cycle must not commit a time edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('complete service period and cycle', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $billedEntry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
        $this->assertTrue($billedEntry->fresh()?->invoiceLines()
            ->where('client_invoice_id', $invoice->id)
            ->exists());
    }

    public function test_a_closing_cycle_interim_draft_fails_closed(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();
        $originalMinutes = $billedEntry->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill([
            'service_period_start' => $invoice->cycle_end->copy()->startOfMonth(),
            'service_period_end' => $invoice->cycle_end,
        ])->save();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $billedEntry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($billedEntry),
                    'minutes' => max(1, $originalMinutes - 60),
                ],
            );
            $this->fail('A closing-cycle interim draft must not commit a time edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('cannot cover the closing month', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $billedEntry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
    }

    public function test_an_interim_draft_that_no_longer_matches_its_derived_period_fails_closed(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();
        $originalMinutes = $billedEntry->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill(['service_period_start' => '2026-07-15'])->save();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $billedEntry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($billedEntry),
                    'minutes' => max(1, $originalMinutes - 60),
                ],
            );
            $this->fail('An interim draft with a mismatched period must not commit a time edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('no longer matches the agreement period and cycle', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $billedEntry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
    }

    public function test_a_migrated_null_kind_non_monthly_draft_regenerates_in_place(): void
    {
        $agreement = $this->quarterlyAgreement();
        $agreement->forceFill(['bill_overage_interim' => false])->save();
        $entry = $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-08-31'),
            $agreement,
        )->refresh();
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill(['invoice_kind' => null])->save();
        $entry->refresh();

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 660,
            ],
        );

        $invoice->refresh();
        $this->assertSame(1, ClientInvoice::query()->count());
        $this->assertSame('cadence_period', $invoice->invoice_kind);
        $this->assertGreaterThan($originalTotal, (int) $invoice->total_amount);
        $this->assertTrue($entry->fresh()?->invoiceLines()
            ->where('client_invoice_id', $invoice->id)
            ->exists());
    }

    /**
     * A cadence draft with neither a cycle nor a service period fails closed.
     *
     * The cycle columns are the preferred anchor and the service period is the
     * compatibility path for drafts migrated from before they existed. With
     * both null there is nothing left to say which period the draft covers, so
     * the edit is refused rather than regenerated against a guessed range -
     * which would silently move the client's charge to another month.
     */
    public function test_a_cadence_draft_without_a_service_period_fails_closed(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 120]);
        $invoice = $this->generateJuly($agreement);
        $originalMinutes = $entry->fresh()?->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill([
            'cycle_start' => null,
            'cycle_end' => null,
            'service_period_start' => null,
            'service_period_end' => null,
        ])->save();
        $entry->refresh();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($entry),
                    'minutes' => 180,
                ],
            );
            $this->fail('A cadence draft with no period must not commit a time edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('no billing period to regenerate', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $entry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
    }

    /**
     * A cadence draft missing only its period *end* fails closed.
     *
     * The sibling above nulls both service-period columns, which cannot prove
     * anything about either one: the refusal reads
     * `service_period_start === null || service_period_end === null`, so with
     * both null, deleting either half leaves the other still throwing. This
     * gives the draft a real start and removes only the end, so the assertion
     * depends on the end check and nothing else.
     *
     * The cycle columns stay null because a draft that names its cycle is
     * regenerated from that instead and never reaches the period branch at all.
     */
    public function test_a_cadence_draft_with_no_period_end_fails_closed(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 120]);
        $invoice = $this->generateJuly($agreement);
        $originalMinutes = $entry->fresh()?->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill([
            'cycle_start' => null,
            'cycle_end' => null,
            'service_period_start' => '2026-07-01',
            'service_period_end' => null,
        ])->save();
        $entry->refresh();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($entry),
                    'minutes' => 180,
                ],
            );
            $this->fail('A cadence draft with no period end must not commit a time edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('no billing period to regenerate', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $entry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
    }

    /**
     * A generated draft that names no agreement fails closed.
     *
     * Regeneration reprices the draft against its agreement's terms. A null
     * `client_agreement_id` resolves to no agreement at all, and the composer
     * that follows drops its project scoping when handed one - so continuing
     * would rebuild the invoice with every project's work on it. The lookup
     * refuses instead, and the client's existing charge is left alone.
     */
    public function test_a_generated_draft_without_an_agreement_fails_closed(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 120]);
        $invoice = $this->generateJuly($agreement);
        $originalMinutes = $entry->fresh()?->minutes;
        $originalTotal = (int) $invoice->total_amount;
        $invoice->forceFill(['client_agreement_id' => null])->save();
        $entry->refresh();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                [
                    'expected_version' => AgentApiVersion::for($entry),
                    'minutes' => 180,
                ],
            );
            $this->fail('A generated draft with no agreement must not commit a time edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('does not belong to an available agreement', $exception->getMessage());
        }

        $this->assertSame($originalMinutes, $entry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
    }

    public function test_duplicate_generated_drafts_fail_before_the_owning_invoice_is_rebuilt(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = $this->generateJuly($agreement);
        $duplicate = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'DUPLICATE-CADENCE',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'service_period_start' => $invoice->service_period_start,
            'service_period_end' => $invoice->service_period_end,
            'cycle_start' => $invoice->cycle_start,
            'cycle_end' => $invoice->cycle_end,
            'currency' => 'USD',
            'subtotal_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'balance_amount' => 12000,
        ]);
        $invoice->lines()->update(['client_invoice_id' => $duplicate->id]);
        $invoice->forceFill([
            'subtotal_amount' => 0,
            'total_amount' => 0,
            'balance_amount' => 0,
        ])->save();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('Ambiguous generated drafts must stop before either is rebuilt.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('resolve the duplicate invoices first', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame(0, $invoice->refresh()->total_amount);
        $this->assertSame(12000, $duplicate->refresh()->total_amount);
        $this->assertTrue($entry->fresh()?->invoiceLines()
            ->where('client_invoice_id', $duplicate->id)
            ->exists());
    }

    public function test_approved_time_without_a_draft_invoice_remains_immutable(): void
    {
        $entry = $this->approvedEntry();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('Unallocated approved time must remain immutable.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('Only draft time entries can be changed.', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
    }

    public function test_another_workspaces_invoice_link_neither_freezes_nor_regenerates_this_entry(): void
    {
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-14',
            'minutes' => 60,
            'description' => 'Local draft work',
            'status' => 'draft',
        ]);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other draft regeneration', 'slug' => 'other-draft-regeneration']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Draft Client',
            'slug' => 'other-draft-client',
        ]);
        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'FOREIGN-DRAFT',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 900,
            'tax_amount' => 0,
            'total_amount' => 900,
        ]);
        $foreignLine = $foreignInvoice->lines()->create([
            'workspace_id' => $otherWorkspace->id,
            'type' => 'time',
            'description' => 'Foreign synthetic line',
            'quantity' => '1.0000',
            'unit_amount' => 900,
            'tax_amount' => 0,
            'total_amount' => 900,
            'sort_order' => 0,
        ]);
        // A pivot naming this workspace's entry under another tenant. Unstorable
        // since #113 - the pivot had no key on client_time_entry_id at all before
        // then - so enforcement is suspended to reproduce a pre-migration row.
        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $foreignLine->id,
            'client_time_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 75],
        );

        $this->assertSame(75, $entry->fresh()?->minutes);
        $this->assertSame(900, $foreignInvoice->fresh()?->total_amount);
        $this->assertDatabaseHas('client_invoice_line_time_entries', [
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $foreignLine->id,
            'client_time_entry_id' => $entry->id,
        ]);
    }

    public function test_ad_hoc_regeneration_refuses_to_delete_a_foreign_workspaces_pivot(): void
    {
        $entry = $this->approvedEntry();
        $invoice = app(InvoiceFromTimeService::class)->create(
            $this->workspace,
            $this->company,
            ['invoice_number' => 'LOCAL-WITH-FOREIGN-PIVOT', 'currency' => 'USD'],
            [$entry->public_id],
        );
        $line = $invoice->lines->sole();
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign pivot', 'slug' => 'foreign-pivot']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Pivot Client',
            'slug' => 'foreign-pivot-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Foreign Pivot Project',
        ]);
        $foreignEntry = ClientTimeEntry::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'client_project_id' => $otherProject->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-07-14',
            'minutes' => 30,
            'description' => 'Foreign pivot time',
            'status' => 'approved',
            'is_billable' => true,
            'currency' => 'USD',
            'billing_rate_amount' => 12000,
        ]);
        // Unstorable since #113; see the note on the pivot above.
        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $line->id,
            'client_time_entry_id' => $foreignEntry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('A foreign pivot on the selected-time line must stop regeneration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('allocation owned by another workspace', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame(12000, $invoice->refresh()->total_amount);
        $this->assertDatabaseHas('client_invoice_line_time_entries', [
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $line->id,
            'client_time_entry_id' => $foreignEntry->id,
        ]);
    }

    public function test_ad_hoc_regeneration_refuses_to_include_a_foreign_workspaces_line(): void
    {
        $entry = $this->approvedEntry();
        $invoice = app(InvoiceFromTimeService::class)->create(
            $this->workspace,
            $this->company,
            ['invoice_number' => 'LOCAL-WITH-FOREIGN-LINE', 'currency' => 'USD'],
            [$entry->public_id],
        );
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign ad hoc line', 'slug' => 'foreign-ad-hoc-line']);
        // A line on this workspace's invoice claiming another tenant. Unstorable
        // since #113; the regeneration guard is what is under test.
        $foreignLine = $this->writingLegacyCrossTenantRows(fn () => $invoice->lines()->create([
            'workspace_id' => $otherWorkspace->id,
            'type' => 'adjustment',
            'description' => 'Foreign adjustment',
            'quantity' => '1.0000',
            'unit_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
            'sort_order' => 99,
        ]));
        $originalTotal = (int) $invoice->total_amount;

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('A foreign line on an ad-hoc invoice must stop regeneration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('line owned by another workspace', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
        $this->assertNotNull($foreignLine->fresh());
    }

    public function test_generated_regeneration_refuses_to_delete_a_foreign_workspaces_pivot(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry();
        $invoice = $this->generateJuly($agreement);
        $line = $invoice->lines()
            ->whereHas('timeEntries', fn ($entries) => $entries->whereKey($entry->id))
            ->sole();
        $originalTotal = (int) $invoice->total_amount;
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign generated pivot', 'slug' => 'foreign-generated-pivot']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Generated Pivot Client',
            'slug' => 'foreign-generated-pivot-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Foreign Generated Pivot Project',
        ]);
        $foreignEntry = ClientTimeEntry::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'client_project_id' => $otherProject->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-07-14',
            'minutes' => 30,
            'description' => 'Foreign generated pivot time',
            'status' => 'approved',
            'is_billable' => true,
            'currency' => 'USD',
            'billing_rate_amount' => 12000,
        ]);
        // Unstorable since #113; see the note on the pivot above.
        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $line->id,
            'client_time_entry_id' => $foreignEntry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('A foreign pivot on a generated line must stop regeneration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('allocation owned by another workspace', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
        $this->assertDatabaseHas('client_invoice_line_time_entries', [
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $line->id,
            'client_time_entry_id' => $foreignEntry->id,
        ]);
    }

    public function test_generated_regeneration_rejects_a_separate_foreign_invoice_allocation(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry();
        $invoice = $this->generateJuly($agreement);
        $originalTotal = (int) $invoice->total_amount;
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign allocation', 'slug' => 'foreign-allocation']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Allocation Client',
            'slug' => 'foreign-allocation-client',
        ]);
        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'FOREIGN-ALLOCATION',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'balance_amount' => 100,
        ]);
        $foreignLine = $foreignInvoice->lines()->create([
            'workspace_id' => $otherWorkspace->id,
            'type' => 'time',
            'description' => 'Foreign allocation line',
            'quantity' => '1.0000',
            'unit_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'sort_order' => 1,
        ]);
        // Unstorable since #113; see the note on the pivot above.
        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $foreignLine->id,
            'client_time_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('A separate foreign invoice allocation must stop regeneration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertStringContainsString('allocation owned by another workspace', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame($originalTotal, (int) $invoice->refresh()->total_amount);
        $this->assertDatabaseHas('client_invoice_line_time_entries', [
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $foreignLine->id,
            'client_time_entry_id' => $entry->id,
        ]);
    }

    public function test_a_foreign_split_root_cannot_escape_the_workspace_in_the_update_response(): void
    {
        $agreement = $this->agreement();
        $first = $this->approvedEntry(['description' => 'Shared split work']);
        $second = $this->approvedEntry(['description' => 'Shared split work']);
        $this->generateJuly($agreement);
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign lineage', 'slug' => 'foreign-lineage']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Lineage Client',
            'slug' => 'foreign-lineage-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Foreign Lineage Project',
        ]);
        $foreignRoot = ClientTimeEntry::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'client_project_id' => $otherProject->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-07-14',
            'minutes' => 60,
            'description' => 'Foreign lineage root',
            'status' => 'approved',
        ]);
        $first->forceFill(['split_from_time_entry_id' => $foreignRoot->id])->save();
        $second->forceFill(['split_from_time_entry_id' => $foreignRoot->id])->save();
        $version = AgentApiVersion::for($second->refresh());

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $second,
                $this->manager,
                ['expected_version' => $version, 'minutes' => 90],
            );
            $this->fail('A foreign split root must not be returned as the local mutation result.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('The regenerated time entry has an inconsistent split lineage.', $exception->getMessage());
        }

        $this->assertSame(60, $second->fresh()?->minutes);
        $this->assertNotNull($first->fresh());
        $this->assertSame($otherWorkspace->id, $foreignRoot->fresh()?->workspace_id);
    }

    public function test_a_same_workspace_cross_company_invoice_chain_fails_closed(): void
    {
        $entry = $this->approvedEntry();
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other local client',
            'slug' => 'other-local-client',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'WRONG-COMPANY-DRAFT',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
        ]);
        $line = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'time',
            'description' => 'Wrong company line',
            'quantity' => '1.0000',
            'unit_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('A cross-company invoice chain must stop the edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('The time entry invoice allocation has an inconsistent client company.', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame(12000, $invoice->fresh()?->total_amount);
    }

    #[DataProvider('immutableInvoiceStatuses')]
    public function test_every_non_draft_or_unknown_invoice_status_keeps_time_frozen(string $status): void
    {
        $entry = $this->approvedEntry();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'IMMUTABLE-'.strtoupper($status),
            'status' => $status,
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
        ]);
        $line = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'time',
            'description' => 'Immutable time',
            'quantity' => '1.0000',
            'unit_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail("Time on a {$status} invoice must remain frozen.");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('Time on an issued, paid, void, or unknown invoice cannot be changed.', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame(12000, $invoice->fresh()?->total_amount);
    }

    /** @return list<array{string}> */
    public static function immutableInvoiceStatuses(): array
    {
        return [['issued'], ['partially_paid'], ['paid'], ['void'], ['unexpected_status']];
    }

    private function agreement(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly monthly agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-06-01',
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 12000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
    }

    private function quarterlyAgreement(): ClientAgreement
    {
        $agreement = $this->agreement();
        $agreement->forceFill([
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
            'retainer_minutes' => 120,
        ])->save();

        return $agreement->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function approvedEntry(array $attributes = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($attributes + [
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-14',
            'minutes' => 60,
            'description' => 'Approved invoice work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 12000,
            'billing_rate_source' => 'agreement',
            'currency' => 'USD',
        ]);
    }

    private function generateJuly(ClientAgreement $agreement): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
            $agreement,
        )->refresh();
    }
}
