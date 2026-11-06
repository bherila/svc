<?php

namespace App\Queries\Approval;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Services\Approval\PortalApprovalAuthority;
use App\Services\Authorization\PortalAccess;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Approval\ClientApprovalSource;
use App\Support\Approval\ClientApprovalVerdict;
use App\Support\Approval\ConsultantTimeRelease;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\SubcontractorBillingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "To approve" on the portal home (docs/ux-overhaul.md sections 7b' and 7c).
 *
 * The hours and expenses a client contact has yet to decide on, grouped by
 * vendor - the workspace they are billed by - and calendar month. Built from
 * this one client's rows in this one workspace: two bounded queries, however
 * many entries there are, with the invoice behind each loaded in one more.
 * Fanning out over every workspace a person belongs to is the next slice; this
 * class takes the company so that slice can call it once per membership.
 *
 * What it leaves out, deliberately:
 *
 * - Anything the viewer's membership does not reach (the same project scope as
 *   the rest of the portal), anything not billable, and anything the client
 *   was never shown: an entry with no client-facing description is not
 *   disclosed, exactly as on the home's recent time.
 * - Direct subcontractor time, which is not on this vendor's invoice.
 * - Hours with no rate and no amount: only minutes are sent. Expenses carry
 *   their amount because that is what the client is approving.
 * - Receipts. An expense receipt has no client-sharing audience by design, so
 *   there is nothing to show inline until that is decided.
 *
 * Row selection and the verdict are both made here and are the single
 * definition the approve and dispute writes resolve their targets through, so
 * a row that is not on this screen cannot be approved from it.
 */
final class PortalApprovalQueue
{
    /** Oldest rows win the cap: they are the ones that have waited longest. */
    public const TIME_LIMIT = 300;

    public const EXPENSE_LIMIT = 150;

    public function __construct(
        private readonly PortalApprovalAuthority $authority,
        private readonly PortalAccess $access,
    ) {}

    /**
     * @return array<string, mixed>|null null when this person may not approve for this client
     */
    public function for(ClientCompany $company, User $viewer): ?array
    {
        if (! $this->authority->may($company, $viewer)) {
            return null;
        }

        $scope = $this->access->visibleProjectIds($company, $viewer);
        $time = $this->timeRows($company, $scope);
        $expenses = $this->expenseRows($company, $scope);

        $vendor = (string) ($company->workspace->name ?? '');
        $groups = [];
        foreach ($time as $entry) {
            $key = ApprovalPeriod::containing($entry->worked_on->toDateString())->key();
            $groups[$key] ??= ['hours' => [], 'expenses' => []];
            $groups[$key]['hours'][] = $entry;
        }
        foreach ($expenses as $expense) {
            $key = ApprovalPeriod::containing($expense->spent_on->toDateString())->key();
            $groups[$key] ??= ['hours' => [], 'expenses' => []];
            $groups[$key]['expenses'][] = $expense;
        }
        krsort($groups);

        $presented = [];
        foreach ($groups as $key => $rows) {
            $period = ApprovalPeriod::fromKey((string) $key);
            if ($period === null) {
                continue;
            }
            $presented[] = $this->group($company, $vendor, $period, $rows['hours'], $rows['expenses']);
        }

        return [
            'approve_href' => route('portal.approvals.approve', $company, absolute: false),
            'dispute_href' => route('portal.approvals.dispute', $company, absolute: false),
            'groups' => $presented,
            'truncated' => $time->count() >= self::TIME_LIMIT || $expenses->count() >= self::EXPENSE_LIMIT,
        ];
    }

