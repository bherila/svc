<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\FinanceReconciliationBadges;
use App\Services\Finance\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * "Reconciled in finance" on the invoice screen: shown for a payment with an
 * active allocation from the configured finance system, linked to each
 * transaction through the configured template, and only to a manager.
 */
final class ReconciledInFinanceBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const string TEMPLATE = 'https://finance.example.test/transactions/{transaction_uuid}';

    protected function setUp(): void
    {
        parent::setUp();

        config(['svc.finance.transaction_url' => self::TEMPLATE]);
    }

    public function test_an_active_finance_allocation_badges_the_payment_with_a_link_to_its_transaction(): void
    {
        $fixture = $this->fixture();
        $transaction = $this->reconcile($fixture, 2000);

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.id', $fixture['payment']->public_id)
            ->where('payments.0.finance_reconciliation', [
                'hrefs' => ['https://finance.example.test/transactions/'.$transaction],
            ]));
    }

    public function test_a_payment_with_no_allocation_has_no_badge(): void
    {
        $fixture = $this->fixture();

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation', null));
    }

    public function test_a_deactivated_allocation_is_no_longer_badged(): void
    {
        $fixture = $this->fixture();
        $transaction = $this->reconcile($fixture, 2000);
        app(PaymentReconciliationService::class)->deactivate(
            $fixture['workspace'],
            $fixture['payment'],
            'finance',
            $transaction,
        );

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation', null));
    }

    public function test_an_allocation_from_another_external_system_is_not_finance(): void
    {
        $fixture = $this->fixture();
        $this->reconcile($fixture, 2000, 'ledger');

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation', null));
    }

    public function test_the_external_system_comes_from_configuration(): void
    {
        $fixture = $this->fixture();
        $transaction = $this->reconcile($fixture, 2000, 'ledger');
        config(['svc.finance.external_system' => 'Ledger']);

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation.hrefs', ['https://finance.example.test/transactions/'.$transaction]));
    }

    public function test_without_a_link_template_the_badge_has_no_link(): void
    {
        $fixture = $this->fixture();
        $this->reconcile($fixture, 2000);
        config(['svc.finance.transaction_url' => null]);

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation', ['hrefs' => []]));
    }

    public function test_an_unsafe_link_template_gives_a_badge_without_a_link(): void
    {
        $fixture = $this->fixture();
        $this->reconcile($fixture, 2000);
        config(['svc.finance.transaction_url' => 'javascript:open("{transaction_uuid}")']);

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation', ['hrefs' => []]));
    }

    public function test_a_payment_split_across_transactions_links_each_once(): void
    {
        $fixture = $this->fixture();
        $first = $this->reconcile($fixture, 2000);
        $second = $this->reconcile($fixture, 1000);

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation.hrefs', [
                'https://finance.example.test/transactions/'.$first,
                'https://finance.example.test/transactions/'.$second,
            ]));
    }

    public function test_an_allocation_flagged_for_review_is_still_reconciled(): void
    {
        $fixture = $this->fixture();
        $transaction = $this->reconcile($fixture, 5000);
        app(InvoiceLifecycleService::class)->setRefundedAmount($fixture['payment'], 1000, $fixture['workspace']);

        $this->screen($fixture['owner'], $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.finance_reconciliation.hrefs', ['https://finance.example.test/transactions/'.$transaction]));
    }

    public function test_only_the_reconciled_payment_on_the_invoice_is_badged(): void
    {
        $fixture = $this->fixture(amount: 10000);
        $second = app(InvoiceLifecycleService::class)->applyPayment($fixture['invoice'], [
            'amount' => 5000,
            'currency' => 'USD',
            'method' => 'check',
            'status' => 'succeeded',
            'received_on' => '2026-08-01',
            'idempotency_key' => 'payment-'.Str::random(16),
        ], $fixture['workspace']);
        $this->reconcile($fixture, 2000);

        $this->screen($fixture['owner'], $fixture)->assertInertia(function (Assert $page) use ($fixture, $second): void {
            $payments = collect($page->toArray()['props']['payments'])->keyBy('id');
            $this->assertNotNull($payments[$fixture['payment']->public_id]['finance_reconciliation']);
            $this->assertNull($payments[$second->public_id]['finance_reconciliation']);
        });
    }

    public function test_a_member_who_cannot_manage_the_workspace_sees_no_badge(): void
    {
        $fixture = $this->fixture();
        $this->reconcile($fixture, 2000);
        $member = User::factory()->create();
        $fixture['workspace']->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $fixture['workspace']->id,
            'client_project_id' => $fixture['project']->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        $this->screen($member, $fixture)->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.id', $fixture['payment']->public_id)
            ->where('payments.0.finance_reconciliation', null));
    }

    public function test_the_badges_are_read_in_one_query_bounded_by_workspace(): void
    {
        $fixture = $this->fixture();
        $this->reconcile($fixture, 2000);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'payment_reconciliations')) {
                $queries[] = $query->sql;
            }
        });

        $badges = app(FinanceReconciliationBadges::class)->forPayments(
            $fixture['workspace'],
            $fixture['invoice']->payments()->get(),
        );

        $this->assertCount(1, $badges);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('workspace_id', $queries[0]);
        $this->assertSame([], app(FinanceReconciliationBadges::class)->forPayments($fixture['workspace'], []));
    }

    /**
     * @param  array{workspace: Workspace, company: ClientCompany, invoice: ClientInvoice}  $fixture
     * @return TestResponse<Response>
     */
    private function screen(User $viewer, array $fixture): TestResponse
    {
        return $this->actingAs($viewer)
            ->get("/workspaces/{$fixture['workspace']->public_id}/clients/{$fixture['company']->public_id}/invoices/{$fixture['invoice']->public_id}")
            ->assertOk();
    }

    /**
     * @param  array{owner: User, workspace: Workspace, payment: ClientInvoicePayment}  $fixture
     */
    private function reconcile(array $fixture, int $amount, string $system = 'finance'): string
    {
        $transaction = (string) Str::uuid();
        app(PaymentReconciliationService::class)->upsert($fixture['workspace'], $fixture['payment'], $fixture['owner'], [
            'external_system_slug' => $system,
            'external_transaction_uuid' => $transaction,
            'allocated_amount' => $amount,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        return $transaction;
    }

    /**
     * @return array{owner: User, workspace: Workspace, company: ClientCompany, project: ClientProject, invoice: ClientInvoice, payment: ClientInvoicePayment}
     */
    private function fixture(int $amount = 5000): array
    {
        $workspace = Workspace::query()->create(['name' => 'Badge workspace', 'slug' => 'badge-'.Str::lower(Str::random(6))]);
        $owner = User::factory()->create();
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Badge client',
            'slug' => 'badge-client-'.Str::lower(Str::random(6)),
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Badge project',
            'status' => 'active',
        ]);
        $lifecycle = app(InvoiceLifecycleService::class);
        $invoice = $lifecycle->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYNTH-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic badge service',
            'quantity' => '1',
            'unit_amount' => $amount,
            'tax_amount' => 0,
            'client_project_id' => $project->id,
        ]]);
        $lifecycle->issue($invoice, $workspace);
        $payment = $lifecycle->applyPayment($invoice, [
            'amount' => 5000,
            'currency' => 'USD',
            'method' => 'wire',
            'status' => 'succeeded',
            'received_on' => '2026-08-10',
            'idempotency_key' => 'payment-'.Str::random(16),
        ], $workspace);

        return [
            'owner' => $owner,
            'workspace' => $workspace,
            'company' => $company,
            'project' => $project,
            'invoice' => $invoice->fresh(),
            'payment' => $payment,
        ];
    }
}
