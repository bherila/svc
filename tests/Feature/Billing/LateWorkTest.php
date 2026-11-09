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
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\LateWork;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Work that arrives after the invoice for its own period was charged rolls
 * forward: onto the next draft when it is priced alone, into deferred work when
 * it draws on a retainer.
 */
final class LateWorkTest extends TestCase
{
    use RefreshDatabase;

    private const int RATE = 10000;

    private const array HOURLY = ['retainer_minutes' => 0, 'retainer_amount' => 0];

    private const array HOURLY_QUARTERLY = [
        'billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 0, 'retainer_amount' => 0,
        'period_retainer_minutes' => 0, 'period_retainer_amount' => 0,
    ];

    private const array RETAINER = ['retainer_minutes' => 600, 'retainer_amount' => 60000];

    private const array RETAINER_QUARTERLY = [
        'billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'retainer_amount' => 60000,
    ];

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 12:00:00');
        CarbonImmutable::setTestNow('2027-02-01 12:00:00');
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic late', 'slug' => 'synthetic-late']);
        $this->owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic late client', 'slug' => 'synthetic-late-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic late project']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string}> */
    public static function hourlyCadences(): iterable
    {
        yield 'monthly' => [self::HOURLY, '2026-02-01', '2026-03-01', '2026-02-10'];
        yield 'quarterly' => [self::HOURLY_QUARTERLY, '2026-04-01', '2026-07-01', '2026-04-10'];
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('hourlyCadences')]
    public function test_an_hour_with_no_retainer_goes_on_the_next_draft_once(array $terms, string $first, string $next, string $nextWork): void
    {
        $agreement = $this->agreement($terms);
        $this->hours('2026-01-10', 60);
        $this->issue($this->generate($agreement, $first));

        $late = $this->hours('2026-01-15', 30);
        $this->hours($nextWork, 60);
        $draft = $this->generate($agreement, $next);

        $this->assertSame((int) round(90 * self::RATE / 60), (int) $draft->total_amount);
        $this->assertSame([$draft->id], $this->invoicesCarrying($late));

        $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft);
        $this->assertNotNull($rebuilt);
        $this->assertSame((int) round(90 * self::RATE / 60), (int) $rebuilt->total_amount, 'Regenerating must not charge it twice.');
        $this->assertSame([$rebuilt->id], $this->invoicesCarrying($late));
        $this->assertFalse((bool) $late->fresh()?->is_deferred);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string}> */
    public static function retainerCadences(): iterable
    {
        yield 'monthly' => [self::RETAINER, '2026-02-01', '2026-03-01', '2026-02-10'];
        yield 'quarterly' => [self::RETAINER_QUARTERLY, '2026-04-01', '2026-07-01', '2026-04-10'];
    }

    /**
     * The late hour overflows its own month's pool, so if the ledger still read
     * it there the next draft would collect it as overage. As deferred work it
     * draws on that draft's free capacity, at no further charge.
     *
     * @param  array<string, mixed>  $terms
     */
    #[DataProvider('retainerCadences')]
    public function test_a_retainer_hour_becomes_deferred_work_and_draws_on_later_free_capacity(array $terms, string $first, string $next, string $nextWork): void
    {
        $agreement = $this->agreement($terms);
        $this->hours('2026-01-10', $this->pool($terms) - 10);
        $this->issue($this->generate($agreement, $first));

        $late = $this->hours('2026-01-15', 180);
        $version = (int) $late->fresh()?->lock_version;
        $this->hours($nextWork, 60);
        $draft = $this->generate($agreement, $next);

        $fresh = $late->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue((bool) $fresh->is_deferred);
        $this->assertSame($version + 1, (int) $fresh->lock_version);
        $this->assertSame([$draft->id], $this->invoicesCarrying($late));
        $this->assertSame($this->retainerCharge($terms), (int) $draft->total_amount, 'Free capacity covers it, so nothing more is charged.');
    }

    /**
     * With no capacity free the hour waits in the backlog, and the draft still
     * charges nothing for it: charging it as overage would bill it twice once the
     * backlog is settled.
     *
     * @param  array<string, mixed>  $terms
     */
    #[DataProvider('retainerCadences')]
    public function test_a_retainer_hour_with_no_capacity_free_waits_without_being_charged(array $terms, string $first, string $next, string $nextWork): void
    {
        $agreement = $this->agreement($terms);
        $this->hours('2026-01-10', $this->pool($terms) - 10);
        $this->issue($this->generate($agreement, $first));

        $late = $this->hours('2026-01-15', 180);
        $this->hours($nextWork, $this->pool($terms));
        $draft = $this->generate($agreement, $next);

        $this->assertTrue((bool) $late->fresh()?->is_deferred);
        $this->assertSame([], $this->invoicesCarrying($late));
        $this->assertSame(1, ClientTimeEntry::query()->whereKey($late->id)->awaitingDeferredBilling()->count());
        $this->assertSame($this->retainerCharge($terms), (int) $draft->total_amount);
    }

    /** A flat-hourly hour is priced alone on any agreement, a retainer's included. */
    public function test_a_flat_hourly_hour_is_carried_at_its_own_price_and_not_deferred(): void
    {
        $agreement = $this->agreement(self::RETAINER);
        $this->hours('2026-01-10', 60);
        $this->issue($this->generate($agreement, '2026-02-01'));

        $late = $this->hours('2026-01-15', 60, [
            'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 8000, 'subcontractor_cost_currency' => 'USD',
        ]);
        $draft = $this->generate($agreement, '2026-03-01');

        $this->assertSame([$draft->id], $this->invoicesCarrying($late));
        $this->assertFalse((bool) $late->fresh()?->is_deferred);

        $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft);
        $this->assertNotNull($rebuilt);
        $this->assertSame([$rebuilt->id], $this->invoicesCarrying($late));
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('everyShape')]
    public function test_a_period_only_drafted_makes_nothing_late(array $terms): void
    {
        $agreement = $this->agreement($terms);
        $this->hours('2026-01-10', 60);
        $this->generate($agreement, '2026-02-01');

        $notLate = $this->hours('2026-01-15', 30);
        $this->hours('2026-02-10', 60);
        $this->generate($agreement, '2026-03-01');

        $this->assertSame([], $this->invoicesCarrying($notLate));
        $this->assertFalse((bool) $notLate->fresh()?->is_deferred);
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('everyShape')]
    public function test_a_voided_period_makes_nothing_late(array $terms): void
    {
        $agreement = $this->agreement($terms);
        $this->hours('2026-01-10', 60);
        $invoice = $this->generate($agreement, '2026-02-01');
        $this->issue($invoice);
        app(InvoiceLifecycleService::class)->void($invoice->fresh() ?? $invoice, $this->workspace);

        $notLate = $this->hours('2026-01-15', 30);
        $this->hours('2026-02-10', 60);
        $this->generate($agreement, '2026-03-01');

        $this->assertSame([], $this->invoicesCarrying($notLate));
        $this->assertFalse((bool) $notLate->fresh()?->is_deferred);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function everyShape(): iterable
    {
        yield 'no retainer' => [self::HOURLY];
        yield 'retainer' => [self::RETAINER];
    }

    /** @param array<string, mixed> $terms */
    #[DataProvider('everyShape')]
    public function test_a_disputed_hour_stays_held(array $terms): void
    {
        $agreement = $this->agreement($terms);
        $this->hours('2026-01-10', 60);
        $this->issue($this->generate($agreement, '2026-02-01'));

        $held = $this->hours('2026-01-15', 30, ['client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic question']);
        $this->hours('2026-02-10', 60);
        $this->generate($agreement, '2026-03-01');

        $this->assertSame([], $this->invoicesCarrying($held));
        $this->assertFalse((bool) $held->fresh()?->is_deferred, 'Only its answer decides how it waits.');
    }

    public function test_another_agreements_charged_period_makes_nothing_late(): void
    {
        $other = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic other project']);
        $charging = $this->agreement(self::HOURLY + ['client_project_id' => $this->project->id]);
        $waiting = $this->agreement(self::HOURLY + ['client_project_id' => $other->id]);
        $this->hours('2026-01-10', 60);
        $this->issue($this->generate($charging, '2026-02-01'));

        $notLate = $this->hours('2026-01-15', 30, ['client_project_id' => $other->id]);
        $this->hours('2026-02-10', 60, ['client_project_id' => $other->id]);
        $this->generate($waiting, '2026-03-01');

        $this->assertSame([], $this->invoicesCarrying($notLate));
    }

    public function test_another_workspaces_charged_period_makes_nothing_late(): void
    {
        $agreement = $this->agreement(self::HOURLY);
        $notLate = $this->hours('2026-01-15', 30);
        $this->hours('2026-02-10', 60);
        [$workspace, $company, $project] = [$this->workspace, $this->company, $this->project];

        $this->workspace = Workspace::query()->create(['name' => 'Synthetic elsewhere', 'slug' => 'synthetic-elsewhere']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic elsewhere client', 'slug' => 'synthetic-elsewhere-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic elsewhere project']);
        $elsewhere = $this->agreement(self::HOURLY);
        $this->hours('2026-01-10', 60);
        $this->issue($this->generate($elsewhere, '2026-02-01'));

        [$this->workspace, $this->company, $this->project] = [$workspace, $company, $project];
        $this->generate($agreement, '2026-03-01');

        $this->assertSame([], $this->invoicesCarrying($notLate));
        $this->assertSame([], app(LateWork::class)->ids(
            ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->unbilled(), $agreement, Carbon::parse('2026-03-01'),
        ));
    }

    /** @param array<string, mixed> $terms */
    private function agreement(array $terms): ClientAgreement
    {
        return ClientAgreement::query()->create($terms + [
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic agreement', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => self::RATE,
            'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function hours(string $day, int $minutes, array $overrides = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => $this->owner->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => self::RATE, 'currency' => 'USD',
        ]);
    }

    private function generate(ClientAgreement $agreement, string $period): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $agreement->fresh() ?? $agreement, Carbon::parse($period));
    }

    private function issue(ClientInvoice $draft): void
    {
        app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $this->workspace);
    }

    /** @return list<int> */
    private function invoicesCarrying(ClientTimeEntry $entry): array
    {
        return array_values(DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->where('allocation.client_time_entry_id', $entry->id)
            ->distinct()
            ->pluck('line.client_invoice_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all());
    }

    /**
     * The minutes one period's retainer holds.
     *
     * @param  array<string, mixed>  $terms
     */
    private function pool(array $terms): int
    {
        return ($terms['billing_cadence'] ?? 'monthly') === 'quarterly' ? 600 * 3 : 600;
    }

    /** @param array<string, mixed> $terms */
    private function retainerCharge(array $terms): int
    {
        return ($terms['billing_cadence'] ?? 'monthly') === 'quarterly' ? 60000 * 3 : 60000;
    }
}