    /**
     * Time the client has yet to decide on or has disputed, oldest first.
     *
     * @param  list<int>|null  $scope  the viewer's project scope; null is the whole client
     * @return Collection<int, ClientTimeEntry>
     */
    public function timeRows(ClientCompany $company, ?array $scope): Collection
    {
        return $this->timeQuery($company, $scope)
            ->with(['project:id,name', 'invoiceLines:id,client_invoice_id', 'invoiceLines.invoice:id,public_id,workspace_id,invoice_number,status,is_visible_to_client'])
            ->orderBy('worked_on')
            ->orderBy('id')
            ->limit(self::TIME_LIMIT)
            ->get()
            ->filter(fn (ClientTimeEntry $entry): bool => $this->verdictOfTime($entry) !== ClientApprovalVerdict::Approved
                && $this->verdictOfTime($entry) !== ClientApprovalVerdict::ApprovedByPayment)
            ->values();
    }

    /**
     * @param  list<int>|null  $scope
     * @return Collection<int, ClientExpense>
     */
    public function expenseRows(ClientCompany $company, ?array $scope): Collection
    {
        return $this->expenseQuery($company, $scope)
            ->with(['project:id,name', 'invoiceLine:id,client_invoice_id', 'invoiceLine.invoice:id,public_id,workspace_id,invoice_number,status,is_visible_to_client'])
            ->orderBy('spent_on')
            ->orderBy('id')
            ->limit(self::EXPENSE_LIMIT)
            ->get()
            ->filter(fn (ClientExpense $expense): bool => $this->verdictOfExpense($expense) !== ClientApprovalVerdict::Approved
                && $this->verdictOfExpense($expense) !== ClientApprovalVerdict::ApprovedByPayment)
            ->values();
    }

    public function verdictOfTime(ClientTimeEntry $entry): ClientApprovalVerdict
    {
        return ClientApprovalVerdict::judge(
            $entry->client_approval_source,
            $entry->client_approved_at !== null,
            $entry->client_disputed_at !== null,
            $entry->invoiceLines->contains(fn ($line): bool => $line->invoice?->status === InvoiceStatus::Paid->value),
        );
    }

    public function verdictOfExpense(ClientExpense $expense): ClientApprovalVerdict
    {
        return ClientApprovalVerdict::judge(
            $expense->client_approval_source,
            $expense->client_approved_at !== null,
            $expense->client_disputed_at !== null,
            $expense->invoiceLine?->invoice?->status === InvoiceStatus::Paid->value,
        );
    }

    /**
     * Candidate time, before the verdict is read in code.
     *
     * @param  list<int>|null  $scope
     * @return Builder<ClientTimeEntry>
     */
    public function timeQuery(ClientCompany $company, ?array $scope): Builder
    {
        return ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->approved()
            ->where('is_billable', true)
            ->where('is_visible_to_client', true)
            ->whereNotNull('client_visible_description')
            // Every mode but direct, spelled as the pure rule decides it.
            ->where(fn (Builder $mode) => $mode->whereNull('subcontractor_billing_mode')
                ->orWhereIn('subcontractor_billing_mode', array_values(array_map(
                    fn (SubcontractorBillingMode $case): string => $case->value,
                    array_filter(SubcontractorBillingMode::cases(), ConsultantTimeRelease::clientApproves(...)),
                ))))
            ->when($scope !== null, fn (Builder $rows) => $rows->whereIn('client_project_id', $scope ?? []))
            ->where(fn (Builder $open) => $open
                ->whereNotNull('client_disputed_at')
                ->orWhereNull('client_approved_at')
                ->orWhere(fn (Builder $payment) => $payment
                    ->where('client_approval_source', ClientApprovalSource::Payment->value)
                    ->whereDoesntHave('invoiceLines', fn (Builder $line) => $this->paidLine($line, $company))));
    }

    /**
     * @param  list<int>|null  $scope
     * @return Builder<ClientExpense>
     */
    public function expenseQuery(ClientCompany $company, ?array $scope): Builder
    {
        return ClientExpense::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->approved()
            ->when($scope !== null, fn (Builder $rows) => $rows->whereIn('client_project_id', $scope ?? []))
            ->where(fn (Builder $open) => $open
                ->whereNotNull('client_disputed_at')
                ->orWhereNull('client_approved_at')
                ->orWhere(fn (Builder $payment) => $payment
                    ->where('client_approval_source', ClientApprovalSource::Payment->value)
                    ->whereDoesntHave('invoiceLine', fn (Builder $line) => $this->paidLine($line, $company))));
    }

