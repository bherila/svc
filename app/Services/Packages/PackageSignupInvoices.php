<?php

namespace App\Services\Packages;

use App\Exceptions\ServicePackageRefused;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\InvoiceNumberAllocator;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use LogicException;
use RuntimeException;

/**
 * The one invoice a sign-up charges (ACC-3, EDGE-6), issued at acceptance.
 *
 * - **The first period** is billed by the cadence engine itself
 *   ({@see ClientInvoicingService::generateCadencePeriod()}), for the cycle
 *   that contains the agreement's start: the same invoice, with the same
 *   proration and the same cycle columns, that a later cadence run would have
 *   written. So the engine's own "already sold" and overlap guards see it, and
 *   the next regular invoice bills the next cycle, never this one again.
 * - **A setup fee** charged at sign-up is added to that invoice as manual
 *   adjustment lines - the one line type regeneration preserves - with no
 *   date, so they never widen the period the engine reconciles. With no first
 *   period it is the whole of an ad hoc invoice. It is never also a recurring
 *   item ({@see PackageAgreementTerms::recurringItems()}).
 *
 * Issued through {@see InvoiceLifecycleService::issue()}, so automatic client
 * delivery follows the company's own setting, due the day it is issued.
 *
 * Runs inside the acceptance transaction, on the draft agreement, after its
 * start is set. The caller has taken the billing maintenance share lock as
 * its first lock: generation locks time and tasks, which only a transaction
 * that began with it may do.
 */
final class PackageSignupInvoices
{
    public function __construct(
        private readonly ClientInvoicingService $invoicing,
        private readonly InvoiceLifecycleService $invoices,
        private readonly InvoiceNumberAllocator $numbers = new InvoiceNumberAllocator,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Issue the sign-up invoice, or return null when the version charges
     * nothing at sign-up.
     *
     * @throws ServicePackageRefused with `package_collected_in_square` for an agreement Square bills (X-2),
     *                               or `package_first_period_unbillable` when the engine refuses the first cycle
     */
    public function issue(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, PackageTerms $terms): ?ClientInvoice
    {
        if (! $terms->chargesAtSignup()) {
            return null;
        }
        // X-2: an agreement carrying the billed-in-Square marker is Square's
        // to collect, and Square is never written to.
        if ($agreement->square_series_key !== null) {
            throw ServicePackageRefused::because(PackageRefusal::CollectedInSquare);
        }
        $this->assertNothingBilledYet($workspace, $agreement);

        if ($terms->chargeFirstPeriod) {
            $invoice = $this->firstPeriod($company, $agreement);
            $this->addSetupFee($workspace, $agreement, $invoice, $terms);
        } else {
            $invoice = $this->setupFeeOnly($workspace, $company, $agreement, $terms);
        }

        $this->dueToday($workspace, $invoice);

        return $this->invoices->issue($invoice, $workspace);
    }

    /**
     * The setup fee lines sign-up charges: none unless the version charges them at sign-up.
     *
     * @return list<PackageComponentTerms>
     */
    public static function setupFees(PackageTerms $terms): array
    {
        if (! $terms->chargeSetupFeeAtSignup) {
            return [];
        }

        return array_values(array_filter($terms->components, static fn (PackageComponentTerms $component): bool => $component->kind === PackageComponentKind::SetupFee && $component->total() > 0));
    }

    /**
     * A draft agreement's sign-up invoice is written once. Asked with locking
     * reads of its invoices and their lines, which also takes both before the
     * numbering lock, as every invoice writer takes them.
     */
    private function assertNothingBilledYet(Workspace $workspace, ClientAgreement $agreement): void
    {
        $invoice = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->whereIn('status', InvoiceStatus::live())
            ->tap(Locks::forUpdate())
            ->first(['id']);
        $line = ClientInvoiceLine::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->tap(Locks::forUpdate())
            ->first(['id']);
        if ($invoice !== null || $line !== null) {
            throw new LogicException('A draft package agreement already has an invoice; its sign-up invoice is issued once.');
        }
    }

    private function firstPeriod(ClientCompany $company, ClientAgreement $agreement): ClientInvoice
    {
        try {
            return $this->invoicing->generateCadencePeriod($company, $agreement, Carbon::parse($agreement->starts_on->toDateString())->startOfDay());
        } catch (RuntimeException) {
            // The engine refused the cycle - another invoice of the company
            // already covers the period it reconciles. Nothing is half-made:
            // the acceptance rolls back with it.
            throw ServicePackageRefused::because(PackageRefusal::FirstPeriodUnbillable);
        }
    }

    private function addSetupFee(Workspace $workspace, ClientAgreement $agreement, ClientInvoice $invoice, PackageTerms $terms): void
    {
        $fees = self::setupFees($terms);
        if ($fees === []) {
            return;
        }
        $sort = 1 + (int) ClientInvoiceLine::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $invoice->id)->max('sort_order');
        foreach ($fees as $fee) {
            ClientInvoiceLine::query()->create([
                ...$this->line($fee, $sort++),
                'workspace_id' => $workspace->id,
                'client_invoice_id' => $invoice->id,
                'client_agreement_id' => $agreement->id,
            ]);
        }
        $invoice->recalculateTotals();
    }

    private function setupFeeOnly(Workspace $workspace, ClientCompany $company, ClientAgreement $agreement, PackageTerms $terms): ClientInvoice
    {
        $today = $this->clock->today($workspace)->toDateString();
        $sort = 1;

        return $this->invoices->createDraft($workspace, $company, [
            'invoice_number' => $this->numbers->next($workspace),
            'invoice_kind' => InvoiceKind::AdHoc->value,
            'client_agreement_id' => $agreement->id,
            'currency' => $terms->currency,
            'issue_date' => $today,
            'due_date' => $today,
        ], array_map(fn (PackageComponentTerms $fee): array => $this->line($fee, $sort++), self::setupFees($terms)));
    }

    /**
     * A cadence draft is dated the day its cycle begins, which for a start
     * part-way through a month is in the past; the sign-up invoice is due the
     * day it is issued, not overdue from the moment it exists.
     */
    private function dueToday(Workspace $workspace, ClientInvoice $invoice): void
    {
        $today = $this->clock->today($workspace)->toDateString();
        $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)->firstOrFail();
        if ($draft->due_date?->toDateString() === $today) {
            return;
        }
        $this->invoices->updateDraftDetails($draft, $workspace, AgentApiVersion::for($draft), ['due_date' => $today]);
    }

    /** @return array{type: string, description: string, quantity: string, unit_amount: int, tax_amount: int, total_amount: int, sort_order: int} */
    private function line(PackageComponentTerms $fee, int $sort): array
    {
        return [
            'type' => InvoiceLineType::Adjustment->value,
            'description' => $fee->description,
            'quantity' => $fee->quantity,
            'unit_amount' => $fee->amount,
            'tax_amount' => 0,
            'total_amount' => $fee->total(),
            'sort_order' => $sort,
        ];
    }
}
