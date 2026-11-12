<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DeferredBacklogReader;
use App\Services\Billing\DeferredBillingAllocator;
use App\Services\Billing\DeferredBuyDownAction;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\DeferredBuyDownRequest;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DeferredBuyDownTest extends TestCase
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
        $this->owner = User::factory()->create(['email' => 'buydown-owner@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic buy-down', 'slug' => 'synthetic-buydown']);
        WorkspaceMembership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-buydown']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic project']);
        $this->agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000]);
    }

    public function test_fifo_targets_whole_entries_and_explicit_round_up(): void
    {
        $first = $this->entry(180, '2026-01-01');
        $second = $this->entry(60, '2026-01-01');
        $third = $this->entry(120, '2026-02-01');
        $billing = app(DeferredBuyDownAction::class);
        $exact = $billing->preview($this->workspace, $this->agreement, DeferredBuyDownRequest::from(['hours' => 4]))->toArray();
        $this->assertSame([$first->public_id, $second->public_id], $exact['entry_ids']);
        $this->assertSame(240, $exact['minutes']);
        $this->assertSame(40000, $exact['total_amount']);
        $small = $billing->preview($this->workspace, $this->agreement, DeferredBuyDownRequest::from(['hours' => 2.5]))->toArray();
        $this->assertSame([$second->public_id], $small['entry_ids']);
        $rounded = $billing->preview($this->workspace, $this->agreement, DeferredBuyDownRequest::from(['hours' => 2.5, 'round_up' => true]))->toArray();
        $this->assertSame([$first->public_id], $rounded['entry_ids']);
        $amount = $billing->preview($this->workspace, $this->agreement, DeferredBuyDownRequest::from(['amount' => 42000, 'discount_amount' => 1000, 'discount_reason' => 'Synthetic agreement discount']))->toArray();
        $this->assertSame($exact['entry_ids'], $amount['entry_ids']);
        $this->assertSame(39000, $amount['total_amount']);
        $this->assertSame($exact['backlog_version'], $amount['backlog_version']);
        $this->assertSame(3, ClientTimeEntry::query()->count());
        $this->assertDatabaseCount('client_invoice_lines', 0);
    }

    public function test_draft_reservation_termination_remainder_and_discard_release_use_the_same_candidate_set(): void
    {
        $first = $this->entry(180, '2026-01-01');
        $second = $this->entry(60, '2026-01-02');
        $last = $this->entry(120, '2026-02-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4, 'discount_amount' => 1000, 'discount_reason' => 'Synthetic discount']);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        $this->assertSame(InvoiceKind::DeferredBuyDown->value, $invoice->invoice_kind);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame(39000, $invoice->total_amount);
        $this->assertSame('0.0000', $invoice->hours_billed_at_rate);
        $this->assertSame(['deferred_buydown', 'adjustment'], $invoice->lines->pluck('type')->all());
        $this->assertSame(-1000, $invoice->lines[1]->total_amount);
        $this->assertSame('2026-06-15', $invoice->lines[0]->line_date?->toDateString());
        $movement = app(DeferredBacklogReader::class)->forAgreement($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'), ['2026-06']);
        $this->assertSame(240, $movement->trend[0]->minutesBilled);
        $this->assertSame(240, $movement->trend[0]->minutesBoughtDown);
        $this->assertSame(-240, $movement->trend[0]->netMinutes());
        $this->assertSame(10000, $invoice->lines[0]->unit_amount);
        $this->assertSame('4.0000', $invoice->lines[0]->hours);
        $this->assertSame([$first->id, $second->id], $invoice->lines[0]->timeEntries()->pluck('client_time_entries.id')->all());
        $remainder = app(DeferredBillingAllocator::class)->collectForTermination($this->company, Carbon::parse('2026-06-15'), $this->agreement);
        $this->assertSame([$last->id], $remainder->modelKeys());
        $this->assertSame([$last->id], app(DeferredBacklogReader::class)->forAgreement($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'))->entryIds);
        $allocation = app(DeferredBillingAllocator::class)->allocate($this->company, Carbon::parse('2026-06-15'), 100, $this->agreement);
        $this->assertCount(1, $allocation->billed);
        app(InvoiceLifecycleService::class)->discardDraft($invoice, $this->workspace, 'Synthetic cancellation');
        $this->assertCount(3, app(DeferredBillingAllocator::class)->collectForTermination($this->company, Carbon::parse('2026-06-15'), $this->agreement));
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);
    }

    public function test_mixed_pricing_groups_keep_their_hours_rates_project_and_entry_links_on_the_draft(): void
    {
        $consultant = $this->entry(60, '2026-01-01');
        $project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic flat-hourly project']);
        $flat = $this->entry(90, '2026-01-02');
        $flat->update(['client_project_id' => $project->id, 'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD']);
        $direct = $this->entry(60, '2026-01-03');
        $direct->update(['subcontractor_billing_mode' => 'direct']);
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 3]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        $this->assertSame(17500, $invoice->total_amount);
        $lines = $invoice->lines()->orderBy('unit_amount', 'desc')->with('timeEntries')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['1.0000', '1.5000'], $lines->pluck('hours')->all());
        $this->assertSame(['1.0000', '1.5000'], $lines->pluck('quantity')->all());
        $this->assertSame([10000, 5000], $lines->pluck('unit_amount')->all());
        $this->assertSame([10000, 7500], $lines->pluck('total_amount')->all());
        $this->assertSame(['deferred_buydown', 'deferred_buydown'], $lines->pluck('type')->all());
        $this->assertSame($project->id, $lines[1]->client_project_id);
        $this->assertSame([$consultant->id], $lines[0]->timeEntries->modelKeys());
        $this->assertSame([$flat->id], $lines[1]->timeEntries->modelKeys());
        $this->assertFalse($direct->invoiceLines()->exists());
    }

    public function test_issued_unpaid_work_stays_reserved_and_void_restores_it(): void
    {
        $entry = $this->entry(60, '2026-01-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        $issued = app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
        $this->assertSame('issued', $issued->status);
        $this->assertSame('invoiced', $entry->fresh()->status);
        $this->assertSame([], app(DeferredBacklogReader::class)->forAgreement($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'))->entryIds);
        app(InvoiceLifecycleService::class)->void($issued, $this->workspace, 'Synthetic void');
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame([$entry->id], app(DeferredBacklogReader::class)->forAgreement($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'))->entryIds);
    }

    public function test_a_shelf_changed_since_preview_is_a_conflict_and_reserves_nothing(): void
    {
        $entry = $this->entry(60, '2026-01-01');
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $billing = app(DeferredBuyDownAction::class);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $entry->update(['minutes' => 120]);
        try {
            $billing->create($this->workspace, $this->agreement, $request, $preview->version);
            $this->fail('A stale shelf must refuse.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('client_invoices', 0);
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function agreementEligibility(): array
    {
        return [
            'active' => [['status' => 'active'], true],
            'paused' => [['status' => 'paused'], true],
            'terminated' => [['status' => 'terminated', 'ends_on' => '2026-05-31'], true],
            'expired' => [['status' => 'expired', 'ends_on' => '2026-05-31'], true],
            'draft' => [['status' => 'draft'], false],
            'future terms' => [['starts_on' => '2026-07-01'], false],
            'one-time terms' => [['billing_cadence' => 'one_time'], false],
        ];
    }

    #[DataProvider('agreementEligibility')]
    public function test_buydown_requires_recurring_terms_already_in_force_or_ended(array $attributes, bool $allowed): void
    {
        $entry = $this->entry(60, '2026-05-01');
        $this->agreement->update($attributes);
        if (! $allowed) {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Deferred buy-down requires a recurring agreement');
        }
        $preview = app(DeferredBuyDownAction::class)->preview($this->workspace, $this->agreement, DeferredBuyDownRequest::from(['hours' => 1]));
        $this->assertSame([$entry->public_id], $preview->toArray()['entry_ids']);
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_changed_unpriceable_terms_refuse_a_confirmation_as_stale_before_pricing(): void
    {
        $this->entry(60, '2026-01-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $this->agreement->update(['hourly_rate_amount' => null]);
        try {
            $billing->create($this->workspace, $this->agreement, $request, $preview->version);
            $this->fail('Changed terms must invalidate the preview.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRequests(): array
    {
        return ['missing target' => [[]], 'both targets' => [['hours' => 1, 'amount' => 100]], 'zero' => [['hours' => 0]],
            'fractional minute' => [['hours' => 0.001]], 'fractional money' => [['amount' => 1.5]],
            'discount needs reason' => [['hours' => 1, 'discount_amount' => 100]], 'blank reason' => [['hours' => 1, 'discount_amount' => 100, 'discount_reason' => '  ']]];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_targets_and_unexplained_discounts_are_refused(array $input): void
    {
        $this->expectException(ValidationException::class);
        DeferredBuyDownRequest::from($input);
    }

    public function test_discount_cannot_exceed_the_contract_charge(): void
    {
        $this->entry(60, '2026-01-01');
        $this->expectException(DomainException::class);
        app(DeferredBuyDownAction::class)->preview($this->workspace, $this->agreement,
            DeferredBuyDownRequest::from(['hours' => 1, 'discount_amount' => 10001, 'discount_reason' => 'Synthetic invalid discount']));
    }

    public function test_replay_and_rehearsal_preserve_buydown_history_and_its_claims(): void
    {
        $entry = $this->entry(60, '2026-01-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        $before = $invoice->refresh()->getRawOriginal();
        $line = $entry->invoiceLines()->firstOrFail();
        foreach (['svc:billing:replay', 'svc:billing:rehearse-generation'] as $command) {
            $arguments = ['--workspace' => $this->workspace->public_id, '--format' => 'json'];
            if ($command === 'svc:billing:rehearse-generation') {
                $arguments['--show'] = true;
            }
            $exit = Artisan::call($command, $arguments);
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $exit, json_encode($report));
            if ($command === 'svc:billing:replay') {
                $this->assertSame(0, $report['summary']['unexplained']);
            }
            $this->assertSame($before, $invoice->fresh()->getRawOriginal());
            $this->assertSame($line->id, $entry->invoiceLines()->firstOrFail()->id);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function ledgerCadences(): array
    {
        return ['monthly' => ['monthly', false], 'quarterly with interims' => ['quarterly', true]];
    }

    #[DataProvider('ledgerCadences')]
    public function test_post_termination_work_is_settled_explicitly_without_rewriting_the_final_invoice(string $cadence, bool $interims): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 12:00:00'));
        $this->agreement->update(['status' => 'terminated', 'ends_on' => '2026-05-31', 'billing_cadence' => $cadence, 'bill_overage_interim' => $interims]);
        $this->entry(60, '2026-05-30');
        $ordinary = $this->entry(60, '2026-06-01');
        $final = app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $this->agreement, Carbon::parse($cadence === 'monthly' ? '2026-06-01' : '2026-07-01'));
        app(InvoiceLifecycleService::class)->issue($final, $this->workspace);
        $this->assertFalse($ordinary->invoiceLines()->exists(), 'Automatic termination collection stops at the end.');
        $this->assertSame([$ordinary->id], array_column($final->fresh()?->deferredWorkSnapshot()?->entries ?? [], 'id'), 'The final document discloses the work still awaiting explicit settlement.');
        $issuedAmount = $final->fresh()?->total_amount;
        $issuedLines = $final->lines()->orderBy('id')->get()->toArray();

        $flat = $this->entry(60, '2026-06-12', [
            'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 8000, 'subcontractor_cost_currency' => 'USD',
        ]);
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 2]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $this->assertSame([$ordinary->public_id, $flat->public_id], $preview->toArray()['entry_ids']);
        $this->assertSame(18000, $preview->selection->total());
        $settlement = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        $this->assertSame(InvoiceKind::DeferredBuyDown->value, $settlement->invoice_kind);
        $this->assertSame('draft', $settlement->status);
        $this->assertSame(18000, $settlement->total_amount);
        app(InvoiceLifecycleService::class)->issue($settlement, $this->workspace);

        $reader = app(DeferredBacklogReader::class);
        $this->assertSame(0, $reader->forAgreement($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'))->minutes);
        $this->assertSame('2026-05-31', $reader->segmentEnd($this->workspace, $this->agreement, CarbonImmutable::parse('2026-06-15'))->toDateString());
        $this->assertSame($issuedAmount, $final->fresh()?->total_amount);
        $this->assertSame($issuedLines, $final->lines()->orderBy('id')->get()->toArray());

        // A new deferral after the first settlement still has a way off the shelf.
        $later = $this->entry(30, '2026-06-15');
        $next = $billing->preview($this->workspace, $this->agreement, DeferredBuyDownRequest::from(['hours' => 0.5]));
        $this->assertSame([$later->public_id], $next->toArray()['entry_ids']);
        $this->assertSame(5000, $next->selection->total());
    }

    public function test_a_successor_still_owns_work_after_its_start_when_buying_down_an_ended_agreement(): void
    {
        $this->agreement->update(['status' => 'terminated', 'ends_on' => '2026-05-31']);
        $gap = $this->entry(60, '2026-06-01');
        $successorsWork = $this->entry(60, '2026-06-10');
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic successor', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-06-10',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 20000,
        ]);
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 2]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $this->assertSame([$gap->public_id], $preview->toArray()['entry_ids']);
        $this->assertSame(10000, $preview->selection->total());
        $settlement = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        app(InvoiceLifecycleService::class)->issue($settlement, $this->workspace);
        $this->assertFalse($successorsWork->invoiceLines()->exists());
    }

    public function test_post_end_settlement_stays_in_its_workspace_client_and_project_scope(): void
    {
        $this->agreement->update(['status' => 'terminated', 'ends_on' => '2026-05-31', 'client_project_id' => $this->project->id]);
        $own = $this->entry(60, '2026-06-01');
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic other project',
        ]);
        $otherProjectWork = $this->entry(60, '2026-06-01', ['client_project_id' => $otherProject->id]);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client']);
        $otherCompanyProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other client project',
        ]);
        $otherCompanyWork = $this->entry(60, '2026-06-01', ['client_company_id' => $otherCompany->id, 'client_project_id' => $otherCompanyProject->id]);
        $elsewhere = Workspace::query()->create(['name' => 'Synthetic elsewhere', 'slug' => 'synthetic-elsewhere']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $elsewhere->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign-client']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $elsewhere->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign project']);
        $foreignWork = $this->entry(60, '2026-06-01', [
            'workspace_id' => $elsewhere->id, 'client_company_id' => $foreignCompany->id, 'client_project_id' => $foreignProject->id,
        ]);

        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 4]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        $this->assertSame([$own->public_id], $preview->toArray()['entry_ids']);
        $this->assertSame(10000, $preview->selection->total());
        $settlement = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
        app(InvoiceLifecycleService::class)->issue($settlement, $this->workspace);
        foreach ([$otherProjectWork, $otherCompanyWork, $foreignWork] as $unrelated) {
            $this->assertFalse($unrelated->invoiceLines()->exists());
        }
    }

    #[DataProvider('ledgerCadences')]
    public function test_buying_twenty_hours_of_a_forty_hour_shelf_leaves_the_next_cadence_identical_to_work_that_never_existed(string $cadence, bool $interims): void
    {
        $this->agreement->update(['billing_cadence' => $cadence, 'bill_overage_interim' => $interims, 'rollover_months' => 0]);
        $first = $this->entry(600, '2026-01-01');
        $second = $this->entry(600, '2026-01-02');
        $this->entry(600, '2026-02-01');
        $this->entry(600, '2026-03-01');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 20]);
        DB::beginTransaction();
        try {
            $preview = $billing->preview($this->workspace, $this->agreement, $request);
            $billing->create($this->workspace, $this->agreement, $request, $preview->version);
            $withBuyDown = $this->cadenceSnapshot();
        } finally {
            DB::rollBack();
        }
        $first->delete();
        $second->delete();
        $withoutWork = $this->cadenceSnapshot();
        $this->assertSame($withoutWork, $withBuyDown);
    }

    /** @return array<string, array{bool}> */
    public static function reservationOrder(): array
    {
        return ['buy-down reserves first' => [true], 'regeneration reserves first' => [false]];
    }

    #[DataProvider('reservationOrder')]
    public function test_buydown_and_draft_regeneration_cannot_claim_the_same_entry_when_interleaved(bool $buyFirst): void
    {
        $engine = app(ClientInvoicingService::class);
        $engine->generateInvoice($this->company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), $this->agreement);
        $entry = $this->entry(60, '2026-05-10');
        $billing = app(DeferredBuyDownAction::class);
        $request = DeferredBuyDownRequest::from(['hours' => 1]);
        $preview = $billing->preview($this->workspace, $this->agreement, $request);
        if ($buyFirst) {
            $invoice = $billing->create($this->workspace, $this->agreement, $request, $preview->version);
            $engine->generateInvoice($this->company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), $this->agreement);
            $this->assertSame($invoice->id, $entry->invoiceLines()->firstOrFail()->client_invoice_id);
        } else {
            $engine->generateInvoice($this->company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), $this->agreement);
            try {
                $billing->create($this->workspace, $this->agreement, $request, $preview->version);
                $this->fail('Regeneration changed the shelf; stale buy-down must refuse.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertSame(1, $entry->invoiceLines()->count());
    }

    /** @return array<string, mixed> */
    private function cadenceSnapshot(): array
    {
        $invoice = app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $this->agreement, Carbon::parse('2026-07-01'));
        $attributes = $invoice->getRawOriginal();
        foreach (['id', 'public_id', 'created_at', 'updated_at'] as $identity) {
            unset($attributes[$identity]);
        }

        return ['invoice' => $attributes, 'lines' => $invoice->lines()->orderBy('sort_order')->get()->map(fn ($line): array => $line->only([
            'type', 'description', 'quantity', 'unit_amount', 'total_amount', 'tax_amount', 'client_project_id',
        ]) + ['line_date' => $line->line_date?->toDateString()])->all()];
    }

    /** @param array<string, mixed> $overrides */
    private function entry(int $minutes, string $date, array $overrides = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($overrides + ['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->owner->id, 'worked_on' => $date, 'minutes' => $minutes,
            'description' => 'Synthetic deferred work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => true,
            'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }
}
