<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Square\SquareCollectionGuard;
use App\Services\WorkspaceAuthorization;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceChangedDuringPaymentChange;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\ManualPaymentChange;
use App\Support\Billing\ManualPaymentOutcome;
use App\Support\Billing\PaymentCorrection;
use App\Support\Billing\PaymentCorrectionOutcome;
use App\Support\Billing\PaymentDateBounds;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Billing\PaymentVersionChanged;
use App\Support\Billing\ServicePeriodRequirement;
use App\Support\Concurrency\LockResource;
use App\Support\Concurrency\Locks;
use App\Support\Finance\ReconciliationReviewReason;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class InvoiceLifecycleService
{
    /**
     * Provider calls are synchronous and normally resolve within one request.
     * Keep a generous boundary around a live call, then let an operator record
     * payment while retaining the delivery row as an ambiguous audit record.
     */
    private const AMBIGUOUS_DELIVERY_CLAIM_MINUTES = 60;

    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ClientActivityRecorder $activities,
        private readonly OverpaymentCreditService $overpaymentCreditService = new OverpaymentCreditService,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
        private readonly ?InvoiceAdministratorNotificationService $administratorNotifications = null,
        private readonly DraftCatchUpDependencies $draftCatchUpDependencies = new DraftCatchUpDependencies,
        private readonly PaymentRefundLedger $refunds = new PaymentRefundLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, int>  $subtotalOverrides
     * @param  (callable(ClientInvoice): void)|null  $beforeGenerationRecorded  final composition inside the draft transaction
     */
    public function createDraft(Workspace $workspace, ClientCompany $company, array $attributes, array $lines, array $subtotalOverrides = [], ?callable $beforeGenerationRecorded = null): ClientInvoice
    {
        $this->assertCompanyTenant($workspace, $company);
        $currency = MoneyService::currency($attributes['currency'] ?? null);
        $totals = MoneyService::invoiceTotals($lines, $subtotalOverrides);

        return DB::transaction(function () use ($workspace, $company, $attributes, $lines, $subtotalOverrides, $currency, $totals, $beforeGenerationRecorded): ClientInvoice {
            BillingMaintenance::share($workspace->id);
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
                'scheduled_issue_date' => $attributes['scheduled_issue_date'] ?? null,
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
            if ($beforeGenerationRecorded !== null) {
                $beforeGenerationRecorded($invoice);
            }
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
            BillingMaintenance::share($workspace->id);
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
                // The hours statement explains the lines the generator wrote,
                // and these are about to be replaced by hand, so it is
                // withdrawn rather than left describing lines that no longer
                // exist. The agent door reaches here only for an ad-hoc draft
                // (#349); a generated one is regenerated, not edited.
                'hours_statement' => null,
            ];
            foreach (['due_date', 'scheduled_issue_date', 'notes'] as $attribute) {
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

    /**
     * Change a draft's due date, scheduled issue date or notes and nothing else.
     *
     * Any draft, generated or ad hoc: lines, time allocations and the hours
     * statement are untouched, which is what makes this safe on a generated
     * draft where {@see self::updateDraft()} is not. Regeneration rewrites a
     * generated draft's period facts and lines but never these columns, so the
     * change survives it.
     *
     * The scheduled issue date is a plan the stale-draft audit reads (#381).
     * It is not the issue date and nothing issues or sends on it, so it is not
     * held to the due date: a draft can be late on either one alone.
     *
     * The version is compared on the locked row, so a write that landed after
     * the caller's read is refused rather than overwritten.
     *
     * @param  array{due_date?: string|null, scheduled_issue_date?: string|null, notes?: string|null}  $attributes
     */
    public function updateDraftDetails(ClientInvoice $invoice, Workspace $workspace, string $expectedVersion, array $attributes): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $expectedVersion, $attributes): ClientInvoice {
            BillingMaintenance::share($workspace->id);
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status !== InvoiceStatus::Draft->value) {
                throw new DomainException('Only a draft invoice\'s details can be changed here. Correct an issued invoice instead.');
            }
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');

            $before = [
                'due_date' => $locked->due_date?->toDateString(),
                'scheduled_issue_date' => $locked->scheduled_issue_date?->toDateString(),
                'notes' => $locked->notes,
            ];
            $updates = [];
            foreach (['due_date', 'scheduled_issue_date'] as $day) {
                if (array_key_exists($day, $attributes)) {
                    $updates[$day] = $attributes[$day] === null
                        ? null
                        : CarbonImmutable::parse($attributes[$day])->toDateString();
                }
            }
            if (array_key_exists('notes', $attributes)) {
                $updates['notes'] = $attributes['notes'];
            }
            if (isset($updates['due_date']) && $locked->issue_date !== null && $updates['due_date'] < $locked->issue_date->toDateString()) {
                throw new DomainException('The due date cannot precede the issue date.');
            }
            $after = [...$before, ...$updates];
            if ($after === $before) {
                throw new DomainException('The update does not change the invoice.');
            }

            $locked->forceFill($updates)->save();
            $this->activities->record(
                $workspace,
                ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($locked->client_company_id)->firstOrFail(),
                'invoice.details_updated',
                $locked,
                [
                    'invoice_kind' => $locked->invoiceKindValue(),
                    'due_date' => ['old' => $before['due_date'], 'new' => $after['due_date']],
                    'scheduled_issue_date' => ['old' => $before['scheduled_issue_date'], 'new' => $after['scheduled_issue_date']],
                    'notes_changed' => $before['notes'] !== $after['notes'],
                ],
                occurrence: (string) Str::uuid(),
            );

            // Both relations are tenant-owned and keyed by the child alone, so
            // each is bounded by the workspace explicitly: a legacy row stamped
            // to another tenant is not loaded through this invoice.
            return $locked->refresh()->load([
                'lines' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'clientCompany' => fn ($query) => $query->where('workspace_id', $workspace->id),
            ]);
        });
    }

    public function discardDraft(ClientInvoice $invoice, Workspace $workspace, string $reason): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $reason): ClientInvoice {
            BillingMaintenance::share($workspace->id);
            $this->lockCadenceAgreement($invoice);
            $locked = $this->lockInvoice($invoice, $workspace);
            if ($locked->status !== 'draft') {
                throw new DomainException('Only a draft invoice can be discarded.');
            }
            $this->assertLockedAgreementIsTheInvoices($invoice, $locked);
            $this->draftCatchUpDependencies->assertDiscardable($locked);

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

    /**
     * @param  (callable(ClientInvoice): void)|null  $assertLocked  a caller's precondition, asked of the
     *                                                              locked row before anything else is decided
     */
    public function issue(ClientInvoice $invoice, ?Workspace $workspace = null, ?callable $assertLocked = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $assertLocked): ClientInvoice {
            BillingMaintenance::share($workspace->id ?? $invoice->workspace_id);
            // An interim overage invoice's claim is checked against the other
            // claims of its agreement's cycle, and two interim invoices of one
            // agreement must not be checked at the same time - each would pass
            // against a cycle the other is about to change. The agreement is
            // what they share, and it ranks before invoices, so it is locked
            // first: from the caller's copy of the invoice, because nothing may
            // be read or locked ahead of it, and then checked against the
            // locked row below.
            $agreement = $this->lockInterimAgreement($invoice);
            // A monthly draft recording the catch-up it was sized against is
            // checked against other invoices of its agreement, read with row
            // locks. Those are serialised on the agreement - which generation
            // and void hold while they change or read them - rather than left
            // to meet in whatever order two issues reach each other's rows.
            $basisAgreement = $invoice->catch_up_basis === null ? null : $this->lockAgreementOf($invoice);
            $locked = $this->lockInvoice($invoice, $workspace);
            // A caller's check of version or status belongs on this row, not on
            // the copy it read before asking: another request can issue the
            // invoice in between, and the charged-status return below would
            // then answer that caller as though its own transition had happened.
            // Asked before the interim-claim and credit locks: a refusal here
            // takes nothing further and releases the invoice lock with the
            // transaction.
            if ($assertLocked !== null) {
                $assertLocked($locked);
            }
            $interimClaims = null;
            // Only a draft whose claim can be placed: one with a complete,
            // ordered period. Anything else is refused by the period checks
            // below, with the refusal that names its repair.
            if ($locked->status === InvoiceStatus::Draft->value
                && $locked->invoice_kind === InvoiceKind::InterimOverage->value
                && $locked->service_period_start !== null
                && $locked->service_period_end !== null
                && ! $locked->service_period_start->gt($locked->service_period_end)) {
                // The claim, the cycle's claims and the cadence reconciliation
                // are all found by agreement, so an interim not tied to its
                // company's agreement is invisible to the real one, and the
                // closing invoice can bill its hours again (#342). The
                // generator never writes one; an import or a hand edit can.
                // Refused before the credit and company locks, which a
                // refusal has no use for.
                if ($locked->client_agreement_id === null) {
                    throw new DomainException($this->unplaceableInterimRefusal('names no agreement'));
                }
                if (! $agreement instanceof ClientAgreement) {
                    // The caller's copy named this same agreement and no row
                    // answered: it is missing, not moved, and a reload
                    // cannot change that.
                    if ((int) $invoice->client_agreement_id === (int) $locked->client_agreement_id) {
                        throw new DomainException($this->unplaceableInterimRefusal('names an agreement that does not exist in this workspace'));
                    }

                    throw new DomainException('This invoice changed after it was loaded. Reload it and issue it again.');
                }
                if ($agreement->id !== (int) $locked->client_agreement_id) {
                    throw new DomainException('This invoice changed after it was loaded. Reload it and issue it again.');
                }
                if ((int) $agreement->client_company_id !== (int) $locked->client_company_id) {
                    throw new DomainException($this->unplaceableInterimRefusal('names an agreement of a different client company'));
                }
                // The claims are invoice rows, so they are locked now, before
                // the company.
                $interimClaims = $this->interimOverageGenerator()->lockCycleClaims($locked, $agreement);
            }

            // A monthly invoice records the earlier catch-up it was sized
            // against; one of those charges moving since - an earlier draft
            // regenerated, created or voided - leaves it billing the wrong
            // debt. Measured with locking reads of other invoice rows, which
            // rank with this one and before the credit lines, and read nothing
            // that fixes the snapshot the credit check below relies on.
            if ($locked->status === InvoiceStatus::Draft->value && $locked->catch_up_basis !== null) {
                if (! $basisAgreement instanceof ClientAgreement
                    || $basisAgreement->id !== (int) $locked->client_agreement_id) {
                    throw new DomainException('This invoice changed after it was loaded. Reload it and issue it again.');
                }
                $this->draftCatchUpDependencies->assertSizedAgainstCurrentCharges($locked);
            }

            // The credit pool's lock, straight after the invoice's and before
            // this transaction's first ordinary read. Under REPEATABLE READ that
            // first ordinary read fixes the snapshot every later ordinary read
            // returns, and the credit ledger is built from ordinary reads - so
            // when the workspace read below came first, a competing issue could
            // spend the credit and commit, this one would then get the lock,
            // and still see the credit unspent (`CreditSpendConcurrencyTest`).
            // Taken here, a transaction this method owns starts its snapshot
            // after the lock. A caller's transaction that has already read
            // something has a snapshot nothing here can refresh, which is what
            // the revision check inside `lockForSpending()` refuses on.
            //
            // Only for a draft: a charged invoice returns below without
            // spending anything, and keeps its idempotent issue lock-free of
            // the company. And the revision is compared only when the draft
            // carries credit to spend - known from a locking read of its own
            // credit lines, which rank after the invoice and before the
            // company, so asking takes nothing out of order and fixes no
            // snapshot. A draft spending nothing is not refused because some
            // other invoice of the company moved the pool.
            $company = null;
            if ($locked->status === 'draft') {
                $spendsCredit = ClientInvoiceLine::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('client_invoice_id', $locked->id)
                    ->where('type', InvoiceLineType::Credit->value)
                    ->where('total_amount', '<', 0)
                    ->tap(Locks::forUpdate())
                    ->first(['id']) !== null;
                $company = $this->overpaymentCreditService->lockForSpending(
                    (int) $locked->workspace_id,
                    (int) $locked->client_company_id,
                    verifySnapshot: $spendsCredit,
                );
            }

            // Re-read even when the caller supplied the authorized workspace.
            // A freshly-created model does not contain database defaults, so a
            // workspace whose timezone comes from the schema default otherwise
            // looks null here on MariaDB.
            $owningWorkspace = Workspace::query()->whereKey($locked->workspace_id)->firstOrFail();
            $locked->setRelation('workspace', $owningWorkspace);

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

            // An invoice is issued on its issue date, not before it. The
            // cadence generators write the first day of the cycle being sold
            // and a run on 27 September creates October's draft, so without
            // this it could be issued - made client-visible, stamped and
            // scheduled for automatic delivery - days before the date printed
            // on it. Compared as calendar dates in the workspace's own zone.
            // A draft with no issue date is still issued today.
            $today = $this->clock->today($owningWorkspace);
            if ($locked->issue_date !== null && $today->toDateString() < $locked->issue_date->toDateString()) {
                throw new DomainException(sprintf(
                    'This invoice is dated %s and cannot be issued before then; it is %s in this workspace.',
                    $locked->issue_date->toDateString(),
                    $today->toDateString(),
                ));
            }

            // A monthly invoice generated while an earlier month was still a
            // draft counted that draft's catch-up as charged. Issued first, it
            // would rely on a charge that could still be discarded.
            $this->draftCatchUpDependencies->assertIssuable($locked);

            // An ad-hoc draft built from time before its lines took the
            // client's wording still prints an internal note (#347). Issuing
            // writes the wording any rebuild would, under the invoice lock.
            if ($locked->invoice_kind === InvoiceKind::AdHoc->value
                && InvoiceLineDetail::rewordLinesCarryingAnInternalNote($locked) > 0) {
                $locked->unsetRelation('lines');
            }

            $issueDate = $locked->issue_date ?? $today;
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
            // company is what the pool belongs to, so that is what serializes -
            // locked at the top of this transaction, see above.
            if (! $company instanceof ClientCompany) {
                throw new DomainException('The invoice client does not belong to this workspace.');
            }
            $locked->setRelation('clientCompany', $company);
            if ($interimClaims !== null && $agreement instanceof ClientAgreement) {
                // After every lock, so a transaction this method owns builds the
                // ledger through a snapshot that starts after all of them.
                $this->interimOverageGenerator()->assertClaimIssuable($locked, $agreement, $interimClaims[0], $interimClaims[1], $interimClaims[2]);
            }
            if ($this->capOverpaymentCreditAtIssue($locked)) {
                $this->overpaymentCreditService->recordPoolChange((int) $locked->workspace_id, (int) $company->id);
            }

            // Timestamps are persisted in UTC. Convert before Eloquent formats
            // the value (which otherwise drops the offset), then return to the
            // workspace timezone only for calendar-day arithmetic.
            $issuedAt = $this->clock->now($owningWorkspace)->utc();
            $automatic = $company->automatic_invoice_email_enabled;
            $delayDays = $automatic ? $company->automatic_invoice_email_delay_days : null;
            if ($automatic && $delayDays === null) {
                throw new DomainException('Automatic invoice delivery is enabled without a configured delay.');
            }
            // Issued, but never scheduled to the client when Square collects
            // it (#438): the books stay complete and Square does the asking.
            $squareCollected = $automatic && $this->squareCollection()->isSquareCollected($locked);

            $locked->forceFill([
                'issue_date' => $issueDate,
                'due_date' => $locked->due_date ?? $issueDate,
                'issued_at' => $issuedAt,
                'status' => 'issued',
                'document_revision' => 1,
                'is_visible_to_client' => true,
                'balance_amount' => $locked->total_amount,
                // The setting is snapped only on the first committed issue.
                // Adding calendar days to the workspace-local instant keeps
                // its wall-clock time across daylight-saving transitions.
                'automatic_delivery_status' => $automatic ? ($squareCollected ? 'cancelled' : 'scheduled') : null,
                'automatic_delivery_delay_days' => $delayDays,
                'automatic_delivery_due_at' => $automatic && ! $squareCollected
                    ? $issuedAt->setTimezone($owningWorkspace->timezone)->addDays($delayDays)->utc()
                    : null,
                ...($squareCollected ? ['automatic_delivery_note' => 'Square collects this invoice, so SVC does not email it to the client.'] : []),
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
                $owningWorkspace,
                $company,
                'invoice.issued',
                $locked,
                [
                    'invoice_kind' => $locked->invoiceKindValue(),
                    'total_amount' => $locked->total_amount,
                    'currency' => $locked->currency,
                    'automatic_delivery_status' => $locked->automatic_delivery_status,
                    'automatic_delivery_delay_days' => $locked->automatic_delivery_delay_days,
                ],
            );

            ($this->administratorNotifications ?? app(InvoiceAdministratorNotificationService::class))
                ->registerIssued($locked);

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

    private function unplaceableInterimRefusal(string $problem): string
    {
        return 'This interim_overage invoice '.$problem.', so it cannot be issued. Its claim is checked '
            .'against the other claims of its agreement\'s cycle, and it would be invisible to that check, '
            .'so the closing invoice could bill the same hours again. No operation here changes an existing '
            .'invoice\'s agreement: discard this draft. Interim invoices are raised from their agreement by '
            .'the overage generator, which bills the work under it if it is still owed.';
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
    /**
     * Lock the agreement of an interim overage draft, before its invoice.
     *
     * Read from the caller's copy rather than the database, because any read
     * here would come before the invoice lock - an ordinary one would fix this
     * transaction's snapshot too early, and a locking one on the invoice would
     * take it ahead of the agreement. `issue()` checks the locked invoice
     * against what was locked here and refuses on any difference.
     */
    /**
     * Lock the agreement of a cadence invoice, before its invoice.
     *
     * Monthly generation holds this lock while it reads which earlier drafts'
     * catch-up to count, so a draft being discarded or voided under that read
     * waits for it and then sees the later invoice it produced
     * (`DraftCatchUpDependencies::assertDiscardable()`). Read from the caller's
     * copy for the same reason as `lockInterimAgreement()`, and checked
     * against the locked invoice afterwards.
     */
    private function lockCadenceAgreement(ClientInvoice $invoice): void
    {
        if (in_array($invoice->invoice_kind, [null, InvoiceKind::CadencePeriod->value], true)) {
            $this->lockAgreementOf($invoice);
        }
    }

    /**
     * Lock an invoice's agreement, before its invoice, from the caller's copy.
     *
     * Every kind with an agreement, since the billed-overage ledger reads them
     * all: voiding an issued one asks which later invoices were sized against
     * it, and monthly generation holds this lock while it records that.
     */
    private function lockAgreementOf(ClientInvoice $invoice): ?ClientAgreement
    {
        if ($invoice->client_agreement_id === null) {
            return null;
        }

        return ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->client_agreement_id)
            ->tap(Locks::forUpdate())
            ->first();
    }

    /**
     * The agreement locked from the caller's copy is still the locked
     * invoice's: its kind and agreement are what chose the lock.
     */
    private function assertLockedAgreementIsTheInvoices(ClientInvoice $callerCopy, ClientInvoice $locked): void
    {
        $agreementOf = static fn (ClientInvoice $invoice): ?int => $invoice->client_agreement_id === null
            ? null
            : (int) $invoice->client_agreement_id;
        if ((string) $callerCopy->invoice_kind !== (string) $locked->invoice_kind
            || $agreementOf($callerCopy) !== $agreementOf($locked)) {
            throw new DomainException('This invoice changed after it was loaded. Reload it and try again.');
        }
    }

    private function lockInterimAgreement(ClientInvoice $invoice): ?ClientAgreement
    {
        return $invoice->invoice_kind === InvoiceKind::InterimOverage->value ? $this->lockAgreementOf($invoice) : null;
    }

    private function interimOverageGenerator(): InterimOverageGenerator
    {
        return app(InterimOverageGenerator::class);
    }

    private function squareCollection(): SquareCollectionGuard
    {
        return app(SquareCollectionGuard::class);
    }

    /**
     * Cap the draft's credit line at what the pool still holds.
     *
     * Returns whether the invoice leaves here still spending credit - the
     * caller then records the pool change under the company lock it holds.
     */
    private function capOverpaymentCreditAtIssue(ClientInvoice $invoice): bool
    {
        $creditLine = $invoice->lines()
            ->where('type', InvoiceLineType::Credit->value)
            ->first();

        if (! $creditLine instanceof ClientInvoiceLine) {
            return false;
        }

        $company = $invoice->clientCompany;
        if (! $company instanceof ClientCompany) {
            return false;
        }

        $applied = abs((int) $creditLine->total_amount);
        $available = (int) round($this->overpaymentCreditService
            ->availableCreditForCompany($company, (string) $invoice->currency) * 100);

        if ($applied <= $available) {
            return $applied > 0;
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

        return $available > 0;
    }

    public function void(ClientInvoice $invoice, ?Workspace $workspace = null, ?string $reason = null): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $workspace, $reason): ClientInvoice {
            BillingMaintenance::share($workspace->id ?? $invoice->workspace_id);
            $this->lockAgreementOf($invoice);
            $locked = $this->lockInvoice($invoice, $workspace);

            if ($locked->status === 'void') {
                return $locked;
            }

            if ($locked->paid_amount > 0 || $locked->status === 'paid') {
                throw new DomainException('A paid invoice cannot be voided.');
            }

            if ($locked->emailDeliveries()
                ->where('workspace_id', $locked->workspace_id)
                ->whereIn('status', ['pending', 'sending'])
                ->exists()) {
                throw new DomainException('Wait for the in-flight client delivery before voiding this invoice.');
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

            $this->assertLockedAgreementIsTheInvoices($invoice, $locked);
            if ($locked->status === InvoiceStatus::Draft->value) {
                $this->draftCatchUpDependencies->assertDiscardable($locked);
            } else {
                // An issued invoice a later one was sized against: voiding it
                // takes its charge out of the ledger that later one relied on.
                $this->draftCatchUpDependencies->assertVoidable($locked);
            }

            $this->releaseAllocations($locked);
            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => 'void',
                'voided_at' => $this->clock->now($locked->workspace),
                'void_reason' => $reason,
                'balance_amount' => 0,
                'automatic_delivery_status' => $locked->automatic_delivery_status === null ? null : 'cancelled',
                'automatic_delivery_note' => $locked->automatic_delivery_status === null ? null : 'Invoice voided.',
            ])->save();
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

    /**
     * @param  array<string, mixed>  $data
     * @param  (callable(ClientInvoice): void)|null  $assertLocked
     */
    public function applyPayment(ClientInvoice $invoice, array $data, ?Workspace $workspace = null, ?callable $assertLocked = null): ClientInvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $workspace, $assertLocked): ClientInvoicePayment {
            BillingMaintenance::share($workspace->id ?? $invoice->workspace_id);
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
                        // The finance transaction it was recorded against is
                        // part of what the payment is: no correction can change
                        // it later, so a retry naming another is another payment.
                        || $existing->external_finance_transaction_uuid !== ($data['external_finance_transaction_uuid'] ?? null)
                        || ($claimsDate && $existing->received_on?->toDateString() !== $receivedOn)) {
                        throw new DomainException('The idempotency key is already bound to a different payment.');
                    }

                    return $existing;
                }
            }

            // Ask the caller's precondition under the invoice lock, after a
            // completed keyed payment has had the chance to replay.
            if ($assertLocked !== null) {
                $assertLocked($locked);
            }

            // The automatic sender has already committed its durable claim
            // and is about to cross the mail-provider boundary. A payment
            // cannot cancel that message after it has left, so refuse the new
            // payment while the claim is in flight; the caller can retry once
            // the short synchronous send has resolved. Existing keyed
            // payments returned above remain idempotent.
            if ($locked->automatic_delivery_status === 'sending') {
                $claim = $locked->emailDeliveries()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('origin', 'automatic')
                    ->where('status', 'sending')
                    ->orderByDesc('claimed_at')
                    ->orderByDesc('id')
                    ->tap(Locks::forUpdate())
                    ->first();
                $claimIsStale = $claim instanceof ClientInvoiceEmailDelivery
                    && $claim->claimed_at !== null
                    && $claim->claimed_at->lte(
                        $this->clock->now($locked->workspace)->utc()
                            ->subMinutes(self::AMBIGUOUS_DELIVERY_CLAIM_MINUTES),
                    );
                if (! $claimIsStale) {
                    throw new DomainException('Automatic invoice delivery is in progress. Retry the payment after it finishes.');
                }

                $locked->forceFill([
                    'automatic_delivery_status' => 'cancelled',
                    'automatic_delivery_due_at' => null,
                    'automatic_delivery_note' => 'The unresolved delivery claim expired; a payment was recorded and automatic delivery was cancelled.',
                ])->save();
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

            // Bounded here as well as by every caller's rule, after trimming
            // as PaymentCorrection::method() does: the command line and any
            // import reach this without a form, and a longer value is refused
            // by MariaDB (or truncated) where SQLite stores it.
            $method = $this->requiredString($data['method'] ?? null, 'method');
            if (mb_strlen($method) > ClientInvoicePayment::METHOD_MAX_LENGTH) {
                throw new DomainException('method may not be longer than '.ClientInvoicePayment::METHOD_MAX_LENGTH.' characters.');
            }

            $payment = $locked->payments()->create([
                'workspace_id' => $locked->workspace_id,
                'status' => $status->value,
                'amount' => $amount,
                'refunded_amount' => 0,
                'currency' => $currency,
                'received_on' => $receivedOn,
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'provider' => $data['provider'] ?? null,
                'provider_payment_identifier' => $data['provider_payment_identifier'] ?? null,
                'external_finance_transaction_uuid' => $data['external_finance_transaction_uuid'] ?? null,
                'idempotency_key' => $key,
            ]);

            $previousInvoiceStatus = $locked->status;
            $this->refreshStatus($locked);
            if (in_array($locked->automatic_delivery_status, ['scheduled', 'held', 'failed'], true)) {
                // Automatic delivery is an issuance notice, not a payment
                // reminder. Any payment attempt moves the invoice out of that
                // workflow; an operator can still send it manually.
                $locked->forceFill([
                    'automatic_delivery_status' => 'cancelled',
                    'automatic_delivery_note' => 'A payment was recorded; automatic delivery does not send payment reminders.',
                ])->save();
            }
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

    /**
     * Record the status a payment processor reports for one of its payments.
     *
     * A processor's refund or dispute is a fact about money that has already
     * moved, so it is recorded whatever the payment's finance reconciliation
     * allocations say: they are kept as they are and each active one is
     * flagged for review ({@see ReconciliationReviewReason}). Only the finance
     * write path refuses an allocation that does not fit.
     */
    public function setPaymentStatus(ClientInvoicePayment $payment, string $status, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return $this->writePaymentStatus($payment, $this->paymentStatus($status), $workspace);
    }

    /**
     * Record a processor's dispute of part of a payment: the payment is
     * `disputed`, and only `$disputedAmount` of it stops counting toward the
     * invoice - the processor withholds what it contests and nothing more.
     * Called again with another amount, it records the new one. Reconciliation
     * allocations are flagged as {@see self::setPaymentStatus()} flags them.
     */
    public function setPaymentDisputed(ClientInvoicePayment $payment, int $disputedAmount, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return $this->writePaymentStatus($payment, InvoicePaymentStatus::Disputed, $workspace, disputedAmount: $disputedAmount);
    }

    /**
     * Change what a payment recorded by hand is worth, saying why (#455).
     *
     * The operation behind `payments.set_status`, `payments.refund` and
     * `svc:billing:payment-status` / `svc:billing:refund-payment`.
     * {@see ManualPaymentChange} decides, on the locked row, whether the change
     * may happen at all - only a hand-recorded payment, only the transitions
     * its table allows. The write itself is the same one a processor's events
     * take ({@see self::setPaymentStatus()}, {@see self::setRefundedAmount()}):
     * the same locks, the same overpayment and reconciliation checks, the same
     * credit-pool change and invoice recomputation, the same activity - which
     * here also carries the reason and the status it replaced.
     *
     * Leaving `succeeded` also has to fit the payment's active finance
     * reconciliation allocations, which a processor's own failure does not
     * ask: a reconciled payment is deallocated in the finance system first.
     *
     * `$expectedVersion` is checked on the locked row; a payment that moved
     * since the caller read it is refused with {@see PaymentVersionChanged}.
     * A change that leaves the payment as it is writes and records nothing.
     *
     * **Refunds and their reversals are entries (#472).** Each refund writes
     * one immutable row of the payment's refund history
     * ({@see PaymentRefundLedger}); a refund recorded in error is answered by a
     * reversal naming it, which lowers the refunded amount through the same
     * write and never edits the refund. A reversal makes money count again, so
     * it is refused on a void invoice and wherever the invoice would be paid
     * beyond its total. The entry is written under the payment lock, after the
     * payment, and the activity names it.
     */
    public function changeManualPayment(ClientInvoicePayment $payment, ManualPaymentChange $change, ?string $expectedVersion = null, ?Workspace $workspace = null, ?User $actor = null): ManualPaymentOutcome
    {
        return DB::transaction(function () use ($payment, $change, $expectedVersion, $workspace, $actor): ManualPaymentOutcome {
            /** @var array{status: InvoicePaymentStatus, refunded_amount: int}|null $decision */
            $decision = null;
            $observed = null;
            $kind = $change->isReversal() ? PaymentRefundKind::Reversal : ($change->isRefund() ? PaymentRefundKind::Refund : null);
            // Named before the write, so the activity the write records can
            // carry the entry it is the history of.
            $entryId = $kind === null ? null : (string) Str::uuid();
            $refunds = $this->refunds;
            $guard = static function (ClientInvoicePayment $locked) use ($change, $expectedVersion, $refunds, &$decision, &$observed): bool {
                if ($expectedVersion !== null && ! AgentApiVersion::matches($locked, $expectedVersion)) {
                    throw new PaymentVersionChanged;
                }
                $observed = ['status' => (string) $locked->status, 'refunded_amount' => (int) $locked->refunded_amount, 'version' => AgentApiVersion::for($locked)];
                // Read under the payment's lock, which every write of its
                // refund history holds, so a refund cannot be reversed twice.
                $target = $change->reverses === null ? null : $refunds->find($locked, $change->reverses);
                $decision = $change->decide((string) $locked->status, (int) $locked->amount, (int) $locked->refunded_amount, $locked->provider, $target);

                return $decision !== null;
            };
            // Closures by reference rather than arrow functions: both are read
            // after the guard has run, and an arrow function would capture the
            // values from before it.
            $extra = static function () use ($change, $kind, $entryId, &$observed): array {
                $entry = match ($kind) {
                    PaymentRefundKind::Refund => ['refund_id' => $entryId],
                    PaymentRefundKind::Reversal => ['reversal_id' => $entryId, 'reverses_refund_id' => $change->reverses],
                    null => [],
                };

                return ['reason' => $change->reason, 'previous_status' => $observed['status'] ?? null, ...$entry];
            };
            $total = static function () use (&$decision): int {
                return $decision['refunded_amount'] ?? throw new LogicException('A refund is written only after it is decided.');
            };

            $written = $kind !== null
                ? $this->writeRefundedAmount($payment, $total, $workspace, $guard, $extra, manual: true)
                : $this->writePaymentStatus($payment, $change->status ?? throw new LogicException('A status change names its status.'), $workspace, $guard, $extra, manual: true);

            if ($observed === null) {
                throw new LogicException('The manual payment guard did not run.');
            }
            $entry = null;
            if ($kind !== null && $entryId !== null && $decision !== null) {
                $moved = match ($kind) {
                    PaymentRefundKind::Refund => $decision['refunded_amount'] - $observed['refunded_amount'],
                    PaymentRefundKind::Reversal => $observed['refunded_amount'] - $decision['refunded_amount'],
                };
                $entry = $this->refunds->record($written, $entryId, $kind, $moved, $change->reason, $change->reverses, $actor);
            }

            return new ManualPaymentOutcome(
                $written,
                $observed['status'],
                $observed['refunded_amount'],
                $observed['version'],
                $decision !== null,
                $entry,
            );
        });
    }

    /**
     * The one write of a payment's status, for a processor's event and a
     * hand-recorded change alike.
     *
     * @param  (callable(ClientInvoicePayment): bool)|null  $guard  asked of the locked payment before anything else; false means leave it as it is
     * @param  (callable(): array<string, string|null>)|null  $extra  more for the activity, read after the guard
     * @param  bool  $manual  a hand-recorded change: leaving `succeeded` must fit the reconciliations, and the invoice must be read current
     * @param  int|null  $disputedAmount  for `disputed`, the part of the payment a processor withholds; null is all of it
     */
    private function writePaymentStatus(
        ClientInvoicePayment $payment,
        InvoicePaymentStatus $next,
        ?Workspace $workspace,
        ?callable $guard = null,
        ?callable $extra = null,
        bool $manual = false,
        ?int $disputedAmount = null,
    ): ClientInvoicePayment {
        return DB::transaction(function () use ($payment, $next, $workspace, $guard, $extra, $manual, $disputedAmount): ClientInvoicePayment {
            BillingMaintenance::share($workspace->id ?? $payment->workspace_id);
            $query = ClientInvoicePayment::query()->where('workspace_id', $payment->workspace_id)->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }
            $lockedPayment = $query->firstOrFail();
            if ($guard !== null && ! $guard($lockedPayment)) {
                return $lockedPayment;
            }
            if ($disputedAmount !== null && ($disputedAmount < 1 || $disputedAmount > $lockedPayment->amount)) {
                throw new DomainException('A disputed amount must be part of the payment: more than zero and no more than its amount.');
            }
            // Leaving `disputed` releases what the dispute held; entering or
            // staying in it records what is now held.
            $nextDisputedAmount = $next === InvoicePaymentStatus::Disputed ? $disputedAmount : null;
            $disputeMoved = $lockedPayment->status === $next->value && $next === InvoicePaymentStatus::Disputed
                && $lockedPayment->disputed_amount !== $nextDisputedAmount;
            // Asked after the payment lock and before the invoice's, in the
            // order LockResource declares, with a current read of the
            // allocations.
            if ($lockedPayment->status !== $next->value) {
                if ($manual) {
                    // A hand-recorded change is a decision, not a report: money
                    // that stops counting cannot stay allocated to a finance
                    // transaction, so it is deallocated there first.
                    if ($next === InvoicePaymentStatus::Refunded
                        || ($lockedPayment->status === InvoicePaymentStatus::Succeeded->value && $next !== InvoicePaymentStatus::Succeeded)) {
                        $this->assertReconciliationCapacity($lockedPayment, $lockedPayment->amount);
                    }
                } else {
                    // A processor's report is recorded regardless; what it
                    // does to an allocation is for the finance system to judge.
                    $reason = match ($next) {
                        InvoicePaymentStatus::Refunded => ReconciliationReviewReason::ProcessorRefund,
                        InvoicePaymentStatus::Disputed => ReconciliationReviewReason::ProcessorDispute,
                        InvoicePaymentStatus::Pending, InvoicePaymentStatus::Succeeded,
                        InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled => null,
                    };
                    if ($reason !== null) {
                        $this->flagActiveReconciliations($lockedPayment, $reason);
                    }
                }
            }
            $invoice = ClientInvoice::query()
                ->where('workspace_id', $lockedPayment->workspace_id)
                ->whereKey($lockedPayment->client_invoice_id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($manual) {
                $this->assertCurrentInvoiceSnapshot($invoice);
            }
            if ($disputeMoved) {
                // The same dispute now holds a different amount.
                $this->flagActiveReconciliations($lockedPayment, ReconciliationReviewReason::ProcessorDispute);
                $this->overpaymentCreditService->recordPoolChange((int) $invoice->workspace_id, (int) $invoice->client_company_id);
                $lockedPayment->forceFill(['disputed_amount' => $nextDisputedAmount])->save();
                $this->refreshStatus($invoice);

                return ClientInvoicePayment::query()->where('workspace_id', $lockedPayment->workspace_id)->whereKey($lockedPayment->id)->firstOrFail();
            }
            if ($lockedPayment->status === $next->value) {
                return $lockedPayment;
            }
            $restoredOverpayment = null;
            // A processor returning a disputed payment's money reports money
            // the business holds; refusing it would leave the payment disputed
            // and the processor's notification failing for good.
            $processorRestoration = ! $manual && $next === InvoicePaymentStatus::Succeeded
                && $lockedPayment->status === InvoicePaymentStatus::Disputed->value;
            $review = null;
            if ($next === InvoicePaymentStatus::Succeeded && $invoice->status === 'void') {
                if (! $processorRestoration) {
                    throw new DomainException('A payment succeeded against a void invoice; refund it or un-void the invoice before recording it.');
                }
                // Recorded, and the invoice stays void: the money belongs to
                // no live invoice, so it is named for review instead.
                $review = 'restored_on_void_invoice';
            }
            if ($next === InvoicePaymentStatus::Succeeded) {
                // A positive filter, so another row of an unreadable status is
                // not counted here and this check can pass on an understated
                // total. `refreshStatus()` below is the backstop: it refuses to
                // total a set containing one and rolls this transaction back.
                // What every other payment already pays, a partly disputed one
                // included ({@see ClientInvoicePayment::paidContribution()}).
                $otherPaid = (int) $invoice->payments()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->where('id', '!=', $lockedPayment->id)
                    ->get(['status', 'amount', 'refunded_amount', 'disputed_amount'])
                    ->sum(fn (ClientInvoicePayment $other): int => $other->paidContribution());
                $overpaid = ($lockedPayment->amount - $lockedPayment->refunded_amount) - ($invoice->total_amount - $otherPaid);
                if ($overpaid > 0 && $review === null) {
                    // A restoration possibly arrives after the invoice was paid
                    // again in the meantime: it is recorded and the excess is
                    // left to the derived overpayment credit, named in the
                    // activity for review. Everything else is still refused.
                    if (! $processorRestoration) {
                        throw new DomainException('Successful payments cannot exceed the invoice total.');
                    }
                    $restoredOverpayment = min($overpaid, $lockedPayment->amount - $lockedPayment->refunded_amount);
                }
            }
            // Leaving or entering `succeeded` changes the settled money the
            // credit pool is derived from - a failure, dispute or refund can
            // shrink it. Recorded under the company lock, after the payment,
            // the reconciliations and the invoice above, so an issue spending
            // credit concurrently either waits for this or refuses on the moved
            // revision.
            $this->overpaymentCreditService->recordPoolChange((int) $invoice->workspace_id, (int) $invoice->client_company_id);
            $previousInvoiceStatus = $invoice->status;
            $lockedPayment->forceFill([
                'status' => $next->value,
                'refunded_amount' => $next === InvoicePaymentStatus::Refunded
                    ? $lockedPayment->amount
                    : $lockedPayment->refunded_amount,
                'disputed_amount' => $nextDisputedAmount,
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
                $this->recordPaymentActivity($invoice, $lockedPayment, $action, (string) Str::uuid(), [
                    ...($extra === null ? [] : $extra()),
                    ...($restoredOverpayment === null ? [] : ['overpaid_amount' => $restoredOverpayment]),
                    ...($review === null ? [] : ['review' => $review]),
                    ...($nextDisputedAmount === null ? [] : ['disputed_amount' => $nextDisputedAmount]),
                ]);
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
     * The long-standing date-only door, kept because the invoice screen's
     * **Correct date** control and its `invoice.payment_date_corrected` history
     * predate {@see self::correctPayment()}. It is now that operation with one
     * field named and no reason asked for: the same row lock, the same scoped
     * invoice lock, the same bound, the same no-op rule. What it keeps of its
     * own is the activity it records - the action name and the
     * `previous_received_on`/`received_on` payload the activity feed and every
     * earlier entry already carry - so no history changes shape.
     *
     * `refreshStatus()` is deliberately not called, here or in the general
     * operation. See {@see self::correctPayment()}.
     */
    public function setPaymentReceivedOn(ClientInvoicePayment $payment, string $receivedOn, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return $this->writePaymentCorrection(
            $payment,
            PaymentCorrection::parse(['received_on' => $receivedOn], null),
            null,
            $workspace,
        )->payment;
    }

    /**
     * Correct a payment's descriptive fields: method, reference, notes, date.
     *
     * One audited operation rather than one function per field, and limited to
     * the fields that describe money rather than being it. There is still no
     * payment-edit path for money: amount, currency, status, refunded amount,
     * the invoice and the processor fields are refused by name, each pointing
     * at the operation that changes what it represents while preserving the
     * history - {@see self::setPaymentStatus()}, {@see self::setRefundedAmount()},
     * or cancelling and recording again. {@see PaymentCorrection} is the
     * allow-list and does the parsing before anything is locked.
     *
     * Each field is bounded exactly as it is on the way in: `method` is
     * required text no longer than its column, `reference` and `notes` are
     * nullable and length-bounded (blank clears them), and `received_on` goes
     * through {@see self::receivedOn()} - `YYYY-MM-DD`, not in the future and
     * not more than two years back, on the invoice's workspace calendar.
     *
     * A reason is required and recorded. `$expectedVersion` is the opaque
     * {@see AgentApiVersion} of the payment as the caller read it; when given,
     * a payment that has moved since - any write to the row bumps it - is
     * refused with {@see PaymentVersionChanged} before anything is written.
     *
     * Locking follows {@see self::setPaymentStatus()} and
     * {@see self::setRefundedAmount()}: the payment row, then the invoice
     * through a workspace-scoped locked query, in the order {@see LockResource}
     * declares. The invoice lock is not needed by these columns and is taken
     * anyway, so a correction cannot interleave with an operation already
     * rewriting that invoice's payments.
     *
     * A correction that changes nothing - every named field already reads that
     * way - writes nothing and records nothing. Otherwise one
     * `invoice.payment_corrected` activity is recorded with a before/after of
     * only the fields that changed and the reason, under a fresh occurrence so
     * each correction is its own event.
     *
     * `refreshStatus()` is deliberately not called. None of these columns is an
     * input to it, and it refuses to recompute an invoice carrying any payment
     * of an unreadable status - so calling it here would make a bookkeeping
     * correction fail because of an unrelated row, which is the one thing a
     * repair must not do.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public function correctPayment(
        ClientInvoicePayment $payment,
        array $changes,
        string $reason,
        ?string $expectedVersion = null,
        ?Workspace $workspace = null,
    ): ClientInvoicePayment {
        return $this->correctPaymentReporting($payment, $changes, $reason, $expectedVersion, $workspace)->payment;
    }

    /**
     * {@see self::correctPayment()}, reporting what it found on the locked row.
     *
     * For a caller that describes the correction - the console command - and
     * must not describe it from a read it took before the lock.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public function correctPaymentReporting(
        ClientInvoicePayment $payment,
        array $changes,
        string $reason,
        ?string $expectedVersion = null,
        ?Workspace $workspace = null,
    ): PaymentCorrectionOutcome {
        return $this->writePaymentCorrection(
            $payment,
            PaymentCorrection::parse($changes, $reason),
            $expectedVersion,
            $workspace,
        );
    }

    /**
     * The shared body of both correction doors.
     *
     * A correction without a reason can only have come from
     * {@see self::setPaymentReceivedOn()}, whose door never asked for one; it
     * is recorded under that door's own action and payload.
     */
    private function writePaymentCorrection(
        ClientInvoicePayment $payment,
        PaymentCorrection $correction,
        ?string $expectedVersion,
        ?Workspace $workspace,
    ): PaymentCorrectionOutcome {
        return DB::transaction(function () use ($payment, $correction, $expectedVersion, $workspace): PaymentCorrectionOutcome {
            BillingMaintenance::share($workspace->id ?? $payment->workspace_id);
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

            // Asked of the locked row, so a write that landed between the
            // caller's read and this lock is seen rather than overwritten.
            if ($expectedVersion !== null && ! AgentApiVersion::matches($lockedPayment, $expectedVersion)) {
                throw new PaymentVersionChanged;
            }
            $lockedVersion = AgentApiVersion::for($lockedPayment);

            $bounded = $correction->changes;
            if (array_key_exists('received_on', $bounded)) {
                // The invoice's own workspace, not the caller's: the bound is a
                // statement about which day it is where this money was received.
                $bounded['received_on'] = $this->receivedOn($bounded['received_on'], $invoice->workspace);
            }

            $diff = PaymentCorrection::diff([
                'method' => $lockedPayment->method,
                'reference' => $lockedPayment->reference,
                'notes' => $lockedPayment->notes,
                'received_on' => $lockedPayment->received_on?->toDateString(),
            ], $bounded);
            if ($diff === []) {
                return new PaymentCorrectionOutcome($lockedPayment, [], $lockedVersion);
            }

            $lockedPayment->forceFill(array_map(
                static fn (array $change): ?string => $change['new'],
                $diff,
            ))->save();

            if ($correction->reason === null) {
                $this->recordPaymentActivity(
                    $invoice,
                    $lockedPayment,
                    'invoice.payment_date_corrected',
                    (string) Str::uuid(),
                    ['previous_received_on' => $diff['received_on']['old'] ?? null, 'received_on' => $diff['received_on']['new'] ?? null],
                );
            } else {
                $this->recordPaymentActivity(
                    $invoice,
                    $lockedPayment,
                    'invoice.payment_corrected',
                    (string) Str::uuid(),
                    ['changes' => PaymentCorrection::forActivity($diff), 'reason' => $correction->reason],
                );
            }

            // The row this transaction locked, wrote and still holds, rather
            // than `refresh()`. That re-reads by primary key alone, which is a
            // tenant-owned query with no tenant in it - harmless in itself,
            // since a primary key cannot reach another workspace's row, but it
            // is a shape the query-shape guard would have to carve an exception
            // for and the next reader would copy. There is also nothing to
            // re-read: the save above is the only write to this row.
            return new PaymentCorrectionOutcome($lockedPayment, $diff, $lockedVersion);
        });
    }

    /**
     * Record the cumulative refunded total a payment processor reports.
     *
     * Recorded whatever the payment's finance reconciliation allocations say,
     * as {@see self::setPaymentStatus()} records a refund or a dispute: each
     * active allocation is kept and flagged for review. The total may also
     * fall - a refund that failed or was canceled - and may change on a
     * disputed payment, which stays disputed.
     */
    public function setRefundedAmount(ClientInvoicePayment $payment, int $amount, ?Workspace $workspace = null): ClientInvoicePayment
    {
        return $this->writeRefundedAmount($payment, static fn (): int => $amount, $workspace);
    }

    /**
     * The one write of a payment's refunded amount.
     *
     * @param  callable(): int  $total  the new refunded total, read after the guard
     * @param  (callable(ClientInvoicePayment): bool)|null  $guard  asked of the locked payment first; false means leave it as it is
     * @param  (callable(): array<string, string|null>)|null  $extra  more for the activity
     * @param  bool  $manual  a hand-recorded refund: it must fit the reconciliations; otherwise a processor's report, which flags them
     */
    private function writeRefundedAmount(ClientInvoicePayment $payment, callable $total, ?Workspace $workspace, ?callable $guard = null, ?callable $extra = null, bool $manual = false): ClientInvoicePayment
    {
        return DB::transaction(function () use ($payment, $total, $workspace, $guard, $extra, $manual): ClientInvoicePayment {
            BillingMaintenance::share($workspace->id ?? $payment->workspace_id);
            $query = ClientInvoicePayment::query()->where('workspace_id', $payment->workspace_id)->whereKey($payment->id)->tap(Locks::forUpdate());
            if ($workspace !== null) {
                $query->where('workspace_id', $workspace->id);
            }

            $lockedPayment = $query->firstOrFail();
            if ($guard !== null && ! $guard($lockedPayment)) {
                return $lockedPayment;
            }
            $amount = $total();
            // Fail-closed already: an unreadable status matches neither case
            // and is refused, which is the right answer - a row nobody can read
            // cannot be shown to hold money there is anything to refund. A
            // disputed payment's refunds are the processor's to report - a
            // refund still pending when the charge was disputed fails - and
            // never a hand-recorded change.
            $currentStatus = InvoicePaymentStatus::tryFrom((string) $lockedPayment->status);
            $refundable = match ($currentStatus) {
                InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Refunded => true,
                InvoicePaymentStatus::Disputed => ! $manual,
                InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed,
                InvoicePaymentStatus::Canceled, null => false,
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
            if ($manual) {
                $this->assertReconciliationCapacity($lockedPayment, $amount);
            } else {
                $this->flagActiveReconciliations($lockedPayment, ReconciliationReviewReason::ProcessorRefund);
            }

            $invoice = ClientInvoice::query()
                ->where('workspace_id', $lockedPayment->workspace_id)
                ->whereKey($lockedPayment->client_invoice_id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            if ($guard !== null) {
                $this->assertCurrentInvoiceSnapshot($invoice);
            }
            $previousAmount = $lockedPayment->refunded_amount;
            // A hand-recorded reversal (#472) is a decision that money counts
            // again, so it must fit the invoice as recording that money would.
            // A processor's lower total is a report and is recorded regardless.
            $reversal = $manual && $amount < $previousAmount;
            if ($reversal) {
                if ($invoice->status === 'void') {
                    throw new DomainException('The invoice is void, so a refund of its payment cannot be reversed: the money would count toward an invoice nobody owes. Nothing has been changed.');
                }
                $otherPaid = (int) $invoice->payments()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->where('id', '!=', $lockedPayment->id)
                    ->get(['status', 'amount', 'refunded_amount', 'disputed_amount'])
                    ->sum(fn (ClientInvoicePayment $other): int => $other->paidContribution());
                if (($lockedPayment->amount - $amount) > ($invoice->total_amount - $otherPaid)) {
                    throw new DomainException('Reversing this refund would make successful payments exceed the invoice total. Withdraw the payment that replaced the refunded money first. Nothing has been changed.');
                }
            }
            // A refund changes the settled money the credit pool is derived
            // from. Recorded under the company lock, after the payment and the
            // invoice, so an issue spending credit concurrently either waits
            // for this or refuses on the moved revision.
            $this->overpaymentCreditService->recordPoolChange((int) $invoice->workspace_id, (int) $invoice->client_company_id);
            $previousInvoiceStatus = $invoice->status;
            $lockedPayment->forceFill([
                'refunded_amount' => $amount,
                'status' => $currentStatus === InvoicePaymentStatus::Disputed
                    ? InvoicePaymentStatus::Disputed->value
                    : ($amount === $lockedPayment->amount
                        ? InvoicePaymentStatus::Refunded->value
                        : InvoicePaymentStatus::Succeeded->value),
            ])->save();
            $this->refreshStatus($invoice);
            // A processor's lower total gives money back - a refund that
            // failed or was canceled - and is recorded as that, not as a
            // second refund.
            $this->recordPaymentActivity(
                $invoice,
                $lockedPayment,
                $amount < $previousAmount ? 'invoice.payment_refund_reversed' : 'invoice.payment_refunded',
                (string) Str::uuid(),
                ['previous_refunded_amount' => $previousAmount, ...($extra === null ? [] : $extra())],
            );
            if ($reversal) {
                // The money counts again, so it may settle the invoice again.
                $this->recordMarkedPaid($invoice, $previousInvoiceStatus, (string) Str::uuid());
            }

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

        foreach ($invoice->payments()->where('workspace_id', $invoice->workspace_id)->get(['public_id', 'status', 'amount', 'refunded_amount', 'disputed_amount']) as $payment) {
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

            // A succeeded payment net of refunds, and the undisputed part of a
            // partly disputed one.
            $paid += $payment->paidContribution();
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
            // Every manual door arrives here. A type the capacity ledger reads
            // as money state is the generator's alone to write.
            if (in_array($line['type'] ?? null, InvoiceLineType::systemOnlyValues(), true)) {
                throw new DomainException('That line type is written only by invoice generation and cannot be added by hand.');
            }
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

    /** @param array<string, int|string|array<string, array{old: string|null, new: string|null}>|null> $extra */
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

    /**
     * Whether the payment's active finance allocations still fit the money it
     * would hold.
     *
     * A share-locked read, so it is a current one: under MariaDB's REPEATABLE
     * READ a plain sum answers from the caller's transaction snapshot, which
     * can predate an allocation committed while this request waited for the
     * payment lock. Every caller holds the payment lock and has not yet taken
     * the invoice's, which is the order LockResource declares.
     */
    /**
     * Refuse a hand-recorded change made from a stale view of the invoice.
     *
     * The invoice was just read with a lock, which is a current read; the
     * sibling payments the overpayment guard and `refreshStatus()` total are
     * read plainly, which under MariaDB's REPEATABLE READ answers from the
     * transaction's snapshot. A caller that read anything earlier in the same
     * transaction - an agent mutation authorizes and loads the payment first -
     * holds a snapshot that can predate a payment recorded or changed on this
     * invoice while it waited for the lock, and every such write moves the
     * invoice's revision. So the plain read of that revision is compared with
     * the locked one, and a difference is a conflict to retry rather than a
     * total computed over money that is no longer there.
     */
    private function assertCurrentInvoiceSnapshot(ClientInvoice $locked): void
    {
        $seen = ClientInvoice::query()->where('workspace_id', $locked->workspace_id)->whereKey($locked->id)->firstOrFail(['id', 'workspace_id', 'lock_version']);
        if (! AgentApiVersion::matches($seen, AgentApiVersion::for($locked))) {
            throw new InvoiceChangedDuringPaymentChange;
        }
    }

    /**
     * Mark every active finance reconciliation allocation of a payment the
     * processor has just refunded or disputed. The allocations themselves are
     * left exactly as the finance system wrote them.
     *
     * The finance listing embeds the allocations and their flags, so flagging
     * any is a change to the payment: its revision (and `updated_at`) advances
     * here, whatever the caller writes to the payment afterwards, or a
     * synchronizer keyed on either would never see the flag.
     */
    private function flagActiveReconciliations(ClientInvoicePayment $payment, ReconciliationReviewReason $reason): void
    {
        $flagged = $payment->reconciliations()
            ->where('workspace_id', $payment->workspace_id)
            ->where('is_active', true)
            ->update([
                'review_reason' => $reason->value,
                'review_flagged_at' => $this->clock->now()->utc(),
            ]);
        if ($flagged > 0) {
            $payment->advanceAgentRevision();
        }
    }

    private function assertReconciliationCapacity(ClientInvoicePayment $payment, int $refundedAmount): void
    {
        $activeAllocated = (int) $payment->reconciliations()
            ->where('workspace_id', $payment->workspace_id)
            ->where('is_active', true)
            ->tap(Locks::forShare())
            ->sum('allocated_amount');
        $netAmount = max(0, $payment->amount - $refundedAmount);

        if ($activeAllocated > $netAmount) {
            throw new DomainException('Refunding this payment would exceed its active finance reconciliation allocations.');
        }
    }
}