    /**
     * An invoice line whose invoice is paid in full, in this workspace.
     *
     * @param  Builder<*>  $line
     */
    private function paidLine(Builder $line, ClientCompany $company): void
    {
        $line->where('client_invoice_lines.workspace_id', $company->workspace_id)
            ->whereHas('invoice', fn (Builder $invoice) => $invoice
                ->where('client_invoices.workspace_id', $company->workspace_id)
                ->where('status', InvoiceStatus::Paid->value));
    }

    /**
     * @param  list<ClientTimeEntry>  $hours
     * @param  list<ClientExpense>  $expenses
     * @return array<string, mixed>
     */
    private function group(ClientCompany $company, string $vendor, ApprovalPeriod $period, array $hours, array $expenses): array
    {
        $pendingHours = array_values(array_filter($hours, fn (ClientTimeEntry $e): bool => $this->verdictOfTime($e)->awaitsClient()));
        $pendingExpenses = array_values(array_filter($expenses, fn (ClientExpense $e): bool => $this->verdictOfExpense($e)->awaitsClient()));
        $currencies = array_values(array_unique(array_map(fn (ClientExpense $e): string => $e->currency, $pendingExpenses)));

        return [
            'key' => $period->key(),
            'vendor' => $vendor,
            'period' => $period->key(),
            'period_label' => $period->label(),
            'hours' => [
                'minutes' => array_sum(array_map(fn (ClientTimeEntry $e): int => $e->minutes, $pendingHours)),
                'pending_count' => count($pendingHours),
                'entries' => array_map(fn (ClientTimeEntry $e): array => $this->hourRow($company, $e), $hours),
            ],
            'expenses' => [
                'pending_count' => count($pendingExpenses),
                // A total in one currency, or none: summing across currencies
                // would be a number nobody is billed.
                'amount' => count($currencies) === 1 ? array_sum(array_map(fn (ClientExpense $e): int => $e->amount, $pendingExpenses)) : null,
                'currency' => count($currencies) === 1 ? $currencies[0] : null,
                'items' => array_map(fn (ClientExpense $e): array => $this->expenseRow($company, $e), $expenses),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function hourRow(ClientCompany $company, ClientTimeEntry $entry): array
    {
        $verdict = $this->verdictOfTime($entry);

        return [
            'id' => $entry->public_id,
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes,
            // Never the internal note: the query already requires this one.
            'description' => $entry->client_visible_description,
            'project' => $entry->project?->name,
            'state' => $verdict->value,
            'dispute_note' => $verdict === ClientApprovalVerdict::Disputed ? $entry->client_dispute_note : null,
            'invoice' => $this->invoiceOf($company, $entry->invoiceLines->map->invoice->filter()->first()),
        ];
    }

    /** @return array<string, mixed> */
    private function expenseRow(ClientCompany $company, ClientExpense $expense): array
    {
        $verdict = $this->verdictOfExpense($expense);

        return [
            'id' => $expense->public_id,
            'spent_on' => $expense->spent_on->toDateString(),
            'description' => $expense->description,
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'project' => $expense->project?->name,
            'state' => $verdict->value,
            'dispute_note' => $verdict === ClientApprovalVerdict::Disputed ? $expense->client_dispute_note : null,
            'invoice' => $this->invoiceOf($company, $expense->invoiceLine?->invoice),
        ];
    }

    /**
     * The invoice behind a row, only where the client can open it.
     *
     * @return array{number: string, href: string}|null
     */
    private function invoiceOf(ClientCompany $company, ?ClientInvoice $invoice): ?array
    {
        if (! $invoice instanceof ClientInvoice
            || ! $invoice->is_visible_to_client
            || ! in_array($invoice->status, InvoiceStatus::charged(), true)) {
            return null;
        }

        return [
            'number' => (string) $invoice->invoice_number,
            'href' => route('portal.invoice', [$company, $invoice], absolute: false),
        ];
    }
}
