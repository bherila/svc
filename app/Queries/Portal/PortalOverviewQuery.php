<?php

namespace App\Queries\Portal;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoicePaymentOptionsReader;
use App\Services\Portal\NextInvoiceEstimator;
use App\Support\Billing\InvoiceDeferredWorkDisclosure;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\ClientHome\InvoiceSummary;
use App\Support\Portal\PortalBalance;
use App\Support\Portal\PortalPayTarget;
use App\Support\Portal\RetainerPeriodUse;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectionInputs;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The client portal's home, above the fold (docs/ux-overhaul.md section 6): the
 * Balance card, the This period card and the invoice history.
 *
 * Fail-closed in the way the rest of the portal is. Every invoice comes through
 * {@see PortalInvoiceQuery}, so a draft, an invoice the operator did not
 * disclose, another company's, or one outside a project-scoped member's grant
 * never reaches the page. The agreement behind This period must be disclosed to
 * the client, not a draft, and inside the viewer's project scope. Nothing here
 * reads a rate, a cost, a note or a workspace-internal field, and nothing is
 * keyed by an id the browser could change.
 *
 * Bounded: a fixed number of queries whatever the number of invoices, lines or
 * time entries (the ledger and billed-charge reads are one agreement's). The
 * forecast is the next invoice and nothing further.
 */
final class PortalOverviewQuery
{
    public const int RECENT_INVOICES = 5;

    /** Open invoices read for the balance; a client with more is not a case this card is for. */
    public const int OPEN_INVOICES = 200;

    public function __construct(
        private readonly PortalAccess $portalAccess,
        private readonly PortalInvoiceQuery $invoices,
        private readonly InvoiceLedgerBuilder $ledgers,
        private readonly NextInvoiceEstimator $estimator,
        private readonly BillingCycleResolver $cycles,
        private readonly WorkspaceClock $clock,
        private readonly InvoicePaymentOptionsReader $payments,
    ) {}

    /**
     * @return array{balance: array<string, mixed>, last_payment: array<string, mixed>|null, pay: array{href: string|null, invoice_href: string|null}, this_period: array<string, mixed>|null, recent_invoices: list<array<string, mixed>>}
     */
    public function for(ClientCompany $company, User $viewer): array
    {
        $workspace = $company->workspace;
        $today = $this->clock->today($workspace);

        $open = $this->invoices->visibleTo($company, $viewer)
            ->whereIn('status', InvoiceStatus::collectible())
            ->where('balance_amount', '>', 0)
            ->orderBy('id')
            ->limit(self::OPEN_INVOICES)
            ->get();

        $balance = new PortalBalance(
            array_values($open->map(fn (ClientInvoice $invoice): array => [
                'id' => (string) $invoice->public_id,
                'number' => (string) $invoice->invoice_number,
                'balance' => (int) $invoice->balance_amount,
                'currency' => (string) $invoice->currency,
                'due_date' => $invoice->due_date?->toDateString(),
            ])->all()),
            $today->toDateString(),
        );
        $facts = $balance->toArray();

        $recent = $this->invoices->visibleTo($company, $viewer)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_INVOICES)
            ->get();

        return [
            'balance' => $facts,
            'last_payment' => $this->lastPayment($company, $viewer),
            'pay' => $this->pay($company, $open, $facts['first']['id'] ?? null),
            'this_period' => $this->thisPeriod($company, $viewer, $today, $recent->first()),
            'recent_invoices' => array_values($recent->map(fn (ClientInvoice $invoice): array => InvoiceSummary::fromInvoice(
                $invoice, route('portal.invoice', [$company, $invoice], absolute: false),
            )->toArray())->all()),
        ];
    }

    /**
     * The last money received on an invoice this client can see: a succeeded
     * payment, newest by the day it was received.
     *
     * @return array{amount: int, currency: string, received_on: string}|null
     */
    private function lastPayment(ClientCompany $company, User $viewer): ?array
    {
        $payment = ClientInvoicePayment::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('status', InvoicePaymentStatus::Succeeded->value)
            ->whereNotNull('received_on')
            ->whereIn('client_invoice_id', $this->invoices->visibleTo($company, $viewer)->select('client_invoices.id'))
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->first();

        if (! $payment instanceof ClientInvoicePayment || $payment->received_on === null) {
            return null;
        }

        return [
            'amount' => max(0, (int) $payment->amount - (int) $payment->refunded_amount),
            'currency' => (string) $payment->currency,
            'received_on' => $payment->received_on->toDateString(),
        ];
    }

    /**
     * Where "Pay" goes, from the same options the invoice page offers
     * ({@see InvoicePaymentOptionsReader}): the invoice's pay section when the
     * workspace takes card payment, Square's hosted page when that is all
     * there is. The invoice itself is always linked. Both are finished here;
     * the browser assembles nothing. A null `href` is a card with no Pay button.
     *
     * @param  Collection<int, ClientInvoice>  $open
     * @return array{href: string|null, invoice_href: string|null}
     */
    private function pay(ClientCompany $company, Collection $open, ?string $firstPublicId): array
    {
        $first = $firstPublicId === null ? null : $open->firstWhere('public_id', $firstPublicId);
        if (! $first instanceof ClientInvoice) {
            return ['href' => null, 'invoice_href' => null];
        }

        $invoiceHref = route('portal.invoice', [$company, $first], absolute: false);

        return [
            'href' => PortalPayTarget::href($this->payments->for($company->workspace, $first), $invoiceHref),
            'invoice_href' => $invoiceHref,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function thisPeriod(ClientCompany $company, User $viewer, CarbonImmutable $today, ?ClientInvoice $latestInvoice): ?array
    {
        $agreement = $this->agreement($company, $viewer, $today);
        if (! $agreement instanceof ClientAgreement) {
            return null;
        }

        $projected = ProjectedAgreement::fromModel($agreement, (string) $company->public_id, (string) $company->name);
        $cycle = $this->cycles->cycleContaining($projected, Carbon::instance($today->max($projected->startsOn)));
        $start = $cycle->start->toDateString();
        $end = $cycle->end->toDateString();

        $use = $this->use($company, $agreement, $today, $start, $end);
        $estimate = $this->estimator->estimate(
            $projected,
            $today,
            $this->billedCharges($company, $agreement, $today),
            $use === null ? 0.0 : $use->overageHours,
            $agreement->hourly_rate_amount,
        );

        return [
            'agreement' => [
                'id' => (string) $agreement->public_id,
                'title' => (string) $agreement->title,
                'href' => route('portal.agreement', [$company, $agreement], absolute: false),
            ],
            'period_start' => $start,
            'period_end' => $end,
            'use' => $use?->toArray(),
            'next_invoice' => $estimate?->toArray(),
            'still_deferred' => $this->stillDeferred($latestInvoice),
        ];
    }

    /**
     * The agreement the card is about: disclosed to the client, not a draft,
     * active, in force today and inside the viewer's project scope. Where
     * several qualify, the most recently started one.
     */
    private function agreement(ClientCompany $company, User $viewer, CarbonImmutable $today): ?ClientAgreement
    {
        $visibleProjectIds = $this->portalAccess->visibleProjectIds($company, $viewer);

        $agreement = ClientAgreement::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $today->toDateString())
            ->where(fn (Builder $ends): Builder => $ends->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()))
            ->when($visibleProjectIds !== null, fn (Builder $query): Builder => $query
                ->where(fn (Builder $scope): Builder => $scope
                    ->whereNull('client_project_id')
                    ->orWhereIn('client_project_id', $visibleProjectIds ?? [])))
            ->with(['recurringItems' => fn (HasMany $items) => $items
                ->where('workspace_id', $company->workspace_id)->orderBy('sort_order')->orderBy('id')])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        return $agreement instanceof ClientAgreement ? $agreement : null;
    }

    /** This period's retainer use from the agreement's capacity ledger, or null when it cannot be read or grants nothing. */
    private function use(ClientCompany $company, ClientAgreement $agreement, CarbonImmutable $today, string $start, string $end): ?RetainerPeriodUse
    {
        try {
            /** @var list<MonthSummary> $ledger */
            $ledger = array_values($this->ledgers->buildAgreementLedgerThrough($company, $agreement, Carbon::instance($today)));
        } catch (RuntimeException|DomainException) {
            return null;
        }

        $use = RetainerPeriodUse::forPeriod($ledger, $start, $end);

        return $use !== null && $use->hoursIncluded > 0 ? $use : null;
    }

    /**
     * What charged invoices have already sold on this agreement from last
     * month on: a retainer cycle by the day it begins, an item by the day it is
     * charged. One read, scoped to the workspace and the agreement.
     *
     * @return array<string, true>
     */
    private function billedCharges(ClientCompany $company, ClientAgreement $agreement, CarbonImmutable $today): array
    {
        $rows = ClientInvoiceLine::query()
            ->toBase()
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
            ->where('client_invoice_lines.workspace_id', $company->workspace_id)
            ->where('client_invoices.workspace_id', $company->workspace_id)
            ->where('client_invoices.client_company_id', $company->id)
            ->whereIn('client_invoices.status', InvoiceStatus::charged())
            ->whereIn('client_invoice_lines.type', [InvoiceLineType::Retainer->value, InvoiceLineType::RecurringItem->value])
            ->whereNotNull('client_invoice_lines.line_date')
            ->where('client_invoice_lines.line_date', '>=', $today->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->where(fn ($owner) => $owner
                ->where('client_invoice_lines.client_agreement_id', $agreement->id)
                ->orWhere(fn ($invoice) => $invoice->whereNull('client_invoice_lines.client_agreement_id')->where('client_invoices.client_agreement_id', $agreement->id)))
            ->get([
                'client_invoice_lines.type',
                'client_invoice_lines.line_date',
                'client_invoice_lines.client_agreement_recurring_item_id as item_id',
            ]);

        $keys = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->line_date, 0, 10);
            if ($row->type === InvoiceLineType::RecurringItem->value) {
                if ($row->item_id !== null) {
                    $keys[ProjectionInputs::itemKey((int) $row->item_id, $date)] = true;
                }

                continue;
            }
            $keys[ProjectionInputs::retainerKey((int) $agreement->id, $date)] = true;
        }

        return $keys;
    }

    /**
     * Work still deferred as the latest invoice stated it, through the existing
     * disclosure: a client copy shows it only when the agreement opts in, read
     * as the setting stands now. Totals only; the itemised list stays on the
     * invoice page.
     *
     * @return array{entry_count: int, total_hours: float, oldest_worked_on: string}|null
     */
    private function stillDeferred(?ClientInvoice $invoice): ?array
    {
        if (! $invoice instanceof ClientInvoice) {
            return null;
        }

        $data = InvoiceDeferredWorkDisclosure::documentData($invoice, InvoiceLineDetail::CLIENT);

        return $data === null ? null : [
            'entry_count' => $data['entry_count'],
            'total_hours' => $data['total_hours'],
            'oldest_worked_on' => $data['oldest_worked_on'],
        ];
    }
}
