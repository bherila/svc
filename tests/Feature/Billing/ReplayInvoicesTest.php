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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The replay harness has to be trustworthy in two opposite directions: it must
 * notice when the engine no longer reproduces history, and it must never write
 * anything while finding out. A harness that silently passes is useless; one
 * that mutates production data to run is dangerous.
 */
final class ReplayInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Replay', 'slug' => 'replay']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Replay Client', 'slug' => 'replay-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Replay Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_requires_a_workspace(): void
    {
        $this->artisan('svc:billing:replay')->assertFailed();
        $this->artisan('svc:billing:replay', ['--workspace' => 'nope'])->assertFailed();
    }

    /**
     * Invoices the current engine produced are, by definition, reproducible by
     * the current engine. This is the control: if it fails, the harness is
     * comparing the wrong things.
     */
    public function test_invoices_the_engine_produced_replay_to_the_cent(): void
    {
        $this->generatedHistory();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('money identical')
            ->assertSuccessful();
    }

    /**
     * The harness must fail when history and the engine disagree. Editing a
     * stored total stands in for the engine having drifted.
     */
    public function test_it_fails_when_a_stored_invoice_no_longer_reproduces(): void
    {
        $invoice = $this->generatedHistory();

        // History says this invoice was worth 1.00 more than the engine now says.
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->assertFailed();
    }

    /**
     * The command claims money is exact to the cent. Comparing totals per type
     * cannot deliver that: a line repriced with a compensating quantity reaches
     * the same total, the same per-type total and the same line count, so every
     * aggregate agrees while the client was charged for something else.
     */
    public function test_a_repriced_line_is_not_reported_as_an_exact_reproduction(): void
    {
        $invoice = $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = $invoice->lines()->orderByDesc('total_amount')->firstOrFail();
        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Half the price, twice as many. Total unchanged, so nothing an
        // aggregate looks at moves.
        $line->forceFill([
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (float) $line->quantity * 2,
        ])->save();

        // tempnam() creates the file it names, so use that path rather than a
        // suffixed sibling - otherwise every run leaves the original behind.
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertFailed();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $verdicts = array_column($detail['comparisons'], 'verdict', 'invoice_number');

        // Every aggregate agrees on this invoice, so the old comparison called
        // it an exact reproduction. The individual lines are what disagree.
        $this->assertArrayHasKey((string) $invoice->invoice_number, $verdicts);
        // A repriced line is a money difference, not an arrangement: the client
        // was charged for something they were not charged for. It gates.
        $this->assertSame('money_differs', $verdicts[(string) $invoice->invoice_number]);
    }

    /**
     * The deliberate corrections say which line types a period should carry.
     * They say nothing about what a line of that type costs, so a repriced line
     * must not be waived by the correction that explains why its type moved.
     */
    public function test_a_repriced_line_is_not_waived_by_a_correction_that_covers_its_type(): void
    {
        $this->generatedHistory();

        // prior_month_retainer is one of the capacity-dependent types, so this
        // is the invoice a correction could otherwise excuse. Only the unit
        // price moves: the invoice total is untouched, so nothing above the
        // line level has anything to notice.
        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();
        $line->forceFill(['unit_amount' => (int) $line->unit_amount + 5000])->save();

        $invoice = $line->invoice()->firstOrFail();
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertFailed();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $rows = array_column($detail['comparisons'], null, 'invoice_number');
        $row = $rows[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        $this->assertSame(0, $row['money_delta']);
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * A line the engine no longer produces is a composition change, and
     * composition is what the four deliberate corrections exist to explain. It
     * must not be mistaken for a repricing, which is never explainable.
     */
    public function test_a_line_the_engine_no_longer_produces_is_not_read_as_a_repricing(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('type', 'prior_month_retainer')->firstOrFail();

        // History carried this charge twice; the engine produces it once. Every
        // amount is identical, so nothing about what the client pays moved.
        $duplicate = $line->replicate();
        $duplicate->public_id = (string) Str::uuid();
        $duplicate->sort_order = (int) $line->sort_order + 1;
        $duplicate->save();

        $invoice = $line->invoice()->firstOrFail();
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $rows = array_column($detail['comparisons'], null, 'invoice_number');
        $row = $rows[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        // Comparing prices as a multiset would call one-of-these-instead-of-two
        // a money difference, and no correction could ever explain it.
        $this->assertSame('composition_differs', $row['verdict']);
    }

    /**
     * The safety property. The command deletes and regenerates every invoice to
     * do its work, so the only thing standing between it and production data is
     * the unconditional rollback.
     */
    public function test_it_leaves_the_database_exactly_as_it_found_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $before = $this->fingerprint();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertFailed();

        $this->assertSame($before, $this->fingerprint(), 'The replay must not change a single row');
    }

    /**
     * An operator-typed invoice has no generator that would reproduce it, so
     * counting it as a divergence would make every real run fail.
     */
    public function test_ad_hoc_invoices_are_set_aside_rather_than_failed(): void
    {
        $this->generatedHistory();

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2023-01-01',
            'service_period_end' => '2023-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => (int) ClientInvoice::query()->where('invoice_number', 'SVC-ADHOC')->value('id'),
            'type' => 'additional_hours', 'description' => 'One-off', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('ad-hoc')
            ->assertSuccessful();
    }

    /**
     * The harness must not invent the divergences it reports.
     *
     * `OverpaymentCreditService` derives credit from payment rows measured
     * against `total_amount`. Blanking the totals for regeneration while
     * leaving the payments made every settled invoice in history look overpaid
     * by its full amount, and the regenerated invoices then drew on a credit
     * pool that had never existed.
     */
    public function test_a_paid_invoice_does_not_become_credit_for_the_invoice_replacing_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill([
            'status' => 'paid',
            'paid_amount' => $invoice->total_amount,
            'balance_amount' => 0,
        ])->save();

        DB::table('client_invoice_payments')->insert([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'public_id' => (string) Str::uuid(),
            'amount' => (int) $invoice->total_amount,
            'currency' => 'USD',
            'status' => 'succeeded',
            'received_on' => '2024-02-05',
            'method' => 'bank_transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->doesntExpectOutputToContain('credit')
            ->assertSuccessful();
    }

    /**
     * An ad-hoc invoice is excluded from the comparison and never regenerated,
     * so releasing its claims handed already-billed work to the cadence
     * generator and reported the second charge as a divergence.
     */
    public function test_an_ad_hoc_invoices_milestone_claim_survives_the_replay(): void
    {
        $this->generatedHistory();

        $adHoc = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC-2',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2024-01-01',
            'service_period_end' => '2024-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $adHoc->id,
            'type' => 'milestone', 'description' => 'Billed ad hoc', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertSuccessful();

        $this->assertDatabaseHas('client_invoice_lines', ['id' => $line->id]);
        $this->assertSame(50000, (int) $adHoc->refresh()->total_amount, 'An ad-hoc invoice is left alone entirely');
    }

    /**
     * A whole-database fingerprint, so the safety assertion cannot pass by
     * checking only the rows that happened to be remembered.
     */
    private function fingerprint(): string
    {
        $parts = [];

        foreach (['client_invoices', 'client_invoice_lines', 'client_invoice_line_time_entries', 'client_time_entries', 'client_tasks', 'workspace_invoice_counters'] as $table) {
            // Sorted here rather than in SQL because not every one of these
            // tables has an `id`: workspace_invoice_counters is keyed on
            // workspace_id alone. `orderBy('id')` looked fine on SQLite, which
            // reinterprets an unresolvable double-quoted identifier as a string
            // literal - so it ordered by the constant 'id', silently doing
            // nothing. MySQL raises 1054 instead. Sorting the encoded rows needs
            // no key at all and is stable on both.
            $rows = DB::table($table)->get()
                ->map(static fn (object $row): string => (string) json_encode($row))
                ->sort()
                ->values()
                ->all();
            $parts[] = $table.':'.md5((string) json_encode($rows));
        }

        return implode('|', $parts);
    }

    private function generatedHistory(): ClientInvoice
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 2,
        ]);

        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-02-14',
            'minutes' => 900,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        // The whole history, as the original system would have produced it -
        // one hand-made invoice would leave every later cycle looking like a
        // divergence the moment the replay walked past it.
        Carbon::setTestNow(Carbon::parse('2024-06-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }

        return ClientInvoice::query()->orderByDesc('id')->firstOrFail();
    }
}
