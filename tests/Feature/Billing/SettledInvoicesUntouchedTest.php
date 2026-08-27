<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An invoice the client has been charged for never moves again.
 *
 * Four billing behaviours were deliberately corrected in this port, and each
 * changes how much a period costs. That is intended for work not yet billed and
 * unacceptable for work already billed: an issued or paid invoice is a
 * statement the client has seen and, in most cases, settled against. Recomputing
 * one silently would change what they owe after the fact.
 *
 * The generator's guard is `isImmutable()`, but that is a claim about one code
 * path. These tests make the claim about the row: run a full generation across
 * the settled invoice's own period and afterwards, then compare every column and
 * every line, not just the total.
 */
final class SettledInvoicesUntouchedTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Settled', 'slug' => 'settled']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Settled Client', 'slug' => 'settled-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Settled Project',
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * Every settled status, not just paid: issued has been sent, partially paid
     * has money against it, and void was a deliberate decision to charge
     * nothing. None may be rewritten.
     */
    public function test_no_settled_status_is_altered_by_a_later_generation(): void
    {
        foreach (InvoiceStatus::settled() as $status) {
            $this->refreshDatabaseForStatus();

            $agreement = $this->agreement();
            $this->entry('2024-01-10', 900);   // 15h against a 10h retainer
            $this->entry('2024-02-12', 300);
            $this->entry('2024-03-14', 240);

            $invoice = app(ClientInvoicingService::class)->generateInvoice(
                $this->company,
                Carbon::parse('2024-01-01'),
                Carbon::parse('2024-01-31'),
                $agreement,
            );
            $invoice->forceFill(['status' => $status])->save();

            $before = $this->rowAndLines($invoice->id);

            // Generate everything else the agreement would produce. This walks
            // the settled invoice's own cycle and every later one, and
            // recomputes the ledger across all of them.
            Carbon::setTestNow(Carbon::parse('2024-04-15'));
            try {
                app(ClientInvoicingService::class)->generateAllInvoices($this->company);
            } finally {
                Carbon::setTestNow();
            }

            $this->assertSame(
                $before,
                $this->rowAndLines($invoice->id),
                "A {$status} invoice was modified by a later generation run",
            );
        }
    }

    /**
     * The corrections change the ledger, and the ledger is what later invoices
     * are built from. A settled invoice's stored balances must stay as issued
     * even when today's arithmetic would compute them differently.
     */
    public function test_a_settled_invoices_stored_ledger_columns_survive_recomputation(): void
    {
        $agreement = $this->agreement(rolloverMonths: 3);
        $this->entry('2024-01-10', 120);

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
            $agreement,
        )->refresh();

        // Stamp balances that today's rules would not reproduce, then settle it.
        $invoice->forceFill([
            'status' => 'paid',
            'unused_hours_balance' => '8.0000',
            'negative_hours_balance' => '0.0000',
            'hours_worked' => '2.0000',
            'paid_amount' => (int) $invoice->total_amount,
        ])->save();

        $stamped = $this->rowAndLines($invoice->id);

        $this->entry('2024-02-20', 600);
        Carbon::setTestNow(Carbon::parse('2024-04-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($stamped, $this->rowAndLines($invoice->id));
    }

    /**
     * Time already billed on a settled invoice stays attached to it. Releasing
     * it would let a later invoice bill the same hours twice.
     */
    public function test_time_billed_on_a_settled_invoice_is_not_re_billed(): void
    {
        $agreement = $this->agreement();
        $this->entry('2024-01-10', 900);

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
            $agreement,
        );
        $invoice->forceFill(['status' => 'issued'])->save();

        $claimedBefore = DB::table('client_invoice_line_time_entries')->count();

        Carbon::setTestNow(Carbon::parse('2024-04-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }

        $stillClaimed = DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', DB::table('client_invoice_lines')->where('client_invoice_id', $invoice->id)->pluck('id'))
            ->count();

        $this->assertSame($claimedBefore, $stillClaimed, 'The settled invoice lost its claim on billed time');
    }

    /**
     * @return array{invoice: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function rowAndLines(int $invoiceId): array
    {
        $invoice = (array) DB::table('client_invoices')->where('id', $invoiceId)->first();
        unset($invoice['updated_at'], $invoice['lock_version']);

        $lines = DB::table('client_invoice_lines')
            ->where('client_invoice_id', $invoiceId)
            ->orderBy('id')
            ->get()
            ->map(static function (object $line): array {
                $row = (array) $line;
                unset($row['updated_at']);

                return $row;
            })
            ->all();

        return ['invoice' => $invoice, 'lines' => $lines];
    }

    private function refreshDatabaseForStatus(): void
    {
        DB::table('client_invoice_line_time_entries')->delete();
        DB::table('client_invoice_lines')->delete();
        DB::table('client_invoices')->delete();
        DB::table('client_time_entries')->delete();
        DB::table('client_agreements')->delete();
    }

    private function agreement(int $rolloverMonths = 0): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'rollover_months' => $rolloverMonths,
        ]);
    }

    private function entry(string $workedOn, int $minutes): ClientTimeEntry
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
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
