<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\ServicePeriodRequirement;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class InvoiceLifecycleService
{
    /**
     * How an operator actually repairs a refused draft.
     *
     * Said once, and deliberately not "give it a service period":
     * {@see self::updateDraft()} accepts currency, totals, due date, notes and
     * lines only. It can set neither service-period boundary nor the kind, so
     * an instruction to correct one in place names an operation this
     * application does not have.
     */
    private const REPAIR_PATH = 'Discard and recreate this draft with a complete service period. '
        .'If the draft came from imported or repaired data, correct its stored period through the '
        .'audited administrative repair path before issuing it.';

    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ClientActivityRecorder $activities,
        private readonly OverpaymentCreditService $overpaymentCreditService = new OverpaymentCreditService,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     */
    public function createDraft(Workspace $workspace, ClientCompany $company, array $attributes, array $lines, array $subtotalOverrides = []): ClientInvoice
    {
        $this->assertCompanyTenant($workspace, $company);
        $currency = MoneyService::currency($attributes['currency'] ?? null);
        $totals = MoneyService::invoiceTotals($lines, $subtotalOverrides);

        return DB::transaction(function () use ($workspace, $company, $attributes, $lines, $subtotalOverrides, $currency, $totals): ClientInvoice {
            $invoice = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_agreement_id' => $attributes['client_agreement_id'] ?? null,
                'client_billing_schedule_id' => $attributes['client_billing_schedule_id'] ?? null,
                'invoice_number' => $this->requiredString($attributes['invoice_number'] ?? null, 'invoice_number'),
                'status' => 'draft',
                // Recorded rather than inferred. A null kind reads as a cadence
                // invoice, which makes the replay try to reproduce something an
                // operator typed and lets it block cadence generation through
                // the overlap guard that deliberately exempts ad-hoc work.
                // Ad hoc is the operator default, not a universal one. A
                // billing schedule creates machine-generated recurring
                // invoices through this same method, and classifying those as
                // ad hoc made the cadence overlap guard and the replay ignore
                // them - so a second invoice could be generated for the same
                // agreement and period.
                'invoice_kind' => $attributes['invoice_kind']
                    ?? (($attributes['client_billing_schedule_id'] ?? null) === null
                        ? InvoiceKind::AdHoc->value
                        : InvoiceKind::CadencePeriod->value),
                'issue_date' => $attributes['issue_date'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'service_period_start' => $attributes['service_period_start'] ?? null,
                'service_period_end' => $attributes['service_period_end'] ?? null,
                // Zero, not null. Nothing on this path bills overage hours, and
                // since #144 a null here means *unknown* rather than *none* -
                // the figure is subtracted from what the next period charges,
                // so a reader that cannot tell the two apart bills the same
                // hours twice, and one that can must refuse. Leaving it unset
                // made every scheduled and ad-hoc invoice unreadable the moment
                // it was issued, which permanently stopped cadence generation
                // for the agreement it belonged to.
                //
                // The sibling generators already write an explicit 0 for the
                // same situation; this was the one creation path that did not.
                'hours_billed_at_rate' => $attributes['hours_billed_at_rate'] ?? 0,
                'currency' => $currency,
                ...$totals,
                'balance_amount' => $totals['total_amount'],
                'notes' => $attributes['notes'] ?? null,
                'is_visible_to_client' => (bool) ($attributes['is_visible_to_client'] ?? false),
            ]);

            $this->createLines($invoice, $workspace, $lines, $subtotalOverrides);
            $this->activities->record($workspace, $company, 'invoice.generated', $invoice, [
                'invoice_kind' => $invoice->invoiceKindValue(),
                'status' => 'draft',
                'total_amount' => $invoice->total_amount,
                'currency' => $invoice->currency,
            ]);

            return $invoice->load('lines', 'clientCompany');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     */
    public function updateDraft(ClientInvoice $invoice, Workspace $workspace, array $attributes, array $lines, array $subtotalOverrides = []): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $attributes, $lines, $subtotalOverrides): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only draft invoices can be updated.');
            }
            $currency = MoneyService::currency($attributes['currency'] ?? $locked->currency);
            $totals = MoneyService::invoiceTotals($lines, $subtotalOverrides);
            $updates = [
                'currency' => $currency,
                ...$totals,
                'balance_amount' => $totals['total_amount'],
            ];
            foreach (['due_date', 'notes'] as $attribute) {
                if (array_key_exists($attribute, $attributes)) {
                    $updates[$attribute] = $attributes[$attribute];
                }
            }

            // Named on the statement, not only on the invoice it hangs off.
            // A relation delete is a builder write: it never reaches
            // `setKeysForSaveQuery()`, so the workspace has to be said here.
            $locked->lines()->where('workspace_id', $workspace->id)->delete();
            $locked->forceFill($updates)->save();
            $this->createLines($locked, $workspace, $lines, $subtotalOverrides);
            $this->activities->record(
                $workspace,
                $locked->clientCompany,
                'invoice.updated',
                $locked,
                [
                    'invoice_kind' => $locked->invoiceKindValue(),
                    'total_amount' => $locked->total_amount,
                    'currency' => $locked->currency,
                    'line_count' => count($lines),
                ],
                occurrence: (string) Str::uuid(),
            );

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    public function discardDraft(ClientInvoice $invoice, Workspace $workspace, string $reason): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $reason): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only a draft invoice can be discarded.');
            }

            $this->releaseAllocations($locked);
            $locked->forceFill([
                'status' => 'void',
                'voided_at' => $this->clock->now($workspace),
                'void_reason' => $reason,
                'balance_amount' => 0,
            ])->save();
            $this->activities->record($workspace, $locked->clientCompany, 'invoice.voided', $locked, [
                'invoice_kind' => $locked->invoiceKindValue(),
                'previous_status' => 'draft',
            ]);

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    public function issue(ClientInvoice $invoice, ?Workspace $workspace = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status !== 'draft') {
                if (InvoiceStatus::hasChargedValue($locked->status)) {
                    return $locked;
                }

                throw new DomainException('Only draft invoices can be issued.');
            }

            // Before anything is spent, moved or recorded, and deliberately
            // after the charged-status return above: an invoice that already
            // took money keeps its idempotent `issue()`, because refusing it
            // here would turn a malformed *existing* row into an error on a
            // path that used to be a no-op. Those rows belong to the census and
            // the repair, not to this transition.
            //
            // The transition is the right place for it rather than
            // `createDraft()`. A draft that states no period has charged nobody
            // and must keep being allowed: `InterimOverageGenerator` raises a
            // correctly placed interim *beside* an unplaceable draft precisely
            // so work genuinely owed is still billed (see
            // `CapacityAndScopeGuardsTest::test_an_unplaceable_interim_draft_does_not_suppress_interim_billing`).
            // What must not happen is that the stale draft is then issued too,
            // at which point two invoices claim the same hours for the same
            // period with nothing on either to show it - #218.
            //
            // #250 fixed the other half at generation time:
            // `BillingPeriodCollisionResolver` refuses a run when a row it must
            // place states no complete period. That stops the money mutation
            // but not the row, which is why the same rule is needed on the door
            // every issuance goes through - browser, command, API and MCP all
            // arrive here.
            //
            // Ownership is part of the question, not only kind. The resolver's
            // kind exemption is reached only for an *unlinked* row, because a
            // row naming this schedule is this schedule's whatever kind it
            // carries - so a schedule-linked ad-hoc invoice with no period is
            // read there as unbounded, established as the schedule's, and
            // refused. Issuing one manufactures a live row that halts the
            // schedule's next run.
            $requirement = ServicePeriodRequirement::for(
                $locked->invoice_kind,
                $locked->client_billing_schedule_id !== null,
            );

            // Before the period question, and regardless of it. An unrecognised
            // kind is `cadence_period` to `invoiceKindValue()` and to nothing
            // that reads the raw column, so an issued one is a cadence invoice
            // the cycle guard cannot see - and `cycleAlreadySold()` is what
            // stops a later correction selling the same retainer twice.
            if ($requirement === ServicePeriodRequirement::UnsupportedKind) {
                throw new DomainException($this->unsupportedKindRefusal($locked));
            }

            if ($requirement->requiresBothBoundaries()
                && ($locked->service_period_start === null || $locked->service_period_end === null)) {
                throw new DomainException($this->undatedPeriodRefusal($locked));
            }

            // Both boundaries present is necessary and not sufficient. A
            // reversed interval states a span no period guard can place either:
            // `possiblyOverlapping()` asks `start <= $end` and `end >= $start`,
            // and a row whose start follows its end fails one of those for
            // *every* period, including the two it sits between. It leaves the
            // resolver entirely, and `billing_schedule_service_period_unique`
            // does not object because the reversed tuple differs from either
            // valid one - so ordinary invoices can be generated beside it.
            //
            // Asked of an exempt row too. An ad-hoc invoice need not state a
            // period, but one that does must mean something by it.
            if ($locked->service_period_start !== null
                && $locked->service_period_end !== null
                && $locked->service_period_start->gt($locked->service_period_end)) {
                throw new DomainException($this->reversedPeriodRefusal());
            }

            $issueDate = $locked->issue_date ?? $this->clock->today($locked->workspace);
            if ($locked->due_date !== null && $locked->due_date->lt($issueDate)) {
                throw new DomainException('The due date cannot precede the issue date.');
            }

            // Credit is only spent at issue. Two drafts can each be offered the
            // whole available pool - that is deliberate, since drafts regenerate
            // freely and reserving against them would strand credit - so the
            // pool is re-checked here, where the money actually leaves it.
            //
            // The invoice row lock is not enough: two different drafts lock two
            // different rows, so both could read the same unconsumed pool. The
            // company is what the pool belongs to, so that is what serializes.
            ClientCompany::query()->whereKey($locked->client_company_id)->tap(Locks::forUpdate())->first();
            $this->capOverpaymentCreditAtIssue($locked);

            $locked->forceFill([
                'issue_date' => $issueDate,
                'due_date' => $locked->due_date ?? $issueDate,
                'issued_at' => $this->clock->now($locked->workspace),
                'status' => 'issued',
                'is_visible_to_client' => true,
                'balance_amount' => $locked->total_amount,
            ])->save();

            foreach ($locked->lines()->with('timeEntries')->get() as $line) {
                // The workspace on the statement, not only on the invoice
                // the lines hang off: a relation update is a builder write and
                // reaches no model hook.
                $line->timeEntries()
                    ->where('client_time_entries.workspace_id', $locked->workspace_id)
                    ->where('status', 'approved')
                    ->update([
                        'status' => 'invoiced',
                        'lock_version' => DB::raw('lock_version + 1'),
                    ]);
            }
            $this->activities->record(
                $locked->workspace,
                $locked->clientCompany,
                'invoice.issued',
                $locked,
                [
                    'invoice_kind' => $locked->invoiceKindValue(),
                    'total_amount' => $locked->total_amount,
                    'currency' => $locked->currency,
                ],
            );

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    /**
     * Say which boundary is missing, and why this kind may not go without it.
     *
     * Named rather than a generic "invalid invoice": the operator reading this
     * has to know that the fix is to give the row a period, not to retry.
     */
    private function undatedPeriodRefusal(ClientInvoice $invoice): string
    {
        $missing = match (true) {
            $invoice->service_period_start === null && $invoice->service_period_end === null => 'no service period at all',
            $invoice->service_period_start === null => 'no service period start',
            default => 'no service period end',
        };

        $subject = $invoice->client_billing_schedule_id === null
            ? 'A '.$invoice->invoiceKindValue().' invoice'
            : 'An invoice naming a billing schedule';

        return $subject.' states '.$missing.', so it cannot be issued. '
            .'It is a claim about a span of time, and one that states no span cannot be placed against any '
            .'other: the period guards read both boundaries, and a null answers UNKNOWN rather than false, '
            .'so the same work can be billed again with nothing able to reject it. '
            .self::REPAIR_PATH;
    }

    private function unsupportedKindRefusal(ClientInvoice $invoice): string
    {
        return 'This invoice carries an unrecognised invoice kind ('.(string) $invoice->invoice_kind.'), '
            .'so it cannot be issued. The model reads an unrecognised kind as a cadence invoice while the '
            .'raw-column guards do not, so an issued one is invisible to the check that stops a later '
            .'correction selling the same retainer and recurring items a second time. '
            .'Discard and recreate this draft with a supported invoice kind, or correct its stored kind '
            .'through the audited administrative repair path.';
    }

    private function reversedPeriodRefusal(): string
    {
        return 'The service period start cannot follow the service period end, so this invoice cannot be '
            .'issued. A reversed span is placed by no period guard - it fails the overlap test for every '
            .'period, including the ones on either side of it - so ordinary invoices can be generated '
            .'beside it for the work it already charged. '
            .self::REPAIR_PATH;
    }

    /**
     * Trim this invoice's credit line to whatever the pool can still cover.
     *
     * Without this, two drafts prepared against the same overpayment can both
     * be issued and both consume it, handing the client the credit twice. The
     * check belongs at issue rather than in the draft calculation because issue
     * is the first moment the spend becomes real, and it is serialized by the
     * row lock taken above.
     */
    private function capOverpaymentCreditAtIssue(ClientInvoice $invoice): void
    {
        $creditLine = $invoice->lines()
            ->where('type', InvoiceLineType::Credit->value)
            ->first();

        if (! $creditLine instanceof ClientInvoiceLine) {
            return;
        }

        $company = $invoice->clientCompany;
        if (! $company instanceof ClientCompany) {
            return;
        }

        $applied = abs((int) $creditLine->total_amount);
        $available = (int) round($this->overpaymentCreditService
            ->availableCreditForCompany($company, (string) $invoice->currency) * 100);

        if ($applied <= $available) {
            return;
        }

        if ($available <= 0) {
            $creditLine->delete();
        } else {
            $creditLine->forceFill([
                'unit_amount' => -$available,
                'total_amount' => -$available,
            ])->save();
        }

        $invoice->refresh();
        $invoice->recalculateTotals();
    }

    public function void(ClientInvoice $invoice, ?Workspace $workspace = null, ?string $reason = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $reason): ClientInvoice {
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status === 'void') {
                return $locked;
            }

            if ($locked->paid_amount > 0 || $locked->status === 'paid') {
                throw new DomainException('A paid invoice cannot be voided.');
            }

            $hasPendingPayments = $locked->payments()->where('status', 'pending')->exists();
            if ($hasPendingPayments) {
                throw new DomainException('Cancel or resolve pending payments before voiding this invoice.');
            }

            $this->releaseAllocations($locked);
            $previousStatus = $locked->status;
            $locked->forceFill(['status' => 'void', 'voided_at' => $this->clock->now($locked->workspace), 'void_reason' => $reason, 'balance_amount' => 0])->save();
            $this->activities->record(
                $locked->workspace,
                $locked->clientCompany,
                'invoice.voided',
                $locked,
                ['invoice_kind' => $locked->invoiceKindValue(), 'previous_status' => $previousStatus],
            );

            return $locked->fresh(['lines', 'clientCompany']);
        });
    }

    /** @param array<string, mixed> $data */
    public function applyPayment(ClientInvoice $invoice, array $data, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $workspace): ClientInvoicePayment {
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status === 'void') {
                throw new DomainException('A payment cannot be applied to a void invoice.');
            }
            if ($locked->status === 'draft') {
                throw new DomainException('A draft invoice must be issued before accepting payment.');
            }

            $currency = MoneyService::currency($data['currency'] ?? null);
            if ($currency !== $locked->currency) {
                throw new DomainException('Payment currency must match the invoice currency.');
            }

            $amount = MoneyService::nonNegativeInteger($data['amount'] ?? null, 'amount');
            if ($amount === 0) {
                throw new DomainException('Payment amount must be greater than zero.');
            }
            if (($data['status'] ?? 'succeeded') === 'succeeded' && $amount > $locked->balance_amount) {
                throw new DomainException('Payment cannot exceed the invoice balance.');
            }

            $key = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;
            if ($key !== null && $key !== '') {
                $existing = ClientInvoicePayment::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing !== null) {
                    if ($existing->client_invoice_id !== $locked->id
                        || $existing->amount !== $amount
                        || $existing->currency !== $currency
                        || $existing->method !== ($data['method'] ?? null)
                        || $existing->status !== ($data['status'] ?? 'succeeded')) {
                        throw new DomainException('The idempotency key is already bound to a different payment.');
                    }

                    return $existing;
                }
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'status' => $data['status'] ?? 'succeeded',
                'amount' => $amount,
                'refunded_amount' => 0,
                'currency' => $currency,
                'received_on' => $data['received_on'] ?? $this->clock->today($locked->workspace)->toDateString(),
                'method' => $this->requiredString($data['method'] ?? null, 'method'),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'provider' => $data['provider'] ?? null,
                'provider_payment_identifier' => $data['provider_payment_identifier'] ?? null,
                'external_finance_transaction_uuid' => $data['external_finance_transaction_uuid'] ?? null,
                'idempotency_key' => $key,
            ]);

            $previousInvoiceStatus = $locked->status;
            $this->refreshStatus($locked);
            if ($payment->status === 'succeeded') {
                $this->recordPaymentActivity($locked, $payment, 'invoice.payment_received');
                $this->recordMarkedPaid($locked, $previousInvoiceStatus, $payment->public_id);
            }

            return $payment->fresh();
        });
    }

    public function setPaymentStatus(ClientInvoicePayment $payment, string $status, ?Workspace $workspace = null): ClientInvoicePayment
    {
        if (! in_array($status, ['pending', 'succeeded', 'failed', 'refunded', 'disputed', 'canceled'], true)) {
            throw new DomainException('Unsupported payment status.');
        }

        return DB::transaction(function () use ($payment, $status, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }
            $lockedPayment = $query->firstOrFail();
            $invoice = $this->lockInvoice($lockedPayment->invoice, $workspace);
            if ($lockedPayment->status === $status) {
                return $lockedPayment;
            }
            if ($status === 'succeeded' && $invoice->status === 'void') {
                throw new DomainException('A payment succeeded against a void invoice; refund it or un-void the invoice before recording it.');
            }
            if ($status === 'succeeded') {
                $otherPaid = (int) $invoice->payments()
                    ->where('id', '!=', $lockedPayment->id)
                    ->where('status', 'succeeded')
                    ->get(['amount', 'refunded_amount'])
                    ->sum(fn (ClientInvoicePayment $other): int => max(0, $other->amount - $other->refunded_amount));
                if (($lockedPayment->amount - $lockedPayment->refunded_amount) > ($invoice->total_amount - $otherPaid)) {
                    throw new DomainException('Successful payments cannot exceed the invoice total.');
                }
            }
            if ($status === 'refunded') {
                $this->assertReconciliationCapacity($lockedPayment, $lockedPayment->amount);
            }
            $previousInvoiceStatus = $invoice->status;
            $lockedPayment->forceFill([
                'status' => $status,
                'refunded_amount' => $status === 'refunded'
                    ? $lockedPayment->amount
                    : $lockedPayment->refunded_amount,
            ])->save();
            $this->refreshStatus($invoice);
            $action = match ($status) {
                'succeeded' => 'invoice.payment_received',
                'failed' => 'invoice.payment_failed',
                'canceled' => 'invoice.payment_canceled',
                'disputed' => 'invoice.payment_disputed',
                'refunded' => 'invoice.payment_refunded',
                default => null,
            };
            if ($action !== null) {
                $this->recordPaymentActivity($invoice, $lockedPayment, $action, (string) Str::uuid());
            }
            if ($status === 'succeeded') {
                $this->recordMarkedPaid($invoice, $previousInvoiceStatus, (string) Str::uuid());
            }

            return $lockedPayment->fresh();
        });
    }

    public function setRefundedAmount(ClientInvoicePayment $payment, int $amount, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($payment, $amount, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }

            $lockedPayment = $query->firstOrFail();
            if (! in_array($lockedPayment->status, ['succeeded', 'refunded'], true)) {
                throw new DomainException('Only a successful payment can be refunded.');
            }
            if ($amount < 0 || $amount > $lockedPayment->amount) {
                throw new DomainException('Refunded amount must be between zero and the payment amount.');
            }
            if ($amount === $lockedPayment->refunded_amount) {
                return $lockedPayment;
            }
            $this->assertReconciliationCapacity($lockedPayment, $amount);

            $invoice = $this->lockInvoice($lockedPayment->invoice, $workspace);
            $previousAmount = $lockedPayment->refunded_amount;
            $lockedPayment->forceFill([
                'refunded_amount' => $amount,
                'status' => $amount === $lockedPayment->amount ? 'refunded' : 'succeeded',
            ])->save();
            $this->refreshStatus($invoice);
            $this->recordPaymentActivity(
                $invoice,
                $lockedPayment,
                'invoice.payment_refunded',
                (string) Str::uuid(),
                ['previous_refunded_amount' => $previousAmount],
            );

            return $lockedPayment->fresh();
        });
    }

    public function refreshStatus(ClientInvoice $invoice): ClientInvoice
    {
        $paid = (int) $invoice->payments()
            ->where('status', 'succeeded')
            ->get(['amount', 'refunded_amount'])
            ->sum(fn (ClientInvoicePayment $payment): int => max(0, $payment->amount - $payment->refunded_amount));
        $paid = min($paid, (int) $invoice->total_amount);
        $balance = max(0, (int) $invoice->total_amount - $paid);
        $status = $invoice->status === 'void'
            ? 'void'
            : ($paid >= (int) $invoice->total_amount ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued'));

        $invoice->forceFill([
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'status' => $status,
        ])->save();

        return $invoice->fresh();
    }

    public function assertTenant(Workspace $workspace, ClientInvoice $invoice): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $invoice);
    }

    /** @param array<string, mixed> $line */
    public static function lineTotal(array $line, ?int $subtotalOverride = null): int
    {
        $totals = MoneyService::invoiceTotals([$line], $subtotalOverride === null ? [] : [0 => $subtotalOverride]);

        return $totals['subtotal_amount'] + $totals['tax_amount'];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     */
    private function createLines(ClientInvoice $invoice, Workspace $workspace, array $lines, array $subtotalOverrides): void
    {
        foreach ($lines as $index => $line) {
            $lineTotal = self::lineTotal($line, $subtotalOverrides[$index] ?? null);
            $invoice->lines()->create([
                'workspace_id' => $workspace->id,
                'client_project_id' => $line['client_project_id'] ?? null,
                'type' => $this->requiredString($line['type'] ?? null, 'line type'),
                'description' => $this->requiredString($line['description'] ?? null, 'line description'),
                'quantity' => $line['quantity'],
                'unit_amount' => MoneyService::nonNegativeInteger($line['unit_amount'] ?? null, 'unit_amount'),
                'tax_amount' => MoneyService::nonNegativeInteger($line['tax_amount'] ?? 0, 'tax_amount'),
                'total_amount' => $lineTotal,
                'sort_order' => $this->nonNegativeSortOrder($line['sort_order'] ?? 0),
            ]);
        }
    }

    private function releaseAllocations(ClientInvoice $invoice): void
    {
        // The invoice's own workspace, named on every statement below rather
        // than trusted to the ids. A line id, a pivot row and a task id are all
        // globally unique, so each of these predicates selects the right rows
        // without it - and each of them is also a statement this repository
        // requires to say which tenant it is addressing, because the id being
        // unique is a property of today's data and not of the SQL.
        $workspaceId = $invoice->workspace_id;

        // Refusing is the point of the predicates, not a side effect of them.
        //
        // This repository accommodates pre-composite-key tenant chains, so a
        // legacy invoice can carry a line, a pivot row or a milestone claim
        // stamped with another workspace. Scoping the releases below without
        // this would quietly *skip* such a row: the invoice would still be
        // voided, and the time entry it held would stay `invoiced` and the
        // milestone stay claimed - unbillable from then on, with nothing said.
        // A silent under-release is no better than the unscoped write it
        // replaced, and worse than stopping.
        //
        // Same three checks, in the same order, as
        // `InvoiceLineComposer::resetSystemGeneratedLines()`, which reached
        // this conclusion first for the regeneration path.
        $invoice->assertLineOwnership();
        $lineIds = $invoice->lines()->where('workspace_id', $workspaceId)->pluck('id');
        if ($lineIds->isEmpty()) {
            return;
        }

        $hasForeignPivots = DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', $lineIds)
            ->where(fn ($query) => $query
                ->whereNull('workspace_id')
                ->orWhere('workspace_id', '!=', $workspaceId))
            ->exists();
        if ($hasForeignPivots) {
            throw new RuntimeException('The invoice contains a time allocation owned by another workspace.');
        }

        $hasForeignTasks = ClientTask::query()
            ->whereIn('client_invoice_line_id', $lineIds)
            ->where(fn ($query) => $query
                ->whereNull('workspace_id')
                ->orWhere('workspace_id', '!=', $workspaceId))
            ->exists();
        if ($hasForeignTasks) {
            throw new RuntimeException('The invoice contains a milestone allocation owned by another workspace.');
        }
        $entryIds = DB::table('client_invoice_line_time_entries')
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_invoice_line_id', $lineIds)
            ->pluck('client_time_entry_id');
        if ($entryIds->isNotEmpty()) {
            ClientTimeEntry::query()
                ->whereIn('id', $entryIds)
                ->where('workspace_id', $workspaceId)
                ->tap(Locks::forUpdate())
                ->get();
            ClientTimeEntry::query()
                ->whereIn('id', $entryIds)
                ->where('workspace_id', $workspaceId)
                ->where('status', 'invoiced')
                ->update([
                    'status' => 'approved',
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);
        }
        DB::table('client_invoice_line_time_entries')
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_invoice_line_id', $lineIds)
            ->delete();

        // A milestone's claim is a column on the task, not a pivot row, so it
        // survives everything above. Left set, the task stays attached to a void
        // invoice and the generator - which only picks up unclaimed tasks - omits
        // the milestone from the replacement invoice permanently.
        DB::table('client_tasks')
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_invoice_line_id', $lineIds)
            ->update(['client_invoice_line_id' => null]);
    }

    private function lockInvoice(ClientInvoice $invoice, ?Workspace $workspace): ClientInvoice
    {
        $query = ClientInvoice::query()->whereKey($invoice->id)->tap(Locks::forUpdate());
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        return $query->firstOrFail();
    }

    /** @param array<string, int|string|null> $extra */
    private function recordPaymentActivity(
        ClientInvoice $invoice,
        ClientInvoicePayment $payment,
        string $action,
        ?string $occurrence = null,
        array $extra = [],
    ): void {
        $this->activities->record(
            $invoice->workspace,
            $invoice->clientCompany,
            $action,
            $payment,
            [
                'amount' => $payment->amount,
                'refunded_amount' => $payment->refunded_amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'status' => $payment->status,
                ...$extra,
            ],
            occurrence: $occurrence,
        );
    }

    private function recordMarkedPaid(ClientInvoice $invoice, string $previousStatus, string $occurrence): void
    {
        if ($previousStatus === 'paid' || $invoice->status !== 'paid') {
            return;
        }

        $this->activities->record(
            $invoice->workspace,
            $invoice->clientCompany,
            'invoice.marked_paid',
            $invoice,
            ['total_amount' => $invoice->total_amount, 'currency' => $invoice->currency],
            occurrence: $occurrence,
        );
    }

    private function assertCompanyTenant(Workspace $workspace, ClientCompany $company): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $company);
    }

    private function requiredString(mixed $value, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("{$name} is required.");
        }

        return trim($value);
    }

    private function nonNegativeSortOrder(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new DomainException('sort_order must be a non-negative integer.');
    }

    private function assertReconciliationCapacity(ClientInvoicePayment $payment, int $refundedAmount): void
    {
        $activeAllocated = (int) $payment->reconciliations()
            ->where('is_active', true)
            ->sum('allocated_amount');
        $netAmount = max(0, $payment->amount - $refundedAmount);

        if ($activeAllocated > $netAmount) {
            throw new DomainException('Refunding this payment would exceed its active finance reconciliation allocations.');
        }
    }
}
