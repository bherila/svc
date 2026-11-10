<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleChargeFinding;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists the agreements whose first billing period the engine charged a
 * recurring item twice in (#685), and whether that invoice went out.
 *
 * A quarterly, semi-annual or annual agreement started on a day other than
 * the 1st, with an item on that cadence and no anchor of its own
 * ({@see RecurringItemBiller::followsBillingCycles()}): until #685 the item
 * was charged on its start date and again on the 1st of its start month a
 * period later, both inside the first period anchored on the start. The
 * engine now charges such an item once per period, on the day the period
 * starts, so every period not yet invoiced - a regenerated draft included -
 * is billed once. What is already issued stays as issued: no invoice is
 * rewritten, and nothing here writes anything. Repaying an overcharge (a
 * reopen and regeneration while unpaid, or a credit) is the owner's
 * decision, and the listing is what they decide from.
 *
 * Every agreement of that shape is listed, a package's or not: a proposal's
 * item on its agreement's cadence had the same rule. Bounded: agreements in
 * batches, each batch's items, first invoices, their lines, and the public
 * ids that name them read in one query each, every one by the rows' own
 * workspace. Unscoped is the operator's reading; a tenant-facing caller
 * passes its workspace.
 */
final class FirstCycleChargeAuditor
{
    private const int BATCH = 200;

    /** @return list<FirstCycleChargeFinding> by agreement id */
    public function findings(?Workspace $workspace = null): array
    {
        $cadences = array_values(array_map(
            static fn (BillingCadence $cadence): string => $cadence->value,
            array_filter(BillingCadence::cases(), static fn (BillingCadence $cadence): bool => $cadence !== BillingCadence::Monthly),
        ));
        $findings = [];
        ClientAgreement::query()
            ->when($workspace instanceof Workspace, fn (Builder $agreements): Builder => $agreements->where('workspace_id', $workspace?->id))
            ->whereIn('billing_cadence', $cadences)
            ->orderBy('id')
            ->chunkById(self::BATCH, function (Collection $agreements) use (&$findings): void {
                array_push($findings, ...$this->batch($agreements));
            });

        return $findings;
    }

    /**
     * @param  Collection<int, ClientAgreement>  $agreements
     * @return list<FirstCycleChargeFinding>
     */
    private function batch(Collection $agreements): array
    {
        $agreements = $agreements->filter(static fn (ClientAgreement $agreement): bool => $agreement->starts_on->day !== 1)->keyBy('id');
        if ($agreements->isEmpty()) {
            return [];
        }
        $workspaceIds = $agreements->pluck('workspace_id')->unique()->values()->all();

        // Each item only under its own agreement's workspace.
        $items = [];
        foreach (ClientAgreementRecurringItem::query()->whereIn('workspace_id', $workspaceIds)->whereIn('client_agreement_id', $agreements->keys()->all())->get() as $item) {
            $agreement = $agreements->get($item->client_agreement_id);
            if ($agreement instanceof ClientAgreement && (int) $item->workspace_id === (int) $agreement->workspace_id && RecurringItemBiller::followsBillingCycles($agreement, $item)) {
                $items[$agreement->id][] = $item->id;
            }
        }
        // Filtered rather than `only()`, which re-indexes an Eloquent collection.
        $agreements = $agreements->filter(static fn (ClientAgreement $agreement): bool => isset($items[$agreement->id]));
        if ($agreements->isEmpty()) {
            return [];
        }

        // The first period starts on the agreement's start, so its invoice
        // names that day as its cycle start. The earliest live (non-void) one,
        // as the engine itself looks it up.
        $invoices = [];
        ClientInvoice::query()->whereIn('workspace_id', $workspaceIds)->whereIn('client_agreement_id', $agreements->keys()->all())
            ->where(fn (Builder $kind): Builder => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->whereIn('status', InvoiceStatus::live())
            ->where(function (Builder $first) use ($agreements): void {
                foreach ($agreements as $agreement) {
                    $first->orWhere(fn (Builder $one): Builder => $one->where('client_agreement_id', $agreement->id)->whereDate('cycle_start', $agreement->starts_on->toDateString()));
                }
            })
            ->orderBy('id')->get()
            ->each(function (ClientInvoice $invoice) use ($agreements, &$invoices): void {
                $agreement = $agreements->get($invoice->client_agreement_id);
                if ($agreement instanceof ClientAgreement && (int) $invoice->workspace_id === (int) $agreement->workspace_id && (int) $invoice->client_company_id === (int) $agreement->client_company_id) {
                    $invoices[$agreement->id] ??= $invoice;
                }
            });

        $lines = [];
        if ($invoices !== []) {
            $agreementOf = [];
            foreach ($invoices as $agreementId => $invoice) {
                $agreementOf[$invoice->id] = $agreementId;
            }
            $lineRows = ClientInvoiceLine::query()->whereIn('workspace_id', $workspaceIds)->whereIn('client_invoice_id', array_keys($agreementOf))
                ->whereIn('client_agreement_recurring_item_id', array_merge(...array_values($items)))
                ->get(['id', 'workspace_id', 'client_invoice_id', 'client_agreement_recurring_item_id', 'line_date', 'total_amount']);
            foreach ($lineRows as $line) {
                $agreementId = $agreementOf[$line->client_invoice_id];
                if ((int) $line->workspace_id === (int) $invoices[$agreementId]->workspace_id && in_array((int) $line->client_agreement_recurring_item_id, $items[$agreementId], true)) {
                    $lines[$agreementId][] = ['item' => (int) $line->client_agreement_recurring_item_id, 'line_date' => (string) $line->line_date?->toDateString(), 'id' => $line->id, 'total' => (int) $line->total_amount];
                }
            }
        }

        $ids = $agreements->keys()->all();
        $workspaces = [];
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get(['id', 'public_id']) as $named) {
            $workspaces[$named->id] = $named->public_id;
        }
        $companies = [];
        foreach (DB::table('client_companies')->whereIn('workspace_id', $workspaceIds)->whereIn('id', $agreements->pluck('client_company_id')->unique()->values()->all())->get(['id', 'workspace_id', 'public_id']) as $company) {
            $companies[(int) $company->workspace_id][(int) $company->id] = (string) $company->public_id;
        }
        // Signed up for through a package, or moved from one.
        $packaged = [];
        foreach (['service_package_enrollments', 'service_package_migration_targets'] as $table) {
            foreach (DB::table($table)->whereIn('workspace_id', $workspaceIds)->whereIn('client_agreement_id', $ids)->get(['workspace_id', 'client_agreement_id']) as $row) {
                $packaged[(int) $row->workspace_id][(int) $row->client_agreement_id] = true;
            }
        }

        $findings = [];
        foreach ($agreements as $agreement) {
            $invoice = $invoices[$agreement->id] ?? null;
            $findings[] = FirstCycleChargeFinding::classify(
                [
                    'workspace' => $workspaces[$agreement->workspace_id] ?? 'missing',
                    'company' => $companies[$agreement->workspace_id][$agreement->client_company_id] ?? 'missing',
                    'agreement' => $agreement->public_id,
                    'cadence' => $agreement->effectiveBillingCadence()->value,
                    'starts_on' => $agreement->starts_on->toDateString(),
                    'package' => isset($packaged[$agreement->workspace_id][$agreement->id]),
                    'items' => count($items[$agreement->id]),
                ],
                $invoice === null ? null : ['public_id' => $invoice->public_id, 'status' => (string) $invoice->status, 'currency' => (string) $invoice->currency],
                $lines[$agreement->id] ?? [],
            );
        }

        return $findings;
    }
}
