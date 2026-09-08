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
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PaymentDateBounds;
use App\Support\Billing\ServicePeriodRequirement;
use App\Support\Concurrency\LockResource;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class InvoiceLifecycleService
{
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
            app(ExpenseInvoiceAllocations::class)->assertReplaceable($locked);
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
                throw new DomainException($this->reversedPeriodRefusal($locked));
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
    /**
     * How an operator actually repairs a refused draft.
     *
     * Every word of this is constrained by what the application can do, because
     * the first version of this guard shipped "Give it a service period start
     * and end." and {@see self::updateDraft()} accepts currency, totals, due
     * date, notes and lines only - it can set neither boundary nor the kind.
     * The replacement then named an "audited administrative repair path", which
     * was worse: no such thing exists. Generation does rewrite both boundaries
     * on a draft it is refreshing - `InterimOverageGenerator` updates them in
     * place - but there is no **operator-facing** operation that repairs an
     * existing invoice's period or kind. `updateDraft()` is the one an operator
     * has, and it touches neither.
     *
     * So the only true instruction is to replace the row, and which replacement
     * is possible depends on the link: `StoreInvoiceRequest` accepts both
     * boundaries but neither `client_billing_schedule_id` nor
     * `client_agreement_id`, so a schedule-linked draft cannot be recreated by
     * hand without losing the link that makes it the schedule's.
     */
    private function repairPath(ClientInvoice $invoice): string
    {
        // The origin test is agreement *and* schedule, not schedule alone. An
        // earlier revision read "no schedule link" as "manually created", which
        // is wrong in this system: neither `ClientInvoicingService` nor
        // `InterimOverageGenerator` ever writes `client_billing_schedule_id`,
        // so an ordinary generated cadence or interim draft has none. Telling
        // an operator to recreate one of those through the invoice create
        // endpoint produces an unlinked `ad_hoc` row with no agreement - and
        // the cadence overlap guard deliberately excludes ad hoc, so the real
        // cadence invoice can then be generated beside it. Copy the generated
        // retainer lines across, as "create it again" invites, and the client
        // pays for them twice.
        $manuallyCreated = InvoiceKind::tryFrom((string) $invoice->invoice_kind) === InvoiceKind::AdHoc
            && $invoice->client_billing_schedule_id === null
            && $invoice->client_agreement_id === null;

        if ($manuallyCreated) {
            return 'Discard this draft and create it again with a complete service period - the invoice '
                .'create endpoint accepts both boundaries.';
        }

        return 'This draft was generated, or carries a classification this application did not assign, so '
            .'manual invoice creation cannot repair it: that path names no agreement, no billing schedule '
            .'and no kind, and would replace a cadence or interim invoice with an unlinked ad-hoc one that '
            .'the overlap guards ignore. No general operator-facing operation changes an '
            .'existing invoice\'s service period or kind. Do not issue it, and do not discard it merely to retry billing - an exact void is '
            .'read as a deliberate waiver of its period. Establish the intended replacement path first.';
    }

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
            .$this->repairPath($invoice);
    }

    private function unsupportedKindRefusal(ClientInvoice $invoice): string
    {
        return 'This invoice carries an unrecognised invoice kind ('.(string) $invoice->invoice_kind.'), '
            .'so it cannot be issued. The model reads an unrecognised kind as a cadence invoice while the '
            .'raw-column guards do not, so an issued one is invisible to the check that stops a later '
            .'correction selling the same retainer and recurring items a second time. '
            .$this->repairPath($invoice);
    }

    private function reversedPeriodRefusal(ClientInvoice $invoice): string
    {
        return 'The service period start cannot follow the service period end, so this invoice cannot be '
            .'issued. A reversed span is placed by no period guard - it fails the overlap test for every '
            .'period, including the ones on either side of it - so ordinary invoices can be generated '
            .'beside it for the work it already charged. '
            .$this->repairPath($invoice);
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

            // Before the pending check, because it is the same question asked
            // of a row that cannot answer it. The check below is a positive
            // filter, so a payment whose status this application cannot read is
            // not seen as pending and does not block the void - and an
            // in-flight payment is exactly what that guard exists to catch.
            //
            // Scoped to the invoice's own workspace. `payments()` is keyed on
            // `client_invoice_id` alone, and `workspace_id` on the payment is
            // unconstrained lineage that a legacy or repaired row can point
            // elsewhere; without this, another tenant's payment could both
            // block this void and have its public id read back in the refusal.
            $unreadablePayment = $locked->payments()
                ->where('workspace_id', $locked->workspace_id)
                ->get(['public_id', 'status'])
                ->first(fn (ClientInvoicePayment $payment): bool => $payment->hasUnreadableStatus());
            if ($unreadablePayment !== null) {
                throw new DomainException(
                    'Payment '.(string) $unreadablePayment->public_id.' carries the unrecognised status "'
                    .(string) $unreadablePayment->status.'", so whether money is in flight against this '
                    .'invoice cannot be established and it must not be voided. Classify or correct that '
                    .'payment status first.'
                );
            }

            $hasPendingPayments = $locked->payments()
                ->where('workspace_id', $locked->workspace_id)
                ->where('status', InvoicePaymentStatus::Pending->value)
                ->exists();
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

            // Parsed, not compared. Every check below asks whether this
            // payment succeeded, and each one written as `=== 'succeeded'`
            // answers no for a value it simply cannot read - so an
            // unrecognised status skipped the over-balance check, was stored
            // verbatim, and was then read as contributing nothing by
            // refreshStatus(). The web layer constrained the value; this
            // service and svc:billing:payment did not.
            $status = $this->paymentStatus($data['status'] ?? null);

            // Bounded here rather than only at the HTTP door, for the same
            // reason the status is: `svc:billing:payment`, an import and a
            // hand-repair never cross that door. Omitted still means today on
            // this workspace's calendar, which is what every caller relied on.
            //
            // Shape now, window later. Whether this is a calendar date is a
            // fact about the string and has to be settled before it can be
            // compared with anything; whether it may be *recorded* is a fact
            // about the clock, and asking it here would make an idempotent
            // retry expire. A payment dated on the floor is below the floor
            // tomorrow, so the same key with the same payload would be refused
            // as too old before the row it matches was ever looked for - a
            // lost-response retry that stops being safe because the workspace
            // date advanced. {@see PaymentDateBounds::assertWithin()} is
            // therefore asked once, below, where a new payment is created.
            //
            // Whether the caller *said* a date is kept too, because it is a
            // different question from what the date is, and only the
            // idempotency check needs to tell them apart.
            $bounds = PaymentDateBounds::asOf($this->clock->today($locked->workspace));
            $claimsDate = ($data['received_on'] ?? null) !== null;
            $receivedOn = $claimsDate
                ? PaymentDateBounds::calendarDate($data['received_on'])
                : $bounds->latest();

            $key = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;
            if ($key !== null && $key !== '') {
                $existing = ClientInvoicePayment::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing !== null) {
                    // The date is one of the fields that define this payment,
                    // so a key reused with a different one is a different
                    // payment and is refused like any other mismatch. The
                    // sequence that makes this matter is the one this field
                    // creates: a response is lost, the operator notices the
                    // date was wrong, corrects it and retries with the same
                    // key - and without this they are told it succeeded while
                    // the original reconciliation date stands.
                    //
                    // Only when the caller named a date, though. `received_on`
                    // is optional and defaults to today on the workspace's
                    // calendar, so a caller who omits it is making no claim
                    // about the date at all - the value in the row is this
                    // service's own earlier choice. Comparing the fallback
                    // would make the guard fire on the clock rather than on
                    // anything the caller varied: an identical retry that
                    // crosses the workspace's midnight, or a replay run the
                    // next day, computes a later today and would be refused as
                    // "a different payment" when nothing about the request
                    // differs. Compared against the parsed value rather than
                    // the raw field, so a date that only differs in its
                    // spelling is still the same date.
                    if ($existing->client_invoice_id !== $locked->id
                        || $existing->amount !== $amount
                        || $existing->currency !== $currency
                        || $existing->method !== ($data['method'] ?? null)
                        || $existing->status !== $status->value
                        || ($claimsDate && $existing->received_on?->toDateString() !== $receivedOn)) {
                        throw new DomainException('The idempotency key is already bound to a different payment.');
                    }

                    return $existing;
                }
            }

            // Nothing above this line has written anything, and everything
            // below creates a payment - which is exactly the boundary the two
            // state-dependent refusals belong on. A date the caller never named
            // is today by construction and passes trivially; one they did is
            // measured against the window as it stands at the moment the row is
            // made.
            $bounds->assertWithin($receivedOn);

            // And the balance, for the same reason and more sharply. This is
            // measured against a figure the first execution of *this same
            // request* moves: a payment for the whole balance leaves nothing
            // owed, so retrying it with its own key was refused outright with
            // "Payment cannot exceed the invoice balance" before the row it
            // matches was ever looked for. The larger the payment the less
            // idempotent it was, and a payment settling an invoice in full is
            // the ordinary case rather than an edge one.
            if ($status === InvoicePaymentStatus::Succeeded && $amount > $locked->balance_amount) {
                throw new DomainException('Payment cannot exceed the invoice balance.');
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'status' => $status->value,
                'amount' => $amount,
                'refunded_amount' => 0,
                'currency' => $currency,
                'received_on' => $receivedOn,
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
            if ($status === InvoicePaymentStatus::Succeeded) {
                $this->recordPaymentActivity($locked, $payment, 'invoice.payment_received');
                $this->recordMarkedPaid($locked, $previousInvoiceStatus, $payment->public_id);
            }

            return ClientInvoicePayment::query()->where('workspace_id', $payment->workspace_id)->whereKey($payment->id)->firstOrFail();
        });
    }

    /**
     * Parse a proposed payment status, refusing one this application cannot read.
     *
     * Null means succeeded, which is the default every caller relied on when
     * this was `$data['status'] ?? 'succeeded'`. An empty string does not: it
     * is a value someone supplied, and it is not one of the six.
     */
    private function paymentStatus(mixed $raw): InvoicePaymentStatus
    {
        if ($raw === null) {
            return InvoicePaymentStatus::Succeeded;
        }

        $status = is_string($raw) ? InvoicePaymentStatus::tryFrom($raw) : null;

        if ($status === null) {
            throw new DomainException(
                'Unsupported payment status "'.(is_string($raw) ? $raw : get_debug_type($raw)).'". A payment '
                .'must carry one of: '.implode(', ', InvoicePaymentStatus::all())
                .'. Nothing has been changed.'
            );
        }

        return $status;
    }

    /**
     * The day a payment arrived, bounded by the workspace's own calendar.
     *
     * Omitted means today, which is the default every caller relied on when
     * this was `$data['received_on'] ?? $this->clock->today(...)`. Supplied, it
     * must be a real `Y-m-d` date inside the window
     * {@see PaymentDateBounds} draws - and a date before the invoice's
     * `issue_date` is deliberately *not* refused here: a deposit or an advance
     * retainer applied to an invoice issued afterwards is a real arrangement,
     * so the screens warn about one and the write succeeds.
     */
    private function receivedOn(mixed $raw, Workspace|string|null $workspace): string
    {
        $bounds = PaymentDateBounds::asOf($this->clock->today($workspace));

        // Both halves together, unlike {@see self::applyPayment()}: a
        // correction neither creates a row nor matches an existing one, so
        // there is no moment between the two questions for the clock to move
        // through.
        return $raw === null ? $bounds->latest() : $bounds->parse($raw);
    }

    public function setPaymentStatus(ClientInvoicePayment $payment, string $status, ?Workspace $workspace = null): ClientInvoicePayment
    {
        $next = $this->paymentStatus($status);

        return DB::transaction(function () use ($payment, $next, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->where('workspace_id', $payment->workspace_id)->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }
            $lockedPayment = $query->firstOrFail();
            $invoice = ClientInvoice::query()
                ->where('workspace_id', $lockedPayment->workspace_id)
                ->whereKey($lockedPayment->client_invoice_id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($lockedPayment->status === $next->value) {
                return $lockedPayment;
            }
            if ($next === InvoicePaymentStatus::Succeeded && $invoice->status === 'void') {
                throw new DomainException('A payment succeeded against a void invoice; refund it or un-void the invoice before recording it.');
            }
            if ($next === InvoicePaymentStatus::Succeeded) {
                // A positive filter, so another row of an unreadable status is
                // not counted here and this check can pass on an understated
                // total. `refreshStatus()` below is the backstop: it refuses to
                // total a set containing one and rolls this transaction back.
                $otherPaid = (int) $invoice->payments()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->where('id', '!=', $lockedPayment->id)
                    ->where('status', InvoicePaymentStatus::Succeeded->value)
                    ->get(['amount', 'refunded_amount'])
                    ->sum(fn (ClientInvoicePayment $other): int => max(0, $other->amount - $other->refunded_amount));
                if (($lockedPayment->amount - $lockedPayment->refunded_amount) > ($invoice->total_amount - $otherPaid)) {
                    throw new DomainException('Successful payments cannot exceed the invoice total.');
                }
            }
            if ($next === InvoicePaymentStatus::Refunded) {
                $this->assertReconciliationCapacity($lockedPayment, $lockedPayment->amount);
            }
            $previousInvoiceStatus = $invoice->status;
            $lockedPayment->forceFill([
                'status' => $next->value,
                'refunded_amount' => $next === InvoicePaymentStatus::Refunded
                    ? $lockedPayment->amount
                    : $lockedPayment->refunded_amount,
            ])->save();

            // After the write, deliberately. This is the one operation that can
            // *repair* an unreadable payment status, and refreshStatus() refuses
            // to recompute a balance over one - so checking before the save
            // would make the inconsistency permanent, unfixable by the only
            // operation that addresses it. Any *other* payment still carrying an
            // unknown status does roll this back, which is the intended answer:
            // one row at a time is a repair, two is a balance nobody can total.
            $this->refreshStatus($invoice);

            $action = match ($next) {
                InvoicePaymentStatus::Succeeded => 'invoice.payment_received',
                InvoicePaymentStatus::Failed => 'invoice.payment_failed',
                InvoicePaymentStatus::Canceled => 'invoice.payment_canceled',
                InvoicePaymentStatus::Disputed => 'invoice.payment_disputed',
                InvoicePaymentStatus::Refunded => 'invoice.payment_refunded',
                InvoicePaymentStatus::Pending => null,
            };
            if ($action !== null) {
                $this->recordPaymentActivity($invoice, $lockedPayment, $action, (string) Str::uuid());
            }
            if ($next === InvoicePaymentStatus::Succeeded) {
                $this->recordMarkedPaid($invoice, $previousInvoiceStatus, (string) Str::uuid());
            }

            return ClientInvoicePayment::query()->where('workspace_id', $lockedPayment->workspace_id)->whereKey($lockedPayment->id)->firstOrFail();
        });
    }

    /**
     * Correct the date on an existing payment, and nothing else.
     *
     * There is deliberately no payment-edit path in this application: a payment
     * is corrected by transitioning its status or its refunded amount, so the
     * history is preserved rather than rewritten. This does not weaken that,
     * because a mistyped date is not a money correction. It moves no amount,
     * changes no status, and cannot move an invoice's balance -
     * {@see self::refreshStatus()} never reads this column. The only remedy for
     * one today is to cancel the payment and record it again, which invents a
     * cancellation that never happened and leaves it in the client's history.
     *
     * So the operation is constrained to the one column. Amount, currency,
     * method, status and refunded amount are untouchable through here, the
     * bound is the same one {@see self::applyPayment()} applies on the way in,
     * and the write is scoped to the workspace like every other.
     *
     * The shape follows {@see self::setPaymentStatus()} and
     * {@see self::setRefundedAmount()}, which are the comparable corrections:
     * the payment row is locked first and the invoice through it, in the order
     * {@see LockResource} declares, and the change
     * is recorded as a `ClientCompanyActivity` carrying the date it replaced,
     * with a fresh occurrence so each correction is its own event rather than a
     * deduplicated repeat of the last one. Where the two left a choice, the
     * conservative reading was taken: this column does not need the invoice
     * lock, and it is taken anyway, so a correction cannot interleave with an
     * operation already rewriting that invoice's payments.
     *
     * `refreshStatus()` is deliberately not called. It is not an input to this
     * column, and it refuses to recompute an invoice carrying any payment of an
     * unreadable status - so calling it here would make correcting a date fail
     * because of an unrelated row, which is the one thing a repair must not do.
     */
    public function setPaymentReceivedOn(ClientInvoicePayment $payment, string $receivedOn, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($payment, $receivedOn, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->where('workspace_id', $payment->workspace_id)->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }
            $lockedPayment = $query->firstOrFail();
            // Resolved and locked in one scoped query, rather than read through
            // `$lockedPayment->invoice` and then locked. The relation is a
            // `belongsTo` on `client_invoice_id` alone, so the read that finds
            // the invoice is bounded by a child key and nothing else - and a
            // row migrated in from before #113's composite tenant keys can name
            // an invoice in another workspace. Locking it afterwards makes the
            // *lock* scoped and leaves the read that produced the model
            // unscoped, which is a tenant-owned query without a tenant in it.
            //
            // Bounded by the payment's own workspace rather than the caller's:
            // that is the stronger of the two, because the payment was already
            // constrained to the caller's workspace above where one was given,
            // and it is the only bound available where one was not - the
            // console command passes none.
            //
            // `firstOrFail()` rather than a message: an invoice that is not
            // this payment's tenant's is not found, which is the answer a
            // cross-tenant reach should get, and it is what the sibling
            // corrections' `lockInvoice()` already answers.
            $invoice = ClientInvoice::query()
                ->whereKey($lockedPayment->client_invoice_id)
                ->where('workspace_id', $lockedPayment->workspace_id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            // The invoice's own workspace, not the caller's: the bound is a
            // statement about which day it is where this money was received.
            $next = $this->receivedOn($receivedOn, $invoice->workspace);
            $previous = $lockedPayment->received_on?->toDateString();
            if ($previous === $next) {
                return $lockedPayment;
            }

            $lockedPayment->forceFill(['received_on' => $next])->save();
            $this->recordPaymentActivity(
                $invoice,
                $lockedPayment,
                'invoice.payment_date_corrected',
                (string) Str::uuid(),
                ['previous_received_on' => $previous, 'received_on' => $next],
            );

            // The row this transaction locked, wrote and still holds, rather
            // than `refresh()`. That re-reads by primary key alone, which is a
            // tenant-owned query with no tenant in it - harmless in itself,
            // since a primary key cannot reach another workspace's row, but it
            // is a shape the query-shape guard would have to carve an exception
            // for and the next reader would copy. There is also nothing to
            // re-read: the save above is the only write to this row.
            return $lockedPayment;
        });
    }

    public function setRefundedAmount(ClientInvoicePayment $payment, int $amount, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($payment, $amount, $workspace): ClientInvoicePayment {
            $query = ClientInvoicePayment::query()->where('workspace_id', $payment->workspace_id)->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }

            $lockedPayment = $query->firstOrFail();
            // Fail-closed already: an unreadable status matches neither case
            // and is refused, which is the right answer - a row nobody can read
            // cannot be shown to hold money there is anything to refund.
            $refundable = match (InvoicePaymentStatus::tryFrom((string) $lockedPayment->status)) {
                InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Refunded => true,
                InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed,
                InvoicePaymentStatus::Disputed, InvoicePaymentStatus::Canceled, null => false,
            };
            if (! $refundable) {
                throw new DomainException('Only a successful payment can be refunded.');
            }
            if ($amount < 0 || $amount > $lockedPayment->amount) {
                throw new DomainException('Refunded amount must be between zero and the payment amount.');
            }
            if ($amount === $lockedPayment->refunded_amount) {
                return $lockedPayment;
            }
            $this->assertReconciliationCapacity($lockedPayment, $amount);

            $invoice = ClientInvoice::query()
                ->where('workspace_id', $lockedPayment->workspace_id)
                ->whereKey($lockedPayment->client_invoice_id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            $previousAmount = $lockedPayment->refunded_amount;
            $lockedPayment->forceFill([
                'refunded_amount' => $amount,
                'status' => $amount === $lockedPayment->amount
                    ? InvoicePaymentStatus::Refunded->value
                    : InvoicePaymentStatus::Succeeded->value,
            ])->save();
            $this->refreshStatus($invoice);
            $this->recordPaymentActivity(
                $invoice,
                $lockedPayment,
                'invoice.payment_refunded',
                (string) Str::uuid(),
                ['previous_refunded_amount' => $previousAmount],
            );

            return ClientInvoicePayment::query()->where('workspace_id', $lockedPayment->workspace_id)->whereKey($lockedPayment->id)->firstOrFail();
        });
    }

    /**
     * Recompute a live invoice's paid and balance amounts from its payments.
     *
     * **Not a way into `issued`, and not a way to normalise a status.** The
     * derivation below answers `issued` for any non-void invoice with no
     * succeeded payment, so it rewrites whatever it is handed. Two shapes must
     * never reach it.
     *
     * A **draft** would be promoted straight past {@see self::issue()} - past
     * the period and kind invariants, and past the issue date, visibility and
     * activity that make an issued invoice legible - into a status
     * `InvoiceStatus::collectible()` accepts, so `InvoiceEmailService` would
     * send a malformed invoice to the client. {@see self::applyPayment()}
     * refuses to attach a payment to a draft, so this is reached through a
     * payment that already sits on one, by way of
     * {@see self::setPaymentStatus()} or {@see self::setRefundedAmount()}.
     *
     * An **unrecognised status** would be silently rewritten to one of today's
     * four outcomes. `InvoiceStatus::isSettledValue()` and `hasChargedValue()`
     * both read an unknown status as settled and charged, precisely because
     * code that cannot interpret a state must not act on it - and rewriting it
     * is the strongest action available. `awaiting_dispute_resolution` becoming
     * `issued` is the same class of defect as the draft case, arrived at from
     * the other side.
     *
     * A third shape is refused inside the derivation rather than at the door.
     * The paid amount is a *positive* filter over succeeded payments, so a
     * payment row carrying an unreadable status contributes nothing and the
     * invoice comes back owing its full balance with that payment already
     * recorded against it - money received, invisible, and collectible a
     * second time. Same reasoning as the invoice status above, one table down,
     * and failing the other way: there the danger is rewriting a state, here
     * it is silently valuing one at zero.
     *
     * Production carries no payment against a draft, no invoice of an
     * unrecognised status, and no payment of an unrecognised status - all 16
     * are succeeded - so none of the three refusals costs anything to adopt.
     *
     * Private, because every caller is in this class and the transition it
     * guards belongs to `issue()`.
     */
    private function refreshStatus(ClientInvoice $invoice): ClientInvoice
    {
        // Exhaustive, with no `default`. A sixth status added to
        // `InvoiceStatus` has to answer this question rather than inheriting
        // whichever of the four outcomes the arithmetic below happens to reach.
        match (InvoiceStatus::tryFrom((string) $invoice->status)) {
            InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Void => null,
            InvoiceStatus::Draft => throw new DomainException(
                'A payment is attached to a draft invoice, which is an inconsistent state: refreshing its '
                .'payment status would move it to issued without the checks issue() performs. Neither the '
                .'invoice nor the payment has been changed. Resolve that pair before continuing.'
            ),
            null => throw new DomainException(
                'Invoice '.(string) $invoice->invoice_number.' carries the unrecognised status "'
                .(string) $invoice->status.'", so whether it has already charged this client cannot be '
                .'established and its payment state must not be recomputed - doing so would rewrite that '
                .'status to one this application does read. Neither the invoice nor the payment has been '
                .'changed. Classify or correct that status first.'
            ),
        };

        // Every payment, not just the succeeded ones. The sum below is a
        // positive filter, so a row carrying a status this application cannot
        // read silently contributes nothing - and "contributed nothing" is a
        // claim about money that an uninterpretable state cannot support. The
        // invoice would stay `issued` for its full balance with the payment
        // already recorded against it, and the same balance could be collected
        // twice. Validating on write does not cover this: an import, a
        // migration or a hand-repair can put such a row here without passing
        // through applyPayment().
        $paid = 0;

        foreach ($invoice->payments()->where('workspace_id', $invoice->workspace_id)->get(['public_id', 'status', 'amount', 'refunded_amount']) as $payment) {
            $paymentStatus = InvoicePaymentStatus::tryFrom((string) $payment->status);

            if ($paymentStatus === null) {
                throw new DomainException(
                    'Payment '.(string) $payment->public_id.' on invoice '.(string) $invoice->invoice_number
                    .' carries the unrecognised status "'.(string) $payment->status.'", so whether it '
                    .'contributed money cannot be established and this invoice\'s paid and balance amounts '
                    .'must not be recomputed - treating it as nothing would leave money already received '
                    .'outstanding and collectible again. Nothing has been changed. Classify or correct that '
                    .'payment status first.'
                );
            }

            if ($paymentStatus->contributesToPaidAmount()) {
                $paid += max(0, (int) $payment->amount - (int) $payment->refunded_amount);
            }
        }

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

        return ClientInvoice::query()->where('workspace_id', $invoice->workspace_id)->whereKey($invoice->id)->firstOrFail();
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

        app(ExpenseInvoiceAllocations::class)->release($invoice);
    }

    private function lockInvoice(ClientInvoice $invoice, ?Workspace $workspace): ClientInvoice
    {
        $query = ClientInvoice::query()->where('workspace_id', $invoice->workspace_id)->whereKey($invoice->id)->tap(Locks::forUpdate());
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
        $company = $this->loadOwningCompany($invoice);

        $this->activities->record(
            $invoice->workspace,
            $company,
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

        // Resolve ownership here too, independently of the preceding activity.
        $company = $this->loadOwningCompany($invoice);

        $this->activities->record(
            $invoice->workspace,
            $company,
            'invoice.marked_paid',
            $invoice,
            ['total_amount' => $invoice->total_amount, 'currency' => $invoice->currency],
            occurrence: $occurrence,
        );
    }

    /**
     * Load an invoice's company with its workspace as well as its key.
     *
     * `$invoice->clientCompany` is a `belongsTo` on `client_company_id` alone,
     * so reading it lazily selects a company by a child key and nothing else -
     * and an invoice migrated in from before #113's composite tenant keys can
     * name one in another workspace. `ClientActivityRecorder` refuses the
     * mismatch, but only once the foreign tenant's row has been read and
     * materialised, which is the same shape as an unscoped invoice read one
     * relation earlier. Constrained the way `InvoiceController::index()`
     * already constrains this relation, and for the same reason.
     *
     * Called from {@see self::recordPaymentActivity()} rather than from the
     * correction that surfaced it, because all four payment paths record
     * through there and all four read this relation the same way.
     *
     * A named guard rather than three lines inline, for the same reason
     * {@see self::assertCompanyTenant()} is one: it is the check every caller
     * needs and none of them should be restating.
     */
    private function loadOwningCompany(ClientInvoice $invoice): ClientCompany
    {
        $company = ClientCompany::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_company_id)
            ->first();

        if ($company === null) {
            throw new DomainException(
                'This invoice names a client company in another workspace, so nothing about its '
                .'payments can be recorded against a client. Nothing has been changed.'
            );
        }

        return $company;
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
            ->where('workspace_id', $payment->workspace_id)
            ->where('is_active', true)
            ->sum('allocated_amount');
        $netAmount = max(0, $payment->amount - $refundedAmount);

        if ($activeAllocated > $netAmount) {
            throw new DomainException('Refunding this payment would exceed its active finance reconciliation allocations.');
        }
    }
}
