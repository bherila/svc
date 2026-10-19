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
use App\Models\SquareSyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\Audit\SquareBillingAudit;
use App\Services\Square\Audit\SquareBillingAuditRepository;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquarePaymentIdsRead;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
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
        // A bounded reach could cut a customer's open invoices short, so no balance is stated.
        $this->assertSame(0, $report['unlinked_customer_balances']['count']);
    }

    public function test_payment_ids_read_by_older_tender_rules_are_re_read_and_leave_the_audit_incomplete_until_then(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345, 'square_order_id' => 'order_audit_1', 'payment_ids' => [], 'payment_ids_basis' => 'PAID|12345']);
        $repository = app(SquareMirrorRepository::class);

        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
        $due = $repository->invoicesNeedingPaymentIds($workspace, 10);
        $this->assertSame([['square_invoice_id' => 'inv_audit_1', 'square_order_id' => 'order_audit_1', 'square_location_id' => 'LOCFIXTURE01', 'basis' => 'PAID|12345|t3']], array_map(static fn (array $lookup): array => array_diff_key($lookup, ['read' => true]), $due));
        $this->assertEquals(new SquarePaymentIdsRead('2030-03-01 00:00:00', 'PAID|12345', '[]'), $due[0]['read']);

        $this->assertFalse($repository->storePaymentIds($workspace, 'inv_audit_1', $due[0]['basis'], ['pay_audit_stale'], read: new SquarePaymentIdsRead('2030-02-28 00:00:00', 'PAID|12345', '[]')), 'An older lookup never overwrites a newer one.');
        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_audit_1', $due[0]['basis'], ['pay_audit_tender'], read: $due[0]['read']));
        // Within the same second: the ids changed, so the older read no longer holds.
        $this->assertFalse($repository->storePaymentIds($workspace, 'inv_audit_1', $due[0]['basis'], ['pay_audit_stale'], read: $due[0]['read']), 'Not even within the same second.');
        $report = app(SquareBillingAudit::class)->report($workspace);
        $this->assertTrue($report['complete']);
        $this->assertSame(['pay_audit_tender'], array_column($report['unmatched_payments']['payments'], 'payment_id'));
        $this->assertSame([], $repository->invoicesNeedingPaymentIds($workspace, 10));

        // A paid invoice whose amounts Square sent in two currencies has no
        // known paid amount, and is read all the same.
        $this->snapshot($workspace, 'inv_audit_2', 2, '2030-02-15', 'PAID', ['paid_amount' => null, 'total_amount' => null, 'square_order_id' => 'order_audit_2']);
        $this->snapshot($workspace, 'inv_audit_3', 3, '2030-03-15', 'UNPAID', ['paid_amount' => null, 'square_order_id' => 'order_audit_3']);
        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
        $this->assertSame([['square_invoice_id' => 'inv_audit_2', 'square_order_id' => 'order_audit_2', 'square_location_id' => 'LOCFIXTURE01', 'basis' => 'PAID||t3']], array_map(static fn (array $lookup): array => array_diff_key($lookup, ['read' => true]), $repository->invoicesNeedingPaymentIds($workspace, 10)));
        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_audit_2', 'PAID||t3', []));
        $this->assertTrue(app(SquareBillingAudit::class)->report($workspace)['complete']);
    }

    public function test_a_truncated_mirror_claims_no_absence_and_is_incomplete(): void
    {
        [$workspace] = $this->fixture();
        $this->assertSame([['series_not_mirrored']], array_column(app(SquareBillingAudit::class)->report($workspace)['series_drift']['agreements'], 'codes'));

        SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::COMPLETED, 'api_version' => '2026-09-16', 'started_at' => '2030-03-20 11:30:00', 'finished_at' => '2030-03-20 11:31:00', 'truncated' => true]);
        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertFalse($report['complete']);
        $this->assertSame(0, $report['series_drift']['count']);
        SquareSyncRun::query()->where('workspace_id', $workspace->id)->delete();
        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete'], 'Never synced.');
    }

    public function test_an_order_too_large_to_read_is_stamped_and_skipped_but_stays_unresolved(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345, 'square_order_id' => 'order_audit_1']);
        $this->snapshot($workspace, 'inv_audit_2', 2, '2030-02-15', 'PAID', ['paid_amount' => 12_345, 'square_order_id' => 'order_audit_2']);
        $repository = app(SquareMirrorRepository::class);

        $this->assertSame(['inv_audit_1'], array_column($repository->invoicesNeedingPaymentIds($workspace, 1), 'square_invoice_id'));
        $this->assertTrue($repository->markPaymentIdsUnresolvable($workspace, 'inv_audit_1', 'PAID|12345|t3'));
        $this->assertFalse($repository->markPaymentIdsUnresolvable($workspace, 'inv_audit_1', 'UNPAID||t3'), 'Stamped only for the state it was read in.');

        $this->assertSame(['inv_audit_2'], array_column($repository->invoicesNeedingPaymentIds($workspace, 1), 'square_invoice_id'));
        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_audit_2', 'PAID|12345|t3', []));
        $this->assertSame([], $repository->invoicesNeedingPaymentIds($workspace, 10));
        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
        // A change to the invoice queues it again.
        SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv_audit_1')->update(['status' => 'PARTIALLY_REFUNDED']);
        $this->assertSame(['inv_audit_1'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'));
    }

    public function test_the_lookup_queue_skips_current_and_unresolvable_rows_in_the_query(): void
    {
        [$workspace] = $this->fixture();
        foreach (['current' => 'PAID|12345|t3', 'stamped' => 'PAID|12345|t3|x', 'unknown_current' => 'PAID||t3'] as $name => $basis) {
            $this->snapshot($workspace, 'inv_'.$name, null, '2030-01-15', 'PAID', ['series_key' => null, 'invoice_number' => '00000'.strlen($name), 'paid_amount' => str_starts_with($name, 'unknown') ? null : 12_345, 'square_order_id' => 'order_'.$name, 'payment_ids' => [], 'payment_ids_basis' => $basis, 'synced_at' => '2030-01-01 00:00:00']);
        }
        $this->snapshot($workspace, 'inv_due', null, '2030-01-15', 'PAID', ['series_key' => null, 'invoice_number' => '000999', 'paid_amount' => 12_345, 'square_order_id' => 'order_due', 'synced_at' => '2030-02-01 00:00:00']);
        $repository = app(SquareMirrorRepository::class);

        $this->assertSame(['inv_due'], array_column($repository->invoicesNeedingPaymentIds($workspace, 1), 'square_invoice_id'));

        // Another sync resolved the ids for this state first: they are kept.
        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_due', 'PAID|12345|t3', ['pay_audit_kept']));
        $this->assertFalse($repository->markPaymentIdsUnresolvable($workspace, 'inv_due', 'PAID|12345|t3'));
        $this->assertSame(['pay_audit_kept'], SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv_due')->value('payment_ids'));
    }

    public function test_a_newer_unfinished_or_failed_sync_leaves_the_audit_incomplete(): void
    {
        [$workspace] = $this->fixture();
        $this->assertTrue(app(SquareBillingAudit::class)->report($workspace)['complete']);
        $run = SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::RUNNING, 'api_version' => '2026-09-16', 'started_at' => '2030-03-20 11:45:00']);
        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
        $run->forceFill(['status' => SquareSyncRun::FAILED, 'error_code' => 'unauthorized'])->save();
        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
    }

    public function test_payment_ids_past_the_bound_are_left_unchecked_and_say_so(): void
    {
        [$workspace] = $this->fixture();
        $ids = array_map(static fn (int $index): string => 'pay_bound_'.$index, range(1, SquareBillingAuditRepository::MAX_PAYMENT_IDS));
        // Loaded newest first: the older invoice is the one past the bound.
        $this->snapshot($workspace, 'inv_audit_2', 2, '2030-02-15', 'PAID', ['paid_amount' => 12_345, 'payment_ids' => ['pay_past_bound']]);
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345, 'payment_ids' => $ids]);
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'client_invoice_payments')) {
                $queries++;
            }
        });

        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertFalse($report['complete']);
        $this->assertSame(SquareBillingAuditRepository::MAX_PAYMENT_IDS, $report['unmatched_payments']['count']);
        $this->assertNotContains('pay_past_bound', array_column($report['unmatched_payments']['payments'], 'payment_id'));
        $this->assertSame(SquareBillingAuditRepository::MAX_PAYMENT_IDS / 500, $queries);
    }

    public function test_only_payments_the_audit_checks_spend_the_bound(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345, 'payment_ids' => ['pay_in_reach']]);
        // Newer, and out of reach: an unmapped customer's one-off invoice.
        $ids = array_map(static fn (int $index): string => 'pay_stranger_'.$index, range(1, SquareBillingAuditRepository::MAX_PAYMENT_IDS));
        $this->snapshot($workspace, 'inv_stranger', null, '2030-02-15', 'UNPAID', ['series_key' => null, 'invoice_number' => '000500', 'square_customer_id' => 'cust_audit_stranger', 'paid_amount' => 100, 'payment_ids' => $ids]);

        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertTrue($report['complete']);
        $this->assertSame(['pay_in_reach'], array_column($report['unmatched_payments']['payments'], 'payment_id'));
    }

    public function test_a_paid_invoice_without_an_order_cannot_be_resolved(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345]);

        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
    }

    public function test_series_members_do_not_spend_the_reach_bound(): void
    {
        [$workspace] = $this->fixture();
        $rows = [];
        // Thousands of a confirmed customer's series members, and a few
        // thousand open invoices of customers svc does not know.
        for ($index = 1; $index <= 4000; $index++) {
            $rows[] = $this->row($workspace, 'inv_member_'.$index, ['invoice_number' => sprintf('000123-R-%04d', $index), 'series_key' => '000123', 'series_occurrence' => $index, 'square_customer_id' => 'cust_audit_series', 'status' => 'CANCELED']);
        }
        for ($index = 1; $index <= 1500; $index++) {
            $rows[] = $this->row($workspace, 'inv_open_'.$index, ['invoice_number' => sprintf('%06d', $index), 'square_customer_id' => 'cust_open_'.$index, 'status' => 'UNPAID']);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('square_invoice_snapshots')->insert($chunk);
        }

        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertTrue($report['complete']);
        $this->assertSame(1500, $report['unlinked_customer_balances']['count']);
    }

    public function test_an_invoice_that_cannot_hold_a_payment_reports_none_whatever_was_cached(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_audit_1', 1, '2030-01-15', 'CANCELED', ['paid_amount' => 0, 'square_order_id' => 'order_audit_1', 'payment_ids' => ['pay_voided_under_old_rules'], 'payment_ids_basis' => 'CANCELED|0']);

        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertSame(0, $report['unmatched_payments']['count']);
        $this->assertTrue($report['complete']);
    }

    public function test_an_order_awaiting_an_authorization_is_retried_after_invoices_never_read(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_pending', null, '2030-01-15', 'PAID', ['series_key' => null, 'invoice_number' => '000501', 'paid_amount' => null, 'square_order_id' => 'order_pending', 'synced_at' => '2030-01-01 00:00:00']);
        $this->snapshot($workspace, 'inv_unread', null, '2030-01-15', 'PAID', ['series_key' => null, 'invoice_number' => '000502', 'paid_amount' => 12_345, 'square_order_id' => 'order_unread', 'synced_at' => '2030-02-01 00:00:00']);
        $repository = app(SquareMirrorRepository::class);
        $this->assertTrue($repository->markPaymentIdsUnresolvable($workspace, 'inv_pending', 'PAID||t3', retryAfter: CarbonImmutable::parse('2030-01-01 00:00:00')));

        $this->assertSame(['inv_unread'], array_column($repository->invoicesNeedingPaymentIds($workspace, 1), 'square_invoice_id'));
        $this->assertSame(['inv_unread', 'inv_pending'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'));
        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);

        // Pending orders take turns: one retried goes behind another.
        $this->snapshot($workspace, 'inv_pending_2', null, '2030-01-15', 'PAID', ['series_key' => null, 'invoice_number' => '000503', 'paid_amount' => null, 'square_order_id' => 'order_pending_2', 'synced_at' => '2030-01-02 00:00:00']);
        $this->assertTrue($repository->markPaymentIdsUnresolvable($workspace, 'inv_pending_2', 'PAID||t3', retryAfter: CarbonImmutable::parse('2030-01-02 00:00:00')));
        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_unread', 'PAID|12345|t3', []));
        $this->assertSame(['inv_pending', 'inv_pending_2'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'));
        $this->assertTrue($repository->markPaymentIdsUnresolvable($workspace, 'inv_pending', 'PAID||t3', retryAfter: CarbonImmutable::parse('2030-03-20 12:00:00')));
        $this->assertSame(['inv_pending_2', 'inv_pending'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'));
        $this->assertTrue($repository->markPaymentIdsUnresolvable($workspace, 'inv_pending_2', 'PAID||t3'), 'Without a retry time it is final.');
        $this->assertSame(['inv_pending'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'));
    }

    public function test_a_sync_that_finishes_while_the_mirror_is_read_leaves_the_audit_incomplete(): void
    {
        [$workspace] = $this->fixture();
        $running = SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::RUNNING, 'api_version' => '2026-09-16', 'started_at' => '2030-03-20 11:45:00']);
        // It completes after the first read of the newest run, mid-load.
        $finished = false;
        DB::listen(function (QueryExecuted $query) use ($running, &$finished): void {
            if (! $finished && str_contains($query->sql, 'client_agreements')) {
                $finished = true;
                DB::table('square_sync_runs')->where('id', $running->id)->update(['status' => SquareSyncRun::COMPLETED, 'finished_at' => '2030-03-20 11:46:00']);
            }
        });

        $this->assertFalse(app(SquareBillingAudit::class)->report($workspace)['complete']);
        $this->assertTrue($finished);
        $this->assertTrue(app(SquareBillingAudit::class)->report($workspace)['complete'], 'The next read is of one generation.');
    }

    public function test_a_read_with_no_known_amount_is_current_but_read_again_in_turn(): void
    {
        [$workspace] = $this->fixture();
        $this->snapshot($workspace, 'inv_unknown', null, '2030-01-15', 'PARTIALLY_PAID', ['series_key' => null, 'invoice_number' => '000601', 'paid_amount' => null, 'total_amount' => null, 'square_order_id' => 'order_unknown', 'synced_at' => '2030-01-01 00:00:00']);
        $this->snapshot($workspace, 'inv_unread', null, '2030-01-15', 'PAID', ['series_key' => null, 'invoice_number' => '000602', 'paid_amount' => 12_345, 'square_order_id' => 'order_unread', 'synced_at' => '2030-02-01 00:00:00']);
        $repository = app(SquareMirrorRepository::class);

        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_unknown', 'PARTIALLY_PAID||t3', ['pay_partial_1'], rereadAfter: CarbonImmutable::parse('2030-03-20 12:00:00')));
        $this->assertSame('PARTIALLY_PAID||t3|r', SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv_unknown')->value('payment_ids_basis'));
        $this->assertSame(['inv_unread', 'inv_unknown'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'), 'Read again, after invoices never read.');
        $this->assertTrue($repository->storePaymentIds($workspace, 'inv_unread', 'PAID|12345|t3', []));
        $this->assertSame(['inv_unknown'], array_column($repository->invoicesNeedingPaymentIds($workspace, 10), 'square_invoice_id'));
        $this->assertTrue(app(SquareBillingAudit::class)->report($workspace)['complete'], 'The read is current meanwhile.');
        $this->assertFalse($repository->markPaymentIdsUnresolvable($workspace, 'inv_unknown', 'PARTIALLY_PAID||t3', retryAfter: CarbonImmutable::parse('2030-03-20 12:00:00')), 'A slower reader never undoes a reread.');
        $this->assertSame('PARTIALLY_PAID||t3|r', SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv_unknown')->value('payment_ids_basis'));
        // Once the amount is known, the old reread stamp no longer protects the row.
        SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->where('square_invoice_id', 'inv_unknown')->update(['paid_amount' => 500]);
        $this->assertTrue($repository->markPaymentIdsUnresolvable($workspace, 'inv_unknown', 'PARTIALLY_PAID|500|t3'));
    }

    public function test_a_linked_member_past_the_series_bound_is_still_checked(): void
    {
        [$workspace, , $company] = $this->fixture();
        // The oldest member, linked and paid, then more members than the bound.
        $this->snapshot($workspace, 'inv_audit_old', 1, '2030-01-15', 'PAID', ['paid_amount' => 12_345, 'payment_ids' => ['pay_audit_old']]);
        $this->link($workspace, $this->invoice($workspace, $company, 'SYN-AUDIT-OLD'), 'inv_audit_old');
        $rows = [];
        for ($index = 2; $index <= SquareBillingAuditRepository::MAX_SERIES_MEMBERS + 1; $index++) {
            // The loaded members are a confirmed customer's too, and must not
            // crowd the omitted one out of the reach.
            $rows[] = $this->row($workspace, 'inv_member_'.$index, ['invoice_number' => sprintf('000123-R-%04d', $index % 10_000), 'series_key' => '000123', 'series_occurrence' => $index, 'square_customer_id' => 'cust_audit_series', 'status' => 'CANCELED']);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('square_invoice_snapshots')->insert($chunk);
        }

        $report = app(SquareBillingAudit::class)->report($workspace);

        $this->assertFalse($report['complete']);
        $this->assertSame(['pay_audit_old'], array_column($report['unmatched_payments']['payments'], 'payment_id'));
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
        SquareSyncRun::query()->create(['workspace_id' => $workspace->id, 'trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::COMPLETED, 'api_version' => '2026-09-16', 'started_at' => '2030-03-20 11:00:00', 'finished_at' => '2030-03-20 11:01:00']);

        return [$workspace, $owner, $company, $agreement];
    }

    /**
     * A mirrored invoice. Given payment ids, they are resolved for its state
     * unless the overrides say otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function snapshot(Workspace $workspace, string $id, ?int $occurrence, string $due, string $status, array $overrides = []): void
    {
        if (array_key_exists('payment_ids', $overrides) && ! array_key_exists('payment_ids_basis', $overrides)) {
            $overrides = ['square_order_id' => 'order_'.$id, 'payment_ids_basis' => SquareSnapshotMapper::paymentIdsBasis($status, $overrides['paid_amount'] ?? 0), ...$overrides];
        }
        SquareInvoiceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'square_invoice_id' => $id,
            'invoice_number' => sprintf('000123-R-%04d', $occurrence ?? 0),
            'series_key' => '000123',
            'series_occurrence' => $occurrence,
            'square_customer_id' => 'cust_audit_series',
            'square_location_id' => 'LOCFIXTURE01',
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(Workspace $workspace, string $id, array $overrides): array
    {
        return [
            'workspace_id' => $workspace->id, 'square_invoice_id' => $id, 'invoice_number' => '000000', 'series_key' => null, 'series_occurrence' => null,
            'square_customer_id' => null, 'square_location_id' => 'LOCFIXTURE01', 'status' => 'UNPAID', 'currency' => 'USD', 'total_amount' => 100, 'autopay' => false, 'payment_requests' => '[]',
            'fingerprint' => str_repeat('a', 64), 'synced_at' => '2030-03-01 00:00:00', 'last_seen_at' => '2030-03-01 00:00:00',
            ...$overrides,
        ];
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
