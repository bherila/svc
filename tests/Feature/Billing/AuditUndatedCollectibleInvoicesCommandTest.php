<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What the command prints, and what it must never print.
 *
 * `UndatedCollectibleInvoiceAuditorTest` covers the arithmetic. This covers what
 * only the command can get wrong: the `--format=json` shape, the notices, and
 * the promise that the output is safe to paste into a public issue.
 *
 * The don't-count-nulls-as-overdue notice is asserted deliberately. It is the
 * only line in any of these audits that exists to talk a reader *out* of a fix,
 * and a reader who reaches this command has usually reached it holding that fix.
 */
final class AuditUndatedCollectibleInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_format_it_cannot_produce(): void
    {
        $this->artisan('svc:billing:audit-undated-collectible-invoices', ['--format' => 'yaml'])
            ->expectsOutputToContain('The --format option must be text or json.')
            ->assertExitCode(2);
    }

    /**
     * The json shape, key by key and value by value.
     *
     * The fixture makes the fields differ - 4, 3, 2, 2, 1, 1 - so a mapping that
     * named the right keys against the wrong properties changes a number here.
     */
    public function test_the_json_contract_is_the_full_set_of_counts(): void
    {
        $this->exposed();

        Artisan::call('svc:billing:audit-undated-collectible-invoices', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'invoices',
            'collectible',
            'undated',
            'with_an_issue_date',
            'without_an_issue_date',
            'would_become_overdue_if_backfilled',
            'undated_balances',
            'would_become_overdue_balances',
        ], array_keys($payload['summary']));

        $this->assertSame([
            'invoices' => 4,
            'collectible' => 3,
            'undated' => 2,
            'with_an_issue_date' => 1,
            'without_an_issue_date' => 1,
            'would_become_overdue_if_backfilled' => 1,
            'undated_balances' => ['USD' => 7500],
            'would_become_overdue_balances' => ['USD' => 5000],
        ], $payload['summary']);
    }

    /**
     * It says nothing that identifies a client.
     *
     * The invoice number is the one that matters most here - #149's population
     * is real outstanding money, and an invoice number carries a client prefix.
     */
    public function test_neither_format_prints_anything_that_identifies_a_client(): void
    {
        $this->exposed();

        foreach (['text', 'json'] as $format) {
            Artisan::call('svc:billing:audit-undated-collectible-invoices', ['--format' => $format]);
            $output = Artisan::output();

            // The control: proves the output was captured, so the absences
            // below are absences from something rather than from an empty
            // string.
            $this->assertStringContainsString('1', $output);

            foreach ([
                'Meridian Tanneries',   // company name
                'meridian-tanneries',   // company slug
                'MERIDIAN-INV-0001',    // invoice number
                'meridian-workspace',   // workspace slug
            ] as $identifier) {
                $this->assertStringNotContainsString($identifier, $output, "$format output named $identifier");
            }
        }
    }

    public function test_it_reports_latent_when_every_collectible_invoice_is_dated(): void
    {
        $workspace = $this->workspace();
        $company = $this->company($workspace);
        $this->invoice($workspace, $company, 'MERIDIAN-INV-0001', [
            'status' => 'issued',
            'balance_amount' => 5000,
            'due_date' => '2026-03-01',
        ]);

        $output = $this->text();

        $this->assertStringContainsString('#149 is latent', $output);
        $this->assertStringNotContainsString('Do not count a null as overdue', $output);
    }

    /**
     * The warning fires, and it argues against the obvious fix.
     *
     * The `orWhereNull` line is the point of the whole command. #135 resolved
     * the identical SQL shape that way and was right to; repeating it here would
     * move invoices into a collections-adjacent report on no evidence. A reader
     * who takes the count and skips the reasoning does the wrong thing with it.
     */
    public function test_it_warns_and_argues_against_counting_nulls_as_overdue(): void
    {
        $this->exposed();

        $output = $this->text();

        $this->assertStringContainsString('collectible invoice(s) have no due date', $output);
        $this->assertStringContainsString('Do not count a null as overdue', $output);
        $this->assertStringContainsString('not self-evidently late', $output);
        $this->assertStringNotContainsString('#149 is latent', $output);
    }

    /**
     * The two halves of the population get their own advice.
     *
     * One is repairable exactly as the lifecycle would have dated it; the other
     * has no defensible date at all and is what a separate reporting bucket
     * exists for. Collapsing them would recommend a backfill for rows it cannot
     * reach.
     */
    public function test_the_repairable_and_undatable_halves_are_advised_separately(): void
    {
        $this->exposed();

        $output = $this->text();

        $this->assertStringContainsString('carry an issue date', $output);
        $this->assertStringContainsString('would land in overdue reporting immediately', $output);
        $this->assertStringContainsString('no issue date either', $output);
        $this->assertStringContainsString('no backfill can date them honestly', $output);
    }

    /**
     * The text output with its console line-wrapping flattened.
     *
     * The notices are several sentences long and the component wraps them to the
     * terminal, so a phrase asserted verbatim can straddle a newline and fail for
     * reasons that have nothing to do with what the command said.
     */
    private function text(): string
    {
        Artisan::call('svc:billing:audit-undated-collectible-invoices');

        return (string) preg_replace('/\s+/', ' ', Artisan::output());
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->create([
            'name' => 'Meridian Workspace',
            'slug' => 'meridian-workspace',
        ]);
    }

    private function company(Workspace $workspace): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Meridian Tanneries',
            'slug' => 'meridian-tanneries',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(Workspace $workspace, ClientCompany $company, string $number, array $overrides): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $invoice->forceFill(['due_date' => null, 'issue_date' => null, ...$overrides])->save();

        return $invoice;
    }

    /**
     * Four invoices making every reported figure differ: one undated and
     * datable, one undated and not, one collectible but dated, one draft.
     */
    private function exposed(): void
    {
        $this->travelTo('2026-08-31');

        $workspace = $this->workspace();
        $company = $this->company($workspace);

        $this->invoice($workspace, $company, 'MERIDIAN-INV-0001', [
            'status' => 'issued',
            'balance_amount' => 5000,
            'issue_date' => '2026-01-01',
        ]);
        $this->invoice($workspace, $company, 'MERIDIAN-INV-0002', [
            'status' => 'partially_paid',
            'balance_amount' => 2500,
        ]);
        $this->invoice($workspace, $company, 'MERIDIAN-INV-0003', [
            'status' => 'issued',
            'balance_amount' => 9000,
            'due_date' => '2026-03-01',
        ]);
        $this->invoice($workspace, $company, 'MERIDIAN-INV-0004', [
            'status' => 'draft',
            'balance_amount' => 7000,
        ]);
    }
}
