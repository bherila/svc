<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DeferredBacklogReader;
use App\Services\Billing\DeferredBuyDownAction;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\DeferredBuyDownRequest;
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
 * invoice of its own: the reopened-invoice case (#594), where the extra month
 * a client agreed to pay must land on the invoice being reissued.
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

    public function test_a_reopened_invoice_carries_the_settlement_through_its_reissue(): void
    {
        $this->ordinary(600, '2026-04-10');
        $this->ordinary(600, '2026-05-10');
        $first = $this->entry(180, '2026-04-01');
        $second = $this->entry(60, '2026-04-02');
        $lifecycle = app(InvoiceLifecycleService::class);
        $issued = $lifecycle->issue($this->cadenceDraft(), $this->workspace);
        $number = $issued->invoice_number;

        $reopened = $lifecycle->reopen($issued, 'Synthetic extra month agreed', $this->workspace);
        $this->assertSame('draft', $reopened->status);

        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $attached = $billing->create($this->workspace, $this->agreement, $request, $preview->version, $reopened);
        $this->assertSame($issued->id, $attached->id);
        $this->assertSame(140000, $attached->total_amount);

        $reissued = $lifecycle->issue($attached, $this->workspace);
        $this->assertSame($number, $reissued->invoice_number);
        $this->assertSame('issued', $reissued->status);
        $this->assertSame(2, $reissued->document_revision);
        $this->assertNotNull($reissued->reissued_at);
        $this->assertSame(140000, $reissued->total_amount);
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
