<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DeferredBacklogReader;
use App\Services\Billing\DeferredBuyDownAction;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\DeferredBuyDownRequest;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceHoursStatementRows;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A settlement attached to an existing cadence draft instead of a settlement
 * invoice of its own: the reopened-invoice case (#594), where the settlement
 * must land on the invoice being reissued.
 */
final class DeferredBuyDownOnInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    private ClientProject $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $this->owner = User::factory()->create(['email' => 'buydown-on-invoice-owner@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic buy-down on invoice', 'slug' => 'synthetic-buydown-on-invoice']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-buydown-on-invoice']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic project']);
        $this->agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-04-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000]);
    }

    public function test_cadence_document_lists_the_remaining_deferred_entries_oldest_first(): void
    {
        $this->ordinary(600, '2026-05-10');
        $oldest = $this->entry(120, '2026-04-01');
        $visible = $this->entry(180, '2026-04-02');
        $visible->forceFill([
            'description' => 'Synthetic internal note',
            'is_visible_to_client' => true,
            'client_visible_description' => 'Client-safe deferred work',
        ])->save();

        $invoice = $this->cadenceDraft();
        $snapshot = $invoice->fresh()?->deferredWorkSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame([$oldest->id, $visible->id], array_column($snapshot->toArray()['entries'], 'id'));
        $this->assertSame(5.0, $snapshot->documentData()['total_hours']);

        $html = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT)->render();
        $this->assertStringContainsString('Still deferred', $html);
        $this->assertStringContainsString('2 entries', $html);
        $this->assertStringContainsString('5.0000 hours', $html);
        $this->assertStringContainsString('oldest worked date 2026-04-01', $html);
        $this->assertStringContainsString('Client-safe deferred work', $html);
        $this->assertStringNotContainsString('Synthetic deferred work', $html);
        $this->assertStringNotContainsString('Synthetic internal note', $html);
        $this->assertLessThan(strpos($html, '2026-04-02'), strpos($html, '2026-04-01'));
    }

    public function test_cadence_document_hides_a_backlog_fully_applied_to_its_pool(): void
    {
        $this->entry(60, '2026-05-05');

        $invoice = $this->cadenceDraft();

        $this->assertNull($invoice->fresh()?->deferredWorkSnapshot());
        $this->assertStringNotContainsString(
            'Still deferred',
            app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT)->render(),
        );
    }

    public function test_attach_reserves_the_selection_on_the_cadence_draft_and_shrinks_the_backlog(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $first = $this->entry(180, '2026-04-01');
        $second = $this->entry(60, '2026-04-02');
        $last = $this->entry(120, '2026-04-15');
        $draft = $this->cadenceDraft();
        $this->assertSame(InvoiceKind::CadencePeriod->value, $draft->invoice_kind);
        $this->assertSame(100000, $draft->total_amount);
        $number = $draft->invoice_number;
        $cycle = [$draft->cycle_start?->toDateString(), $draft->cycle_end?->toDateString()];
        $period = [$draft->service_period_start?->toDateString(), $draft->service_period_end?->toDateString()];

        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);

        $this->assertSame($draft->id, $invoice->id, 'No new invoice is allocated');
        $this->assertSame($number, $invoice->invoice_number);
        $this->assertSame(InvoiceKind::CadencePeriod->value, $invoice->invoice_kind);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame([$cycle, $period], [
            [$invoice->cycle_start?->toDateString(), $invoice->cycle_end?->toDateString()],
            [$invoice->service_period_start?->toDateString(), $invoice->service_period_end?->toDateString()],
        ], 'The host keeps its cadence cycle and work period');
        $settlement = $invoice->lines()->where('type', 'deferred_buydown')->sole();
        $this->assertSame(40000, $settlement->total_amount);
        $this->assertSame(10000, $settlement->unit_amount);
        $this->assertSame('4.0000', $settlement->hours);
        // Dated on the host's work-period end, not the day of the buy-down, so
        // a regeneration cannot widen the period it reconciles.
        $this->assertSame('2026-05-31', $settlement->line_date?->toDateString());
        $this->assertSame([$first->id, $second->id], $settlement->timeEntries()->pluck('client_time_entries.id')->all());
        $this->assertSame(140000, $invoice->total_amount);
        $this->assertSame(140000, $invoice->balance_amount);
        $this->assertSame(0.0, (float) $invoice->hours_billed_at_rate, 'A settlement consumes no retainer capacity and bills no catch-up');
        $this->assertSame([$last->id], $this->backlogIds());
    }

    public function test_attach_takes_settled_work_out_of_the_hours_statement_the_client_is_sent(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(240, '2026-04-01');
        $this->entry(120, '2026-04-02');
        // Offered by the backlog but after the host's work period, so the
        // statement never counted it.
        $this->entry(60, '2026-06-02');
        $draft = $this->cadenceDraft();
        $before = $draft->fresh()?->hoursStatement();
        $this->assertNotNull($before);
        $this->assertSame([6.0, 2], [$before->deferredBacklogHours, $before->deferredBacklogEntries]);
        $deferredBefore = $draft->fresh()?->deferredWorkSnapshot();
        $this->assertNotNull($deferredBefore);
        $this->assertSame(7.0, $deferredBefore->documentData()['total_hours']);

        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);

        $after = $draft->fresh()?->hoursStatement();
        $this->assertNotNull($after);
        $this->assertSame([2.0, 1], [$after->deferredBacklogHours, $after->deferredBacklogEntries]);
        $deferredAfter = $draft->fresh()?->deferredWorkSnapshot();
        $this->assertNotNull($deferredAfter);
        $this->assertSame([1, 1.0, '2026-06-02'], [
            $deferredAfter->documentData()['entry_count'],
            $deferredAfter->documentData()['total_hours'],
            $deferredAfter->documentData()['oldest_worked_on'],
        ]);
    }

    public function test_settling_work_after_the_hosts_period_leaves_its_statement_alone(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(240, '2026-04-01');
        // The four-hour entry does not fit a one-hour target and is passed over,
        // so this is what settles, and the statement never counted it.
        $later = $this->entry(60, '2026-06-02');
        $draft = $this->cadenceDraft();

        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $host = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);

        $this->assertSame([$later->id], $host->lines()->where('type', 'deferred_buydown')->sole()->timeEntries()->pluck('client_time_entries.id')->all());
        $statement = $draft->fresh()?->hoursStatement();
        $this->assertNotNull($statement);
        $this->assertSame([4.0, 1], [$statement->deferredBacklogHours, $statement->deferredBacklogEntries]);
    }

    public function test_attach_leaves_a_draft_without_a_statement_without_one(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(60, '2026-04-01');
        $draft = $this->cadenceDraft();
        $draft->forceFill(['hours_statement' => null])->save();

        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);

        $this->assertNull($draft->fresh()?->hours_statement);
    }

    public function test_regenerating_a_host_reaches_the_statement_attaching_left_and_never_reads_as_termination(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(240, '2026-04-01');
        $this->entry(120, '2026-04-02');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $host = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        $attached = $host->fresh()?->hoursStatement();
        $this->assertNotNull($attached);

        $regenerated = app(ClientInvoicingService::class)->regenerateDraftInvoice($host->fresh())?->fresh()?->hoursStatement();

        $this->assertNotNull($regenerated);
        $this->assertSame(0.0, $regenerated->deferredBilledOnTerminationHours);
        $this->assertEquals($attached->toArray(), $regenerated->toArray());
        $labels = collect(InvoiceHoursStatementRows::for($regenerated))->flatMap(fn (array $section): array => array_column($section['rows'], 'label'));
        $this->assertFalse($labels->contains(fn (string $label): bool => str_contains($label, 'termination')));
    }

    public function test_attach_records_what_it_changed_in_the_client_history(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(60, '2026-04-01');
        $this->entry(60, '2026-04-02');
        $draft = $this->cadenceDraft();
        $before = ClientCompanyActivity::query()->where('workspace_id', $this->workspace->id)->count();
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 2]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);

        $activities = ClientCompanyActivity::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->get();
        $this->assertCount($before + 1, $activities);
        $activity = $activities->last();
        $this->assertSame('invoice.updated', $activity?->action);
        $this->assertSame($draft->public_id, $activity?->subject_public_id);
        $this->assertSame(['invoice_kind' => 'cadence_period', 'total_amount' => 120000, 'currency' => 'USD', 'settled_deferred_entry_count' => 2], $activity?->payload);
    }

    public function test_attach_with_a_discount_writes_the_same_adjustment_as_a_standalone_settlement(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(180, '2026-04-01');
        $this->entry(60, '2026-04-02');
        $draft = $this->cadenceDraft();
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4, 'discount_amount' => 1000, 'discount_reason' => 'Synthetic discount']);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);

        $this->assertSame(['deferred_buydown', 'adjustment'], $invoice->lines()->whereIn('type', ['deferred_buydown', 'adjustment'])->pluck('type')->all());
        $adjustment = $invoice->lines()->where('type', 'adjustment')->sole();
        $this->assertSame(-1000, $adjustment->total_amount);
        $this->assertSame('2026-05-31', $adjustment->line_date?->toDateString());
        $this->assertSame(139000, $invoice->total_amount);
    }

    public function test_an_issued_target_is_refused_and_reserves_nothing(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(60, '2026-04-01');
        $issued = app(InvoiceLifecycleService::class)->issue($this->cadenceDraft(), $this->workspace);

        $this->assertAttachRefused($issued, 'can only be attached to a draft invoice');
        $this->assertSame(0, $issued->lines()->where('type', 'deferred_buydown')->count());
    }

    public function test_a_non_cadence_target_is_refused_and_reserves_nothing(): void
    {
        $this->entry(60, '2026-04-01');
        $adHoc = app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->company, [
            'invoice_number' => 'SYNTHETIC-AD-HOC', 'currency' => 'USD', 'client_agreement_id' => $this->agreement->id,
        ], [['type' => 'service', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 5000]]);

        $this->assertAttachRefused($adHoc, 'can only be attached to a cadence invoice');
    }

    public function test_a_settlement_invoice_cannot_host_a_second_settlement(): void
    {
        $this->entry(120, '2026-04-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 2]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $settlement = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        $this->assertSame(InvoiceKind::DeferredBuyDown->value, $settlement->invoice_kind);

        $this->entry(60, '2026-04-02');
        $this->assertAttachRefused($settlement, 'can only be attached to a cadence invoice');
    }

    public function test_a_draft_for_another_agreement_is_refused_and_reserves_nothing(): void
    {
        $this->entry(60, '2026-04-01');
        $other = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic other retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 50000, 'hourly_rate_amount' => 5000]);
        $draft = app(InvoiceLifecycleService::class)->createDraft($this->workspace, $this->company, [
            'invoice_number' => 'SYNTHETIC-OTHER-AGREEMENT', 'currency' => 'USD', 'client_agreement_id' => $other->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ], []);

        $this->assertAttachRefused($draft, 'was not generated for the agreement');
    }

    public function test_a_draft_for_another_company_is_refused_and_reserves_nothing(): void
    {
        $this->entry(60, '2026-04-01');
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-other-company']);
        $draft = app(InvoiceLifecycleService::class)->createDraft($this->workspace, $otherCompany, [
            'invoice_number' => 'SYNTHETIC-OTHER-COMPANY', 'currency' => 'USD', 'client_agreement_id' => $this->agreement->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ], []);

        $this->assertAttachRefused($draft, 'different client company');
    }

    public function test_a_shelf_changed_since_preview_is_a_conflict_and_attaches_nothing(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $entry = $this->entry(60, '2026-04-01');
        $draft = $this->cadenceDraft();
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $entry->update(['minutes' => 120]);
        try {
            $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);
            $this->fail('A stale shelf must refuse.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(0, $draft->lines()->where('type', 'deferred_buydown')->count());
        $this->assertSame(100000, $draft->fresh()?->total_amount);
    }

    public function test_regenerating_the_host_draft_keeps_the_settlement_lines_and_their_claim(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $first = $this->entry(180, '2026-04-01');
        $second = $this->entry(60, '2026-04-02');
        $last = $this->entry(120, '2026-04-15');
        $draft = $this->cadenceDraft();
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $draft);
        $line = $attached->lines()->where('type', 'deferred_buydown')->sole();

        $regenerated = app(ClientInvoicingService::class)->regenerateDraftInvoice($attached->fresh());
        $this->assertNotNull($regenerated);
        $kept = $regenerated->lines()->where('type', 'deferred_buydown')->sole();
        $this->assertSame($line->id, $kept->id, 'The settlement line is the same row, not a rebuilt copy');
        $this->assertSame([$first->id, $second->id], $kept->timeEntries()->pluck('client_time_entries.id')->all());
        $this->assertSame(140000, $regenerated->total_amount, 'The rebuilt totals still carry the settlement');
        $this->assertSame('2026-05-31', $regenerated->service_period_end?->toDateString(), 'The settlement does not widen the work period');
        $this->assertSame([$last->id], $this->backlogIds());
    }

    public function test_discard_and_void_of_the_host_draft_release_the_reservation_back_to_the_shelf(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $first = $this->entry(180, '2026-04-01');
        $second = $this->entry(60, '2026-04-02');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        $this->assertSame([], $this->backlogIds());

        app(InvoiceLifecycleService::class)->discardDraft($attached, $this->workspace, 'Synthetic cancellation');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->backlogIds());
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);

        // A second host, voided as a draft this time.
        $april = app(ClientInvoicingService::class)->generateInvoice($this->company, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), $this->agreement);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $april);
        $this->assertSame([], $this->backlogIds());

        app(InvoiceLifecycleService::class)->void($attached, $this->workspace, 'Synthetic void');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->backlogIds());
        $this->assertSame('approved', $first->fresh()?->status);
    }

    public function test_issuing_the_host_bills_the_settlement_with_it_and_voiding_the_issued_host_releases_it(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $entry = $this->entry(60, '2026-04-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());

        $issued = app(InvoiceLifecycleService::class)->issue($attached, $this->workspace);
        $this->assertSame('issued', $issued->status);
        $this->assertSame(110000, $issued->total_amount);
        $this->assertSame('invoiced', $entry->fresh()?->status);
        $this->assertSame([], $this->backlogIds());

        app(InvoiceLifecycleService::class)->void($issued, $this->workspace, 'Synthetic void');
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame([$entry->id], $this->backlogIds());
    }

    public function test_reopening_unwinds_a_settlement_and_it_can_be_attached_and_billed_again_once(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $first = $this->entry(180, '2026-04-01');
        $second = $this->entry(60, '2026-04-02');
        $lifecycle = app(InvoiceLifecycleService::class);
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4, 'discount_amount' => 1000, 'discount_reason' => 'Synthetic discount']);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $host = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        $issued = $lifecycle->issue($host, $this->workspace);
        $number = $issued->invoice_number;

        $reopened = $lifecycle->reopen($issued, 'Correct the settlement', $this->workspace);
        $this->assertSame('draft', $reopened->status);
        $this->assertSame(100000, $reopened->total_amount, 'Only cadence charges remain');
        $this->assertSame(0, $reopened->lines()->where('type', 'deferred_buydown')->count());
        $this->assertSame(0, $reopened->lines()->where('type', 'adjustment')->count());
        $this->assertSame('approved', $first->fresh()?->status);
        $this->assertSame('approved', $second->fresh()?->status);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->backlogIds());
        $statement = $reopened->hoursStatement();
        $this->assertNotNull($statement);
        $this->assertSame([4.0, 2], [$statement->deferredBacklogHours, $statement->deferredBacklogEntries]);
        $this->assertSame([
            'settled_deferred_entry_count' => 2,
            'unwound_amount' => 39000,
            'currency' => 'USD',
        ], ClientCompanyActivity::query()->where('workspace_id', $this->workspace->id)
            ->where('action', 'invoice.deferred_buydown_unwound')->sole()->payload);
        $beforeSettlement = $reopened->deferredWorkSnapshot();
        $this->assertNotNull($beforeSettlement);
        $this->assertSame(4.0, $beforeSettlement->documentData()['total_hours']);

        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_map(
            static fn (ClientTimeEntry $entry): int => $entry->id,
            $preview->selection->entries,
        ));
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $reopened);
        $this->assertSame($issued->id, $attached->id);
        $this->assertSame(139000, $attached->total_amount);
        $this->assertNull($attached->deferredWorkSnapshot());

        $reissued = $lifecycle->issue($attached, $this->workspace);
        $this->assertSame($number, $reissued->invoice_number);
        $this->assertSame('issued', $reissued->status);
        $this->assertSame(2, $reissued->document_revision);
        $this->assertNotNull($reissued->reissued_at);
        $this->assertSame(139000, $reissued->total_amount);
        $this->assertSame(1, $reissued->lines()->where('type', 'deferred_buydown')->count());
        $this->assertSame(1, $reissued->lines()->where('type', 'adjustment')->count());
        $this->assertSame([$first->id, $second->id], $reissued->lines()->where('type', 'deferred_buydown')->sole()
            ->timeEntries()->pluck('client_time_entries.id')->all());
        $this->assertSame('invoiced', $first->fresh()?->status);
        $this->assertSame('invoiced', $second->fresh()?->status);
        $this->assertSame([], $this->backlogIds());
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count(), 'Still the one document the client was sent');
    }

    public function test_replay_and_rehearsal_preserve_the_attached_settlement_and_its_claim(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $entry = $this->entry(60, '2026-04-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        $line = $entry->invoiceLines()->firstOrFail();
        $total = $attached->fresh()?->total_amount;
        foreach (['svc:billing:replay', 'svc:billing:rehearse-generation'] as $command) {
            $arguments = ['--workspace' => $this->workspace->public_id, '--format' => 'json'];
            if ($command === 'svc:billing:rehearse-generation') {
                $arguments['--show'] = true;
            }
            $exit = Artisan::call($command, $arguments);
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $exit, (string) json_encode($report));
            if ($command === 'svc:billing:replay') {
                $this->assertSame(0, $report['summary']['unexplained']);
            }
            $this->assertSame($total, $attached->fresh()?->total_amount);
            $this->assertSame($line->id, $entry->invoiceLines()->firstOrFail()->id);
        }
    }

    public function test_replay_accepts_an_issued_host_with_a_discounted_settlement(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $settled = $this->entry(60, '2026-04-01');
        $this->entry(180, '2026-04-02');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1, 'discount_amount' => 1000, 'discount_reason' => 'Synthetic discount']);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $host = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        app(InvoiceLifecycleService::class)->issue($host->fresh() ?? $host, $this->workspace);
        $backlog = $this->backlogIds();

        $exit = Artisan::call('svc:billing:replay', ['--workspace' => $this->workspace->public_id, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, (string) json_encode($report));
        $this->assertSame(0, $report['summary']['unexplained']);
        $this->assertSame(0, $report['summary']['unexplained_divergence_minor']);
        $this->assertNotContains($settled->id, $backlog, 'The settled entry is off the shelf');
        $this->assertSame($backlog, $this->backlogIds(), 'Replay rolled back');
        $this->assertSame('invoiced', $settled->fresh()?->status);
    }

    public function test_rest_creation_with_an_invoice_id_attaches_instead_of_creating(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $this->entry(60, '2026-04-01');
        $draft = $this->cadenceDraft();
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read', 'billing:write']);
        $base = '/api/v1/workspaces/'.$this->workspace->public_id.'/agreements/'.$this->agreement->public_id.'/deferred-backlog';
        $preview = $this->getJson($base.'/preview?hours=1')->assertOk()->json('data');

        $created = $this->postJson($base.'/invoices', [
            'hours' => 1, 'expected_backlog_version' => $preview['backlog_version'], 'invoice_id' => $draft->public_id,
        ], ['Idempotency-Key' => 'synthetic-buydown-on-invoice'])->assertCreated()->json('data');

        $this->assertSame($draft->public_id, $created['id']);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(110000, $draft->fresh()?->total_amount);

        $this->postJson($base.'/invoices', [
            'hours' => 1, 'expected_backlog_version' => $preview['backlog_version'], 'invoice_id' => '00000000-0000-0000-0000-000000000000',
        ], ['Idempotency-Key' => 'synthetic-buydown-missing-host'])->assertNotFound();
    }

    public function test_a_paid_issued_host_with_a_settlement_still_cannot_be_reopened(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $entry = $this->entry(60, '2026-04-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $lifecycle = app(InvoiceLifecycleService::class);
        $issued = $lifecycle->issue($billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft()), $this->workspace);
        $lifecycle->applyPayment($issued, [
            'amount' => 110000, 'currency' => 'USD', 'method' => 'ach', 'status' => 'succeeded', 'received_on' => '2026-06-15',
        ], $this->workspace);
        try {
            $lifecycle->reopen($issued->fresh(), 'Synthetic paid settlement reopen', $this->workspace);
            $this->fail('A paid invoice cannot be reopened, even when it carries a settlement.');
        } catch (DomainException $refusal) {
            $this->assertSame('A paid invoice cannot be reopened.', $refusal->getMessage());
        }

        $fresh = $issued->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(110000, $fresh->total_amount);
        $this->assertSame('invoiced', $entry->fresh()?->status);
        $this->assertSame([$entry->id], $fresh->lines()->where('type', 'deferred_buydown')->sole()->timeEntries()->pluck('client_time_entries.id')->all());
        $this->assertSame([], $this->backlogIds(), 'The settled work must not return to the shelf to be billed again');
    }

    public function test_a_cadence_invoice_without_a_settlement_can_still_be_reopened(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $lifecycle = app(InvoiceLifecycleService::class);
        $issued = $lifecycle->issue($this->cadenceDraft(), $this->workspace);

        $this->assertSame('draft', $lifecycle->reopen($issued, 'Synthetic reopen', $this->workspace)->status);
    }

    /** Every door that changes an allocated entry reaches the same freeze. */
    public function test_settled_time_on_a_host_cannot_be_edited_deleted_or_returned_to_draft(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $entry = $this->entry(60, '2026-04-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $host = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        $this->actingAsMcp($this->owner, ['mcp:use', 'time:read', 'time:write', 'time:approve', 'billing:read', 'billing:write']);
        $url = '/api/v1/workspaces/'.$this->workspace->public_id.'/time-entries/'.$entry->public_id;
        $version = AgentApiVersion::for($entry->fresh());

        foreach ([
            'edit' => ['PATCH', $url, ['expected_version' => $version, 'minutes' => 240]],
            'delete' => ['DELETE', $url, ['expected_version' => $version]],
            'unapprove' => ['POST', $url.'/unapprove', ['expected_version' => $version]],
        ] as $door => [$method, $path, $body]) {
            $this->json($method, $path, $body, ['Idempotency-Key' => 'synthetic-settled-'.$door])
                ->assertConflict()->assertJsonPath('message', 'The time entry was not changed because this invoice settles it as deferred work. Void or discard the invoice to change it.');

            $fresh = ClientTimeEntry::withTrashed()->whereKey($entry->id)->sole();
            $this->assertSame(60, $fresh->minutes, $door);
            $this->assertSame('approved', $fresh->status, $door);
            $this->assertFalse($fresh->trashed(), $door);
            $this->assertSame(110000, $host->fresh()?->total_amount, $door);
        }
    }

    public function test_other_time_on_a_host_stays_editable_and_the_settlement_survives_the_rebuild(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        $this->ordinary(600, '2026-04-10');
        $ordinary = $this->ordinary(600, '2026-05-10');
        $this->entry(60, '2026-04-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $host = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());
        $line = $host->lines()->where('type', 'deferred_buydown')->sole();
        $this->actingAsMcp($this->owner, ['mcp:use', 'time:read', 'time:write', 'billing:read', 'billing:write']);

        $this->patchJson('/api/v1/workspaces/'.$this->workspace->public_id.'/time-entries/'.$ordinary->public_id,
            ['expected_version' => AgentApiVersion::for($ordinary->fresh()), 'minutes' => 300],
            ['Idempotency-Key' => 'synthetic-unsettled-edit'])->assertOk();

        $this->assertSame(300, $ordinary->fresh()?->minutes);
        $this->assertSame($line->id, $host->fresh()?->lines()->where('type', 'deferred_buydown')->sole()->id);
    }

    public function test_the_time_sheet_does_not_offer_to_edit_settled_time_on_a_host(): void
    {
        $this->ordinary(600, '2026-04-10');
        $ordinary = $this->ordinary(600, '2026-05-10');
        $settled = $this->entry(60, '2026-05-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $billing->create($this->workspace, $this->agreement, $request, $preview->version, $this->cadenceDraft());

        $response = $this->actingAs($this->owner, 'web')
            ->get('/workspaces/'.$this->workspace->public_id.'/clients/'.$this->company->public_id.'/time')
            ->assertOk();
        $rows = collect($response->viewData('page')['props']['months'])->flatMap(fn (array $month): array => $month['entries'])->keyBy('id');

        $this->assertFalse($rows[$settled->public_id]['can_edit'], 'Settled time is frozen');
        $this->assertTrue($rows[$ordinary->public_id]['can_edit'], 'Other time on the same draft stays editable');
    }

    public function test_an_invoice_in_another_workspace_cannot_host_a_settlement(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $entry = $this->entry(60, '2026-04-01');
        $otherWorkspace = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other-workspace']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Synthetic foreign company', 'slug' => 'synthetic-foreign-company']);
        $foreign = app(InvoiceLifecycleService::class)->createDraft($otherWorkspace, $otherCompany, [
            'invoice_number' => 'SYNTHETIC-FOREIGN', 'currency' => 'USD', 'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ], []);
        $this->actingAsMcp($this->owner, ['mcp:use', 'billing:read', 'billing:write']);
        $base = '/api/v1/workspaces/'.$this->workspace->public_id.'/agreements/'.$this->agreement->public_id.'/deferred-backlog';
        $preview = $this->getJson($base.'/preview?hours=1')->assertOk()->json('data');

        $this->postJson($base.'/invoices', [
            'hours' => 1, 'expected_backlog_version' => $preview['backlog_version'], 'invoice_id' => $foreign->public_id,
        ], ['Idempotency-Key' => 'synthetic-buydown-foreign-host'])->assertNotFound();

        $this->assertSame(0, $foreign->lines()->count());
        $this->assertSame([$entry->id], $this->backlogIds(), 'A refused attach reserves nothing');

        // The action refuses too, for a caller that resolved the invoice itself.
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        try {
            $billing->create($this->workspace, $this->agreement, $request, $billing->preview($this->workspace, $this->agreement, $request)->version, $foreign);
            $this->fail('A foreign invoice must be refused.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame(0, $foreign->lines()->count());
    }

    private function assertAttachRefused(ClientInvoice $target, string $message): void
    {
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        try {
            $billing->create($this->workspace, $this->agreement, $request, $preview->version, $target);
            $this->fail('The attach must be refused: '.$message);
        } catch (DomainException $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }
        $this->assertNotSame([], $this->backlogIds(), 'A refused attach reserves nothing');
    }

    /** @return list<int> */
    private function backlogIds(): array
    {
        return app(DeferredBacklogReader::class)->forAgreement($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'))->entryIds;
    }

    private function cadenceDraft(): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice($this->company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), $this->agreement);
    }

    private function ordinary(int $minutes, string $date): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->owner->id, 'worked_on' => $date, 'minutes' => $minutes,
            'description' => 'Synthetic ordinary work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false,
            'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }

    private function entry(int $minutes, string $date): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->owner->id, 'worked_on' => $date, 'minutes' => $minutes,
            'description' => 'Synthetic deferred work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => true,
            'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }
}
