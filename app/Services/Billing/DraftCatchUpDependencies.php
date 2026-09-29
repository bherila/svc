<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Support\Billing\CatchUpBasis;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catch-up that a monthly invoice was sized against while an earlier
 * invoice was still a draft.
 *
 * The capacity ledger books each month's work from time entries, but reads
 * catch-up hours only from charged invoices. Monthly generation therefore
 * overlays each earlier cadence draft's `hours_billed_at_rate` as though it
 * were issued. Without the overlay, the next invoice would open on debt that
 * the draft already bills and charge it again. With the overlay, the later
 * invoice relies on a charge that nobody has made yet. So the earlier draft
 * must be issued before the later invoice is, and it must not be discarded
 * while a later invoice relies on it: the lifecycle refuses both
 * (`EarlierDraftCatchUpTest`).
 *
 * Only monthly generation overlays earlier drafts, but the ordering guards
 * cover every cadence invoice: see agreementOf().
 *
 * Order alone does not say which figure a later invoice relied on. Monthly
 * generation therefore also records, on the invoice, every earlier charge it
 * was sized against - issued and draft - as a CatchUpBasis. Issuing it
 * re-measures that and refuses once any charge has moved, so an earlier draft
 * regenerated or created after it forces it to be regenerated too; and an
 * issued invoice that a live later one recorded cannot be voided under it.
 */
final class DraftCatchUpDependencies
{
    public function __construct(
        private readonly BilledOverageLedger $billedOverageLedger = new BilledOverageLedger,
    ) {}

