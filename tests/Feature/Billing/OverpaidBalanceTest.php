<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An overpaid invoice does not owe a negative amount.
 *
 * `client_invoices.balance_amount` is an unsigned column, so writing
 * `total - paid` when the client has paid more than the invoice asks for is
 * rejected outright by MySQL - `SQLSTATE[22003]: Out of range value for column
 * 'balance_amount'` - and stored without complaint by SQLite. The suite runs on
 * SQLite, so nothing here failed; the replay against real data on MariaDB is
 * what surfaced it, as a generator that silently produced empty invoices.
 *
 * Overpayment is a supported state - the excess becomes credit - so the
 * subtraction is floored rather than the column widened, and the floor lives in
 * one place because three callers computed this and only one of them floored it.
 */
final class OverpaidBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paying_more_than_the_invoice_asks_leaves_nothing_owed(): void
    {
        $invoice = $this->invoice(total: 10000, paid: 15000);

        $invoice->recalculateTotals();

        $this->assertSame(10000, (int) $invoice->refresh()->total_amount);
        $this->assertSame(0, (int) $invoice->balance_amount, 'A negative balance cannot even be stored');
    }

    public function test_reducing_a_paid_invoices_lines_below_what_was_paid_still_stores(): void
    {
        $invoice = $this->invoice(total: 50000, paid: 50000);

        // The generator rewrites a draft's lines; if the new total is lower than
        // what has already been paid, the balance goes negative unless floored.
        $invoice->lines()->delete();
        $invoice->recalculateTotals();

        $this->assertSame(0, (int) $invoice->refresh()->total_amount);
        $this->assertSame(0, (int) $invoice->balance_amount);
    }

    public function test_an_ordinary_part_payment_still_leaves_the_remainder_owed(): void
    {
        $invoice = $this->invoice(total: 10000, paid: 4000);

        $invoice->recalculateTotals();

        $this->assertSame(6000, (int) $invoice->refresh()->balance_amount);
    }

    /**
     * The standing guard. Three callers derived this independently and one of
     * them was wrong for the whole life of the port.
     */
    public function test_nothing_computes_the_balance_by_hand(): void
    {
        $offenders = [];
        $roots = ['app/Models', 'app/Services', 'app/Http', 'app/Console'];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root)));
            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (explode("\n", $contents) as $number => $line) {
                    if (preg_match('/[\'"]balance_amount[\'"]\s*=>\s*.*\$\w*[Tt]otal\w*\s*-/', $line) === 1) {
                        $offenders[] = sprintf(
                            '%s:%d  %s',
                            str_replace(base_path().'/', '', $file->getPathname()),
                            $number + 1,
                            trim($line),
                        );
                    }
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These lines subtract a payment from a total to get the balance, instead of asking the model.\n\n%s\n\n".
            'Use ClientInvoice::balanceOwed(); an unsigned column cannot hold what the plain subtraction produces.',
            implode("\n", $offenders),
        ));
    }

    private function invoice(int $total, int $paid): ClientInvoice
    {
        $workspace = Workspace::query()->create(['name' => 'Overpaid', 'slug' => 'overpaid']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Overpaid Client', 'slug' => 'overpaid-client',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => 'SVC-00001',
            'currency' => 'USD',
            'status' => 'partially_paid',
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'retainer',
            'description' => 'Retainer',
            'quantity' => '1.0000',
            'unit_amount' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'sort_order' => 0,
        ]);
        $invoice->forceFill(['paid_amount' => $paid])->save();

        return $invoice->refresh();
    }
}
