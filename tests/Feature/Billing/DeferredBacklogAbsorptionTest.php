<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deferred work waits for spare capacity in the month being reconciled.
 *
 * The invoice for month P reconciles P-1's work against P-1's pool and sells
 * P's retainer in advance. Deferred work used to be offered both: whatever P-1
 * left *and* the whole of P. Taking P's retainer booked the backlog as P-1
 * overage, which the ledger carries into P as debt, so P's own work spilled
 * into P+1 and the minimum-availability rule billed catch-up hours the backlog
 * had caused - the two things deferral exists to prevent.
 */
final class DeferredBacklogAbsorptionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $user;

    private ClientInvoicingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Absorption', 'slug' => 'absorption']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Delta Freight', 'slug' => 'delta-freight',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Freight',
        ]);
        $this->user = User::factory()->create();
        $this->service = app(ClientInvoicingService::class);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 1,
        ]);
    }

    public function test_the_backlog_does_not_borrow_the_retainer_being_sold(): void
    {
        $this->entry('2026-01-12', 600);
        $backlog = $this->entry('2026-01-20', 570, deferred: true);
        $this->entry('2026-02-12', 600);

        // January's pool is spent, so the backlog waits - visibly.
        $february = $this->generate('2026-01');
        $this->assertNull($this->deferredLine($february));
        $this->assertSame([$backlog->id], array_column($this->service->lastDeferredSkipped(), 'id'));

        $march = $this->generate('2026-02');

        // February's work is February's, whole, and nothing is billed at rate.
        $descriptions = $march->lines->pluck('description')->all();
        $this->assertContains('Work items applied to retainer (10:00 applied to February 2026 pool)', $descriptions);
        $this->assertSame(0, $march->lines->where('type', InvoiceLineType::AdditionalHours->value)->count());
        $this->assertSame(0.0, (float) $march->hours_billed_at_rate);
    }

    public function test_the_backlog_is_absorbed_whole_and_in_order_as_spare_capacity_appears(): void
    {
        $this->entry('2026-01-12', 180);
        $first = $this->entry('2026-01-20', 240, deferred: true);
        $second = $this->entry('2026-01-21', 300, deferred: true);

        // January leaves 7 hours: the 4-hour entry fits, the 5-hour one does
        // not and is not split to make it fit.
        $february = $this->generate('2026-01');
        $this->assertSame([$first->id], $this->deferredLine($february)?->timeEntries()->pluck('client_time_entries.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$second->id], array_column($this->service->lastDeferredSkipped(), 'id'));

        // A quiet February has its own 10 hours plus January's 3 left.
        $march = $this->generate('2026-02');
        $line = $this->deferredLine($march);
        $this->assertSame([$second->id], $line?->timeEntries()->pluck('client_time_entries.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(300, (int) $second->fresh()?->minutes);
        $this->assertSame(0, (int) $line?->total_amount);
        $this->assertSame(0, $march->lines->where('type', InvoiceLineType::AdditionalHours->value)->count());
    }

    private function generate(string $workMonth): ClientInvoice
    {
        $start = Carbon::parse($workMonth.'-01');

        return $this->service->generateInvoice(
            $this->company, $start, $start->copy()->endOfMonth()->startOfDay(), $this->agreement,
        );
    }

    private function deferredLine(ClientInvoice $invoice): ?ClientInvoiceLine
    {
        return $invoice->lines->first(
            fn ($line): bool => str_starts_with((string) $line->description, 'Deferred work items applied to retainer'),
        );
    }

    private function entry(string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'billing_rate_amount' => 15000,
            'currency' => 'USD',
        ]);
    }
}