    /**
     * What a monthly invoice generated through `$through` is sized against,
     * from one read of each source, so that what is overlaid and what is
     * recorded cannot disagree.
     *
     * `overlay` is the catch-up hours charged by earlier cadence drafts, keyed
     * by the YYYY-MM work month each settles: the same key the billed-overage
     * ledger uses. Issued invoices are already in that ledger, so only drafts
     * are missing from it. The window is the ledger's own: an invoice whose
     * service period ends on or before `$through`, the end of the range being
     * generated (BilledOverageLedger::window()), so a draft counts here exactly
     * when its charge will count there once issued. A later draft is outside
     * it. Void and deleted invoices charged nothing and are not counted.
     *
     * `basis` is every earlier charge the invoice relies on, by invoice: each
     * charged invoice in the ledger's window and each draft overlaid.
     *
     * An unknown figure on a draft that carries an additional-hours line is
     * refused rather than read as zero, as it is on every billed-overage read:
     * dropping it would bill the same debt again. With no such line (a migrated
     * draft, typically) nothing on it bills at the rate, so it charges nothing.
     *
     * @infection-ignore-all The tenant, status, kind and date predicates need the feature database and are covered by CorrectionPoolDrawTest and EarlierDraftCatchUpTest; the mutation lane runs unit tests only, and covers CatchUpBasis.
     *
     * @return array{overlay: array<string, float>, basis: CatchUpBasis}
     */
    public function sizingThrough(ClientAgreement $agreement, int $companyId, Carbon $through, ?int $excludeInvoiceId): array
    {
        $workspaceId = (int) $agreement->workspace_id;
        $agreementId = (int) $agreement->id;
        $drafts = $this->earlierDrafts($workspaceId, $companyId, $agreementId, $through, $excludeInvoiceId)
            ->where(fn (Builder $query): Builder => $this->billingCatchUp($query))
            ->orderBy('service_period_end')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'service_period_end', 'hours_billed_at_rate']);

        $overlay = [];
        $charges = $this->chargedThrough($workspaceId, $agreementId, $through, $excludeInvoiceId, current: false);
        foreach ($drafts as $draft) {
            $hours = $this->draftHoursOrFail($draft);
            $charges[] = ['id' => (int) $draft->id, 'number' => (string) $draft->invoice_number, 'hours' => $hours];
            if ($hours === 0.0 || $draft->service_period_end === null) {
                continue;
            }
            $month = $draft->service_period_end->format('Y-m');
            $overlay[$month] = round(($overlay[$month] ?? 0.0) + $hours, 4);
        }

        return ['overlay' => $overlay, 'basis' => CatchUpBasis::of($through->toDateString(), $charges)];
    }

    /**
     * The same basis measured now, through row locks only.
     *
     * A row lock returns the current row rather than a transaction's snapshot
     * and waits for a writer still holding it, so an issue whose caller read
     * something first cannot pass on an old figure. And nothing here is an
     * ordinary read, which would fix that snapshot before `issue()` takes the
     * credit lock it relies on: the drafts are selected without
     * billingCatchUp()'s line subquery, and whether a draft with no figure
     * bills at the rate is asked of its lines with a lock of their own.
     *
     * @infection-ignore-all The tenant, status, kind and date predicates and the locks need the feature database and are covered by EarlierDraftCatchUpTest; the mutation lane runs unit tests only, and covers CatchUpBasis.
     */
    public function currentBasisThrough(int $workspaceId, int $companyId, int $agreementId, Carbon $through, int $invoiceId): CatchUpBasis
    {
        $charges = $this->chargedThrough($workspaceId, $agreementId, $through, $invoiceId, current: true);
        $drafts = $this->earlierDrafts($workspaceId, $companyId, $agreementId, $through, $invoiceId)
            ->where(function (Builder $query): void {
                $query->where('hours_billed_at_rate', '!=', 0)->orWhereNull('hours_billed_at_rate');
            })
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get(['id', 'invoice_number', 'hours_billed_at_rate']);

        $unknown = $drafts->whereNull('hours_billed_at_rate')->pluck('id')->all();
        $billingAtRate = $unknown === [] ? [] : ClientInvoiceLine::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_invoice_id', $unknown)
            ->where('type', InvoiceLineType::AdditionalHours->value)
            ->tap(Locks::forUpdate())
            ->get(['id', 'client_invoice_id'])
            ->map(static fn (ClientInvoiceLine $line): int => (int) $line->client_invoice_id)
            ->all();

        foreach ($drafts as $draft) {
            if ($draft->hours_billed_at_rate === null && ! in_array((int) $draft->id, $billingAtRate, true)) {
                continue;
            }
            $charges[] = ['id' => (int) $draft->id, 'number' => (string) $draft->invoice_number, 'hours' => $this->draftHoursOrFail($draft)];
        }

        return CatchUpBasis::of($through->toDateString(), $charges);
    }

    /**
     * The billed-overage ledger's charged invoices through `$through`, with
     * the figure each charged.
     *
     * @return list<array{id: int, number: string, hours: float}>
     */
    private function chargedThrough(int $workspaceId, int $agreementId, Carbon $through, ?int $excludeInvoiceId, bool $current): array
    {
        $charged = $this->billedOverageLedger->windowFor($workspaceId, $agreementId, $through)
            ->when($excludeInvoiceId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeInvoiceId))
            ->when($current, fn (Builder $query): Builder => $query->tap(Locks::forUpdate()))
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'hours_billed_at_rate']);

        $charges = [];
        foreach ($charged as $invoice) {
            $charges[] = ['id' => (int) $invoice->id, 'number' => (string) $invoice->invoice_number, 'hours' => $invoice->billedOverageHoursOrFail()];
        }

        return $charges;
    }

    /**
     * Refuse to issue a draft whose recorded basis no longer matches the
     * earlier charges it was sized against.
     *
     * Measured again over the recorded window, through row locks: the rows
     * this compares are other invoices of the agreement, and a stale read of
     * one would let a later invoice through on a figure that has already
     * moved. Called straight after the invoice's own lock, before anything of
     * lower rank, so these locks keep the acquisition order and read nothing
     * that fixes the transaction's snapshot.
     *
     * A draft with no recorded basis - generated before it was recorded, or
     * by a path that does not measure the billed-overage ledger - is issued
     * as it always was.
     *
     * @infection-ignore-all The window and lock need the feature database and are covered by EarlierDraftCatchUpTest; the comparison is CatchUpBasis's and is unit-tested there.
     */
    public function assertSizedAgainstCurrentCharges(ClientInvoice $invoice): void
    {
        if ($invoice->catch_up_basis === null || $invoice->client_agreement_id === null) {
            return;
        }

        $recorded = CatchUpBasis::fromArray($invoice->catch_up_basis);
        if (! $recorded instanceof CatchUpBasis) {
            throw new DomainException(
                "Invoice {$invoice->invoice_number} records the earlier catch-up it was sized against in a form "
                .'that cannot be read. Regenerate it before issuing it.',
            );
        }

        $current = $this->currentBasisThrough(
            (int) $invoice->workspace_id,
            (int) $invoice->client_company_id,
            (int) $invoice->client_agreement_id,
            Carbon::parse($recorded->through),
            (int) $invoice->id,
        );

        $change = $recorded->firstChangeIn($current);
        if ($change !== null) {
            throw new DomainException(sprintf(
                'Invoice %s was sized against %s catch-up hours charged by invoice %s, which now charges %s. '
                .'Regenerate this invoice before issuing it, so that it bills the debt that is actually left.',
                $invoice->invoice_number,
                self::hours($change['recorded']),
                $change['number'],
                self::hours($change['current']),
            ));
        }
    }

    /**
     * Refuse to void a charged invoice while a live later invoice recorded
     * its catch-up as part of the basis it was sized against.
     *
     * Voided, the charge leaves the billed-overage ledger, and the debt it
     * paid would then be billed by neither invoice. Only a recorded basis
     * counts: a later invoice generated before bases were recorded is not
     * known to rely on it, and voiding under it is allowed as it always was.
     * So is one whose basis cannot be read, which says nothing either way.
     * A locking read, after the agreement lock, for the reason given in
     * assertDiscardable().
     *
     * @infection-ignore-all The predicates and lock need the feature database and are covered by EarlierDraftCatchUpTest; CatchUpBasis::hoursFrom() is unit-tested.
     */
    public function assertVoidable(ClientInvoice $invoice): void
    {
        if ($invoice->client_agreement_id === null) {
            return;
        }

        $dependents = ClientInvoice::query()
            ->where('workspace_id', $invoice->workspace_id)
            // Not narrowed to the company: the recorded window is the
            // ledger's, which reads the agreement's invoices whoever they name.
            ->where('client_agreement_id', $invoice->client_agreement_id)
            ->whereIn('status', InvoiceStatus::live())
            ->whereKeyNot($invoice->getKey())
            ->whereNotNull('catch_up_basis')
            ->orderBy('service_period_end')
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get(['id', 'invoice_number', 'status', 'catch_up_basis']);

        foreach ($dependents as $dependent) {
            $hours = CatchUpBasis::fromArray($dependent->catch_up_basis)?->hoursFrom((int) $invoice->id) ?? 0.0;
            if ($hours === 0.0) {
                continue;
            }
            $undo = in_array($dependent->status, [InvoiceStatus::Paid->value, InvoiceStatus::PartiallyPaid->value], true)
                ? "{$dependent->invoice_number} has taken payment and cannot be voided, so neither can this while it stands."
                : "Void or discard {$dependent->invoice_number} first.";
            throw new DomainException(sprintf(
                'Invoice %s covers later work and was sized against the %s catch-up hours this invoice charges; '
                .'voided, that debt would be billed by neither. %s To change only this invoice\'s wording or an '
                .'operator-authored line, correct it in place where that is allowed, which keeps its charge.',
                $dependent->invoice_number,
                self::hours($hours),
                $undo,
            ));
        }
    }

    /**
     * Refuse to issue a cadence invoice while an earlier draft whose
     * catch-up it was sized against has not been issued.
     *
     * @infection-ignore-all The predicates need the feature database and are covered by EarlierDraftCatchUpTest; the mutation lane runs unit tests only.
     */
    public function assertIssuable(ClientInvoice $invoice): void
    {
        $agreement = $this->agreementOf($invoice);
        if (! $agreement instanceof ClientAgreement || $invoice->service_period_end === null) {
            return;
        }

        $earlier = $this->cadenceInvoices((int) $invoice->workspace_id, (int) $invoice->client_company_id, (int) $agreement->id)
            ->where('status', InvoiceStatus::Draft->value)
            ->whereKeyNot($invoice->getKey())
            // Compared by end, as the ledger places a charge: a start widened
            // backwards by a backdated line would hide the draft this was
            // sized against, and an end widened forwards only makes this
            // stricter.
            ->where(fn (Builder $query): Builder => $this->endingBefore(
                $query,
                Carbon::instance($invoice->service_period_end),
                $invoice->id,
            ))
            ->where(fn (Builder $query): Builder => $this->billingCatchUp($query))
            ->orderBy('service_period_end')
            ->value('invoice_number');

        if (is_string($earlier)) {
            throw new DomainException(
                "Draft invoice {$earlier} covers earlier work and bills catch-up hours that this invoice was sized "
                .'against as though they were already charged. Issue or discard it first, then regenerate this '
                .'invoice if it was discarded.',
            );
        }
    }

    /**
     * Refuse to discard or void a cadence draft that bills catch-up
     * while a later live invoice was sized against that charge.
     *
     * @infection-ignore-all The predicates need the feature database and are covered by EarlierDraftCatchUpTest; the mutation lane runs unit tests only.
     */
    public function assertDiscardable(ClientInvoice $draft): void
    {
        $agreement = $this->agreementOf($draft);
        if (! $agreement instanceof ClientAgreement
            || $draft->service_period_end === null
            || ! $this->billingCatchUp(
                // The current row, not the snapshot: the caller holds its
                // lock, and a regeneration committed while it waited may
                // have given this draft its catch-up.
                ClientInvoice::query()->where('workspace_id', $draft->workspace_id)->whereKey($draft->getKey())
                    ->tap(Locks::forUpdate()),
            )->exists()) {
            return;
        }

        $later = $this->cadenceInvoices((int) $draft->workspace_id, (int) $draft->client_company_id, (int) $agreement->id)
            ->whereIn('status', InvoiceStatus::live())
            ->whereKeyNot($draft->getKey())
            ->where(fn (Builder $query): Builder => $this->endingAfter(
                $query,
                Carbon::instance($draft->service_period_end),
                $draft->id,
            ))
            ->orderBy('service_period_end')
            // A locking read, which reads the current row rather than the
            // transaction's snapshot: a caller that read something before the
            // agreement lock (the agent API's authorisation does) would
            // otherwise miss a later invoice committed while it waited for it.
            ->tap(Locks::forUpdate())
            ->value('invoice_number');

        if (is_string($later)) {
            throw new DomainException(
                "Invoice {$later} covers later work and was sized against the catch-up hours this draft bills. "
                .'Discard that invoice first, then discard this draft and regenerate it.',
            );
        }
    }

    /**
     * Earlier cadence drafts, in the order the overlay and guards share.
     *
     * @return Builder<ClientInvoice>
     */
    private function earlierDrafts(int $workspaceId, int $companyId, int $agreementId, Carbon $through, ?int $excludeInvoiceId): Builder
    {
        return $this->cadenceInvoices($workspaceId, $companyId, $agreementId)
            ->where('status', InvoiceStatus::Draft->value)
            ->when($excludeInvoiceId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludeInvoiceId))
            ->where(fn (Builder $query): Builder => $this->endingBefore($query, $through, $excludeInvoiceId));
    }

    /**
     * A draft's catch-up, refused when unknown: billingCatchUp() admits a
     * null figure only beside an additional-hours line, so a null here is one
     * that bills at the rate and cannot be read as zero.
     */
    private function draftHoursOrFail(ClientInvoice $draft): float
    {
        if ($draft->hours_billed_at_rate === null) {
            throw new DomainException(
                "Draft invoice {$draft->invoice_number} records no billed-overage hours, so what it bills cannot "
                .'be known and a later invoice cannot be sized without risking a second charge for the same '
                .'hours. Regenerate or discard that draft first.',
            );
        }

        return (float) $draft->hours_billed_at_rate;
    }

    private static function hours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 4, '.', ''), '0'), '.');
    }

    /**
     * Invoices ordered before one ending on `$end`: by the end of their service
     * period, as the ledger places a charge, and by id between equal ends. The
     * overlay and both guards use this one order, so a draft the overlay
     * counts is exactly one the guards see, and two drafts ending on the same
     * day can never each wait for the other. An invoice not yet written (no
     * id) comes after every row ending on its end, as it will once created.
     *
     * @param  Builder<ClientInvoice>  $query
     * @return Builder<ClientInvoice>
     */
    private function endingBefore(Builder $query, Carbon $end, ?int $id): Builder
    {
        return $query->where(function (Builder $query) use ($end, $id): void {
            $query->whereDate('service_period_end', '<', $end->toDateString())
                ->orWhere(function (Builder $tie) use ($end, $id): void {
                    $tie->whereDate('service_period_end', '=', $end->toDateString())
                        ->when($id !== null, fn (Builder $query): Builder => $query->where('id', '<', $id));
                });
        });
    }

    /**
     * Invoices ordered after one ending on `$end`, in the order of
     * endingBefore().
     *
     * @param  Builder<ClientInvoice>  $query
     * @return Builder<ClientInvoice>
     */
    private function endingAfter(Builder $query, Carbon $end, int $id): Builder
    {
        return $query->where(function (Builder $query) use ($end, $id): void {
            $query->whereDate('service_period_end', '>', $end->toDateString())
                ->orWhere(function (Builder $tie) use ($end, $id): void {
                    $tie->whereDate('service_period_end', '=', $end->toDateString())
                        ->where('id', '>', $id);
                });
        });
    }

    /**
     * Invoices that bill catch-up, or may: a non-zero figure, or no figure at
     * all beside a line that bills hours at the rate.
     *
     * @param  Builder<ClientInvoice>  $query
     * @return Builder<ClientInvoice>
     */
    private function billingCatchUp(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('hours_billed_at_rate', '!=', 0)
                ->orWhere(function (Builder $unknown): void {
                    $unknown->whereNull('hours_billed_at_rate')
                        ->whereHas('lines', function (Builder $lines): void {
                            $lines->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
                                ->where('type', InvoiceLineType::AdditionalHours->value);
                        });
                });
        });
    }

    /**
     * The agreement of a generated cadence invoice.
     *
     * Deliberately not narrowed to monthly agreements. Only monthly generation
     * overlays earlier drafts, but an agreement's cadence can be changed after
     * its monthly drafts exist, and a hand-edited draft keeps no record of the
     * cadence it was generated under. Keeping cadence invoices in order costs
     * a non-monthly agreement nothing it relies on, while guessing from the
     * current cadence could lift the guard from a draft a later one relies on.
     */
    private function agreementOf(ClientInvoice $invoice): ?ClientAgreement
    {
        if ($invoice->client_agreement_id === null
            || ! in_array($invoice->invoice_kind, [null, InvoiceKind::CadencePeriod->value], true)) {
            return null;
        }

        return ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereKey($invoice->client_agreement_id)
            ->first();
    }

    /**
     * @return Builder<ClientInvoice>
     */
    private function cadenceInvoices(int $workspaceId, int $companyId, int $agreementId): Builder
    {
        return ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->where('client_company_id', $companyId)
            ->where('client_agreement_id', $agreementId)
            ->where(function (Builder $query): void {
                $query->whereNull('invoice_kind')
                    ->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value);
            });
    }
}
