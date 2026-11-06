<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\ClientProject;
use App\Models\ProcessorMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\ProcessorMovementRecorder;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * A synthetic workspace for the accounting reads, built at fixed instants in
 * early 2030 so every date and total in the tests is exact. Every name, number
 * and identifier is invented, and money is in minor units of USD.
 *
 * - Alpha (workspace timezone America/New_York): invoice SYN-A-1, issued
 *   2030-02-01, with a taxable adjustment (2 x 50.00 + 8.00 tax, a project
 *   line), an untaxed adjustment (30.00), an expense billed at cost (12.00)
 *   and a credit (-5.00). A payment of 100.00 on it was received by hand on
 *   2030-02-04, 25.00 refunded on 2030-03-02 and 5.00 of that refund
 *   reversed on 2030-03-03. A Stripe payment of 200.00 (fee 6.10) received
 *   2030-02-05 was refunded 50.00 and charged a further fee of 3.00 by the
 *   processor.
 * - Beta: invoice SYN-B-1, issued 2030-02-10 and voided 2030-02-20.
 * - A draft that is no entry of the books.
 */
trait BuildsAccountingFixtures
{
    /**
     * @return array{workspace: Workspace, owner: User, alpha: ClientCompany, beta: ClientCompany, project: ClientProject, invoice: ClientInvoice, voided: ClientInvoice, manual: ClientInvoicePayment, stripe: ClientInvoicePayment, refund: ClientInvoicePaymentRefund, reversal: ClientInvoicePaymentRefund, movements: list<ProcessorMovement>}
     */
    protected function accountingWorkspace(string $slug, int $scale = 1): array
    {
        Mail::fake();
        $workspace = Workspace::query()->create(['name' => 'Synthetic accounting '.$slug, 'slug' => 'synthetic-accounting-'.$slug, 'default_currency' => 'USD', 'timezone' => 'America/New_York']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $alpha = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Alpha '.$slug, 'slug' => 'synthetic-alpha-'.$slug]);
        $beta = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Beta '.$slug, 'slug' => 'synthetic-beta-'.$slug]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $alpha->id, 'name' => 'Synthetic Project '.$slug]);

        $this->travelTo('2030-02-01 15:00:00');
        $invoice = $this->accountingInvoice($workspace, $alpha, 'SYN-A-1-'.$slug, '2030-02-01', [
            ['adjustment', 'Hosting', '2', 5000 * $scale, 800 * $scale, $project->id],
            ['adjustment', 'Setup', '1', 3000 * $scale, 0, null],
        ], [['expense', 'Domain at cost', 1200 * $scale], ['credit', 'Credit applied', -500 * $scale]]);

        $this->travelTo('2030-02-04 16:00:00');
        $manual = $this->accountingPayment($workspace, $invoice, 10000 * $scale, '2030-02-04', 'bank_transfer', null);
        $this->travelTo('2030-02-05 16:00:00');
        $stripe = $this->accountingPayment($workspace, $invoice, 20000 * $scale, '2030-02-05', 'card', 'stripe', 610 * $scale);

        // 02:30 UTC on March 2 is 21:30 on March 1 in New York.
        $this->travelTo('2030-03-02 02:30:00');
        $refund = ClientInvoicePaymentRefund::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_payment_id' => $manual->id, 'kind' => PaymentRefundKind::Refund, 'amount' => 2500 * $scale, 'reason' => 'Synthetic refund',
        ]);
        $this->travelTo('2030-03-03 15:00:00');
        $reversal = ClientInvoicePaymentRefund::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_payment_id' => $manual->id, 'kind' => PaymentRefundKind::Reversal, 'amount' => 500 * $scale,
            'reason' => 'Synthetic reversal', 'reverses_refund_id' => $refund->id,
        ]);
        $manual->forceFill(['refunded_amount' => 2000 * $scale])->save();

        $this->travelTo('2030-03-04 15:00:00');
        $movements = [
            $this->accountingMovement($workspace, $stripe, 'synthetic_refund_'.$slug, ProcessorMovementKind::Refund, -5000 * $scale, 0, '2030-03-04T14:00:00Z'),
            $this->accountingMovement($workspace, $stripe, 'synthetic_fee_'.$slug, ProcessorMovementKind::Fee, 0, 300 * $scale, '2030-03-04T14:30:00Z'),
        ];
        $stripe->forceFill(['refunded_amount' => 5000 * $scale])->save();

        $this->travelTo('2030-02-10 15:00:00');
        $voided = $this->accountingInvoice($workspace, $beta, 'SYN-B-1-'.$slug, '2030-02-10', [['adjustment', 'Consulting', '1', 40000 * $scale, 3200 * $scale, null]], []);
        $this->travelTo('2030-02-20 18:00:00');
        app(InvoiceLifecycleService::class)->void($voided->fresh(), $workspace, 'Synthetic void.');

        $this->travelTo('2030-02-12 15:00:00');
        app(InvoiceLifecycleService::class)->createDraft($workspace, $beta, ['invoice_number' => 'SYN-DRAFT-'.$slug, 'currency' => 'USD'], [
            ['type' => 'adjustment', 'description' => 'Draft only', 'quantity' => '1', 'unit_amount' => 999999, 'tax_amount' => 0],
        ]);

        return ['workspace' => $workspace, 'owner' => $owner, 'alpha' => $alpha, 'beta' => $beta, 'project' => $project, 'invoice' => $invoice->fresh(), 'voided' => $voided->fresh(),
            'manual' => $manual->fresh(), 'stripe' => $stripe->fresh(), 'refund' => $refund, 'reversal' => $reversal, 'movements' => $movements];
    }

    /**
     * An invoice issued through the lifecycle service, with lines the manual door refuses added to it once issued.
     *
     * @param  list<array{string, string, string, int, int, int|null}>  $operatorLines  type, description, quantity, unit, tax, project id
     * @param  list<array{string, string, int}>  $systemLines  type, description, amount (pre-tax)
     */
    protected function accountingInvoice(Workspace $workspace, ClientCompany $company, string $number, string $issueDate, array $operatorLines, array $systemLines): ClientInvoice
    {
        $draft = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
            'invoice_number' => $number, 'currency' => 'USD', 'issue_date' => $issueDate, 'due_date' => CarbonImmutable::parse($issueDate)->addDays(30)->toDateString(),
        ], array_map(static fn (array $line): array => [
            'type' => $line[0], 'description' => $line[1], 'quantity' => $line[2], 'unit_amount' => $line[3], 'tax_amount' => $line[4], 'client_project_id' => $line[5],
        ], $operatorLines));
        $issued = app(InvoiceLifecycleService::class)->issue($draft->fresh(), $workspace);
        // Added once issued: the manual door refuses these types, and issuing would try to spend a credit or claim an expense.
        foreach ($systemLines as $index => [$type, $description, $amount]) {
            ClientInvoiceLine::query()->create([
                'workspace_id' => $workspace->id, 'client_invoice_id' => $issued->id, 'type' => $type, 'description' => $description, 'quantity' => '1',
                'unit_amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount, 'sort_order' => 100 + $index,
            ]);
        }

        return $issued;
    }

    protected function accountingPayment(Workspace $workspace, ClientInvoice $invoice, int $amount, string $receivedOn, string $method, ?string $provider, ?int $fee = null): ClientInvoicePayment
    {
        $payment = ClientInvoicePayment::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'status' => 'succeeded', 'amount' => $amount, 'refunded_amount' => 0,
            'currency' => 'USD', 'received_on' => $receivedOn, 'method' => $method, 'reference' => 'synthetic-'.$method,
            'provider' => $provider, 'provider_payment_identifier' => $provider === null ? null : 'pi_synthetic_'.$workspace->slug,
        ]);
        if ($fee !== null) {
            $payment->forceFill(['provider_fee_amount' => $fee])->save();
        }

        return $payment;
    }

    protected function accountingMovement(Workspace $workspace, ClientInvoicePayment $payment, string $identifier, ProcessorMovementKind $kind, int $principal, int $fee, string $occurredAt): ProcessorMovement
    {
        $facts = new ProcessorMovementFacts(
            provider: 'stripe', identifier: $identifier, paymentIdentifiers: [(string) $payment->provider_payment_identifier], sourceIdentifier: null,
            kind: $kind, principalMinor: $principal, feeMinor: $fee, netMinor: $principal - $fee, currency: 'USD',
            occurredAt: CarbonImmutable::parse($occurredAt), dateBasis: 'stripe_balance_created',
        );
        app(ProcessorMovementRecorder::class)->record($workspace, $payment->id, $facts, 'synthetic_account_'.$workspace->slug, true);

        return ProcessorMovement::query()->where('workspace_id', $workspace->id)->where('provider_entry_identifier', $identifier)->firstOrFail();
    }
}
