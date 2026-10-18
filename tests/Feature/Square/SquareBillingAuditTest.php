<?php

namespace Tests\Feature\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\SquareCustomerSnapshot;
use App\Models\SquareInvoiceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\Audit\SquareBillingAudit;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The phase-two Square billing-audit flags (#438) through
 * `billing_audit.stale_and_missing`: what each flag reads from svc's mirror,
 * that nothing reaches Square, and that no other workspace's rows - even with
 * the same Square ids - change the answer.
 */
final class SquareBillingAuditTest extends TestCase
{
    use CallsMcp;
    use FakesSquare;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read'];

    public function test_each_flag_is_reported_with_finished_urls_over_rest_and_mcp(): void
    {
        [$workspace, $owner, $company, $agreement] = $this->fixture();
        $linked = $this->invoice($workspace, $company, 'SYN-AUDIT-1');
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['payment_ids' => ['pay_audit_recorded', 'pay_audit_missing'], 'paid_amount' => 12_345]);
        $this->snapshot($workspace, 'inv_audit_2', 2, '2030-02-15', 'PAID', ['payment_ids' => ['pay_audit_unlinked'], 'paid_amount' => 12_345]);
        $this->snapshot($workspace, 'inv_audit_3', 3, '2030-03-15', 'UNPAID', ['total_amount' => 15_000]);
        $this->snapshot($workspace, 'inv_audit_4', 4, '2030-04-15', 'SCHEDULED', ['total_amount' => 15_000]);
        $this->link($workspace, $linked, 'inv_audit_1');
        $this->payment($workspace, $linked, 'pay_audit_recorded');
        $this->snapshot($workspace, 'inv_audit_s1', null, '2030-02-01', 'UNPAID', ['series_key' => null, 'invoice_number' => '000500', 'square_customer_id' => 'cust_audit_stranger', 'recipient_name' => 'Synthetic Stranger', 'total_amount' => 7_000]);
        $this->actingAsMcp($owner, self::SCOPES);

        $rest = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/billing-audits/stale-and-missing')->assertOk()->json();

        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($rest, AgentApiResponseSchemaCatalog::forOperation('billing_audit.stale_and_missing')));
        $audit = $rest['data']['square_billing_audit'];
        $agreementUrl = route('clients.agreement', [$workspace, $company->public_id, $agreement->public_id]);
        $dashboard = static fn (string $id): string => 'https://squareupsandbox.com/dashboard/invoices/'.$id;
        $this->assertTrue($audit['complete']);
        $this->assertSame(['count' => 2, 'periods' => [
            ['agreement_id' => $agreement->public_id, 'agreement_url' => $agreementUrl, 'series_key' => '000123', 'square_invoice_id' => 'inv_audit_2', 'invoice_number' => '000123-R-0002', 'status' => 'PAID', 'due_on' => '2030-02-15', 'currency' => 'USD', 'total_amount' => 12_345, 'dashboard_url' => $dashboard('inv_audit_2')],
            ['agreement_id' => $agreement->public_id, 'agreement_url' => $agreementUrl, 'series_key' => '000123', 'square_invoice_id' => 'inv_audit_3', 'invoice_number' => '000123-R-0003', 'status' => 'UNPAID', 'due_on' => '2030-03-15', 'currency' => 'USD', 'total_amount' => 15_000, 'dashboard_url' => $dashboard('inv_audit_3')],
        ], 'truncated' => false], $audit['unmirrored_series_periods']);
        $this->assertSame(['count' => 2, 'payments' => [
            ['payment_id' => 'pay_audit_missing', 'reason' => 'not_recorded', 'square_invoice_id' => 'inv_audit_1', 'invoice_number' => '000123-R-0001', 'invoice_id' => $linked->public_id, 'invoice_url' => route('clients.invoice', [$workspace, $company->public_id, $linked->public_id]), 'dashboard_url' => $dashboard('inv_audit_1')],
            ['payment_id' => 'pay_audit_unlinked', 'reason' => 'invoice_not_linked', 'square_invoice_id' => 'inv_audit_2', 'invoice_number' => '000123-R-0002', 'invoice_id' => null, 'invoice_url' => null, 'dashboard_url' => $dashboard('inv_audit_2')],
        ], 'truncated' => false], $audit['unmatched_payments']);
        // The series' customer is the client's confirmed customer, so only the stranger is unlinked.
        $this->assertSame(['count' => 1, 'customers' => [
            ['customer_id' => 'cust_audit_stranger', 'recipient_name' => 'Synthetic Stranger', 'open_invoice_count' => 1, 'currency' => 'USD', 'open_amount' => 7_000, 'explore_url' => route('square.explore', [$workspace, 'customer_id' => 'cust_audit_stranger'])],
        ], 'truncated' => false], $audit['unlinked_customer_balances']);
        $this->assertSame(['count' => 1, 'agreements' => [
            ['agreement_id' => $agreement->public_id, 'agreement_url' => $agreementUrl, 'series_key' => '000123', 'codes' => ['amount'], 'agreement_cadence' => 'monthly', 'series_cadence' => 'monthly', 'agreement_currency' => 'USD', 'series_currency' => 'USD', 'agreement_amount' => 12_345, 'series_amount' => 15_000, 'explore_url' => route('square.explore', [$workspace, 'series_key' => '000123'])],
        ], 'truncated' => false], $audit['series_drift']);
        // The stranger's customer is the same set square.customers.unlinked reports with an open balance.
        $unlinked = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/square/customers/unlinked?with_open_balance=true')->assertOk();
        $this->assertSame(['cust_audit_stranger'], array_column($unlinked->json('data'), 'customer_id'));

        $mcp = $this->callTool($this->initialize(), 'billing_audit.stale_and_missing', ['workspace_id' => $workspace->public_id]);
        $this->assertSame($rest, $mcp['result']['structuredContent']);
        Http::assertNothingSent();
    }

    public function test_another_workspaces_rows_with_the_same_square_ids_change_nothing(): void
    {
        [$workspace, , $company] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['payment_ids' => ['pay_audit_shared'], 'paid_amount' => 12_345]);
        $this->snapshot($workspace, 'inv_audit_s1', null, '2030-02-01', 'UNPAID', ['series_key' => null, 'invoice_number' => '000500', 'square_customer_id' => 'cust_audit_other_mapped', 'total_amount' => 7_000]);
        $before = app(SquareBillingAudit::class)->report($workspace);

        // The same series, customers, Square invoice and payment in another
        // workspace: there the period is linked, the payment recorded, the
        // customer mapped and retired, and the agreement differs.
        $other = Workspace::query()->create(['name' => 'Synthetic audit other', 'slug' => 'synthetic-audit-other']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-other-company']);
        $otherAgreement = ClientAgreement::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'title' => 'Synthetic other', 'status' => 'active', 'starts_on' => '2029-01-01', 'billing_cadence' => 'annual', 'currency' => 'USD', 'retainer_amount' => 1]);
        $otherAgreement->forceFill(['square_series_key' => '000999'])->save();
        $otherInvoice = $this->invoice($other, $otherCompany, 'SYN-AUDIT-OTHER');
        $this->snapshot($other, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['payment_ids' => ['pay_audit_shared']]);
        $this->link($other, $otherInvoice, 'inv_audit_1');
        $this->payment($other, $otherInvoice, 'pay_audit_shared');
        CompanySquareCustomer::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'square_customer_id' => 'cust_audit_other_mapped', 'active_company_slot' => $otherCompany->id, 'active_customer_slot' => 'cust_audit_other_mapped', 'confirmed_at' => now()]);
        $retired = SquareCustomerSnapshot::query()->create(['workspace_id' => $other->id, 'square_customer_id' => 'cust_audit_other_mapped', 'fingerprint' => str_repeat('b', 64), 'synced_at' => now(), 'last_seen_at' => now()]);
        $retired->delete();
        // A legacy link stamped to this workspace but pointing at the other
        // workspace's invoice is not a link here.
        $this->writingLegacyCrossTenantRows(fn () => DB::table('invoice_square_links')->insert([
            'public_id' => '00000000-0000-4000-8000-0000000d0001', 'workspace_id' => $workspace->id, 'client_invoice_id' => $otherInvoice->id,
            'square_invoice_id' => 'inv_audit_1', 'square_invoice_number' => '000123-R-0001', 'series_key' => '000123', 'currency' => 'USD', 'match_method' => 'manual',
            'active_invoice_slot' => $otherInvoice->id, 'active_square_invoice_slot' => 'inv_audit_1', 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $after = app(SquareBillingAudit::class)->report($workspace);

        $this->assertSame($before, $after);
        $this->assertSame(1, $after['unmirrored_series_periods']['count']);
        $this->assertSame(['pay_audit_shared'], array_column($after['unmatched_payments']['payments'], 'payment_id'));
        $this->assertSame(['cust_audit_other_mapped'], array_column($after['unlinked_customer_balances']['customers'], 'customer_id'));
        // The other workspace's annual agreement on series 000999 is not this workspace's to compare.
        $this->assertSame(0, $after['series_drift']['count']);
        // Square serves one workspace: the other reports nothing at all.
        $this->assertSame(SquareBillingAudit::empty(), app(SquareBillingAudit::class)->report($other));
        $this->assertSame($company->workspace_id, $workspace->id);
    }

    public function test_nothing_is_reported_without_a_square_connection(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_2', 2, '2030-02-15', 'PAID', ['payment_ids' => ['pay_audit_unlinked']]);
        config(['services.square.access_token' => null]);

        $this->assertSame(SquareBillingAudit::empty(), app(SquareBillingAudit::class)->report($workspace));
    }

    public function test_the_load_is_bounded_and_says_when_it_was(): void
    {
        [$workspace] = $this->fixture();
        $rows = [];
        for ($index = 1; $index <= 5001; $index++) {
            $rows[] = [
                'workspace_id' => $workspace->id, 'square_invoice_id' => 'inv_bound_'.$index, 'invoice_number' => sprintf('000123-R-%04d', $index % 10_000), 'series_key' => '000123',
                'series_occurrence' => $index, 'status' => 'CANCELED', 'currency' => 'USD', 'total_amount' => 12_345, 'autopay' => false, 'payment_requests' => '[]',
                'fingerprint' => str_repeat('a', 64), 'synced_at' => '2030-03-01 00:00:00', 'last_seen_at' => '2030-03-01 00:00:00',
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('square_invoice_snapshots')->insert($chunk);
        }

        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
    }

    public function test_a_full_reach_never_hides_a_billed_in_square_series(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['total_amount' => 15_000]);
        $this->snapshot($workspace, 'inv_audit_2', 2, '2030-02-15', 'PAID', ['total_amount' => 15_000]);
        $rows = [];
        for ($index = 1; $index <= 5001; $index++) {
            $rows[] = [
                'workspace_id' => $workspace->id, 'square_invoice_id' => 'inv_open_'.$index, 'invoice_number' => sprintf('%06d', $index), 'square_customer_id' => 'cust_open_'.$index,
                'status' => 'UNPAID', 'currency' => 'USD', 'total_amount' => 100, 'autopay' => false, 'payment_requests' => '[]',
                'fingerprint' => str_repeat('a', 64), 'synced_at' => '2030-03-01 00:00:00', 'last_seen_at' => '2030-03-01 00:00:00',
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('square_invoice_snapshots')->insert($chunk);
        }

        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertFalse($report['complete']);
        $this->assertSame([['amount']], array_column($report['series_drift']['agreements'], 'codes'));
        $this->assertSame(5000, $report['unlinked_customer_balances']['count']);
    }

    public function test_payment_ids_read_by_older_tender_rules_are_re_read_and_leave_the_audit_incomplete_until_then(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345, 'square_order_id' => 'order_audit_1', 'payment_ids' => [], 'payment_ids_basis' => 'PAID|12345']);
        $repository = app(SquareMirrorRepository::class);

        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
        $due = $repository->invoicesNeedingPaymentIds($workspace, 10);
        $this->assertSame([['square_invoice_id' => 'inv_audit_1', 'square_order_id' => 'order_audit_1', 'basis' => 'PAID|12345|t2']], $due);

        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_audit_1', $due[0]['basis'], ['pay_audit_tender']));
        $report = app(SquareBillingAudit::class)->report($workspace);
        $this->assertTrue($report['complete']);
        $this->assertSame(['pay_audit_tender'], array_column($report['unmatched_payments']['payments'], 'payment_id'));
        $this->assertSame([], $repository->invoicesNeedingPaymentIds($workspace, 10));
    }

    public function test_an_imported_annual_series_mirrors_its_amount_once_per_cycle(): void
    {
        [$workspace, $owner] = $this->fixture();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
        foreach ([1 => '2029-03-01', 2 => '2030-03-01'] as $occurrence => $due) {
            $this->snapshot($workspace, 'inv_annual_'.$occurrence, $occurrence, $due, 'PAID', ['series_key' => '000777', 'invoice_number' => sprintf('000777-R-%04d', $occurrence), 'square_customer_id' => 'cust_audit_annual', 'total_amount' => 120_000, 'paid_amount' => 120_000]);
        }
        $this->actingAsMcp($owner, [...self::SCOPES, 'billing:write']);

        $imported = $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/square/customers/cust_audit_annual/import', ['confirm' => true, 'company_name' => 'Synthetic annual client', 'series_key' => '000777'], ['Idempotency-Key' => 'synthetic-annual-import'])->assertOk();

        $agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $imported->json('data.agreement_id'))->firstOrFail();
        $this->assertSame(['annual', null, 120_000, 1_200.0], [$agreement->billing_cadence, $agreement->retainer_amount, $agreement->period_retainer_amount, $agreement->periodRetainerFee()]);
        $this->assertNotContains('000777', array_column(app(SquareBillingAudit::class)->report($workspace)['series_drift']['agreements'], 'series_key'));
    }

    /** @return array{Workspace, User, ClientCompany, ClientAgreement} */
    private function fixture(): array
    {
        Date::setTestNow('2030-03-20 12:00:00');
        config(['app.url' => 'https://svc.example.test', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        Http::fake();
        $workspace = Workspace::query()->create(['name' => 'Synthetic audit workspace', 'slug' => 'synthetic-audit-workspace']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic audit company', 'slug' => 'synthetic-audit-company']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Square monthly', 'status' => 'active', 'starts_on' => '2030-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'retainer_amount' => 12_345]);
        $agreement->forceFill(['square_series_key' => '000123'])->save();
        CompanySquareCustomer::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'square_customer_id' => 'cust_audit_series', 'active_company_slot' => $company->id, 'active_customer_slot' => 'cust_audit_series', 'confirmed_at' => now()]);
        $this->connectSquare($workspace);

        return [$workspace, $owner, $company, $agreement];
    }

    /** @param  array<string, mixed>  $overrides */
    private function snapshot(Workspace $workspace, string $id, ?int $occurrence, string $due, string $status, array $overrides = []): void
    {
        SquareInvoiceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'square_invoice_id' => $id,
            'invoice_number' => sprintf('000123-R-%04d', $occurrence ?? 0),
            'series_key' => '000123',
            'series_occurrence' => $occurrence,
            'square_customer_id' => 'cust_audit_series',
            'status' => $status,
            'currency' => 'USD',
            'total_amount' => 12_345,
            'paid_amount' => 0,
            'due_on' => $due,
            'autopay' => false,
            'payment_requests' => [],
            'fingerprint' => str_repeat('a', 64),
            'synced_at' => '2030-03-01 00:00:00',
            'last_seen_at' => '2030-03-01 00:00:00',
            ...$overrides,
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        return ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => $number, 'invoice_kind' => 'ad_hoc', 'status' => 'paid', 'currency' => 'USD', 'total_amount' => 12_345, 'balance_amount' => 0, 'is_visible_to_client' => true]);
    }

    private function link(Workspace $workspace, ClientInvoice $invoice, string $squareInvoiceId): void
    {
        InvoiceSquareLink::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'square_invoice_id' => $squareInvoiceId, 'square_invoice_number' => '000123-R-0001', 'series_key' => '000123', 'currency' => 'USD', 'match_method' => 'manual', 'active_invoice_slot' => $invoice->id, 'active_square_invoice_slot' => $squareInvoiceId, 'linked_at' => now()]);
    }

    private function payment(Workspace $workspace, ClientInvoice $invoice, string $paymentId): void
    {
        ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'amount' => 12_345, 'refunded_amount' => 0, 'currency' => 'USD', 'status' => 'succeeded', 'method' => 'square', 'provider' => 'square', 'provider_payment_identifier' => $paymentId, 'received_on' => '2030-01-15']);
    }
}
