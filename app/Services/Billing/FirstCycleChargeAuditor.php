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
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists the agreements whose first periods the engine charged a recurring
 * item an extra time in (#685, #695), and whether that invoice went out.
 *
 * A quarterly, semi-annual or annual agreement started on a day other than
 * the 1st, with a recurring item and no anchor of its own, on its cadence or
 * another ({@see RecurringItemBiller::followsBillingCycles()}): until #685 and
 * #695 the item was charged on its start date and again on the 1st of its
 * start month a period of its own later, both inside its first period - a
 * quarterly item in a quarter from 9 January on 9 January and 1 April, a
 * monthly one four times in that quarter, an annual one on 9 January and
 * 1 January. The engine now places such an item on the agreement's periods,
 * so every period not yet invoiced - a regenerated draft included - charges
 * it as often as its cadence says. What is already issued stays as issued:
 * no invoice is rewritten, and nothing here writes anything. Repaying an
 * overcharge (a reopen and regeneration while unpaid, or a credit) is the
 * owner's decision, and the listing is what they decide from.
 *
 * The extra charge always falls inside the item's first period of its own
 * cadence, counted from the agreement period the item starts in, so the
 * invoices read are those of the periods spanning each item's first group,
 * each against the charges the engine now places in it
 * ({@see FirstCycleChargeFinding}). An off-cadence item added later is read
 * from its own start: an annual item from 20 April on a quarterly agreement
 * from 9 January was charged on 20 April and on 1 April a year on, on the
 * invoice of the period from 9 January.
 *
 * Every agreement of that shape is listed, a package's or not: a proposal's
 * item had the same rule. Bounded: agreements in batches, each batch's
 * items, the invoices of its first periods, their lines, and the public ids
 * that name them read in one query each, every one by the rows' own
 * workspace; what the engine places is then worked out from those rows,
 * without a query. Unscoped is the operator's reading; a tenant-facing
 * caller passes its workspace.
 */
final class FirstCycleChargeAuditor
{
    private const int BATCH = 200;

    public function __construct(
        private readonly BillingCycleResolver $cycles = new BillingCycleResolver,
        private readonly RecurringItemBiller $biller = new RecurringItemBiller,
    ) {}

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
        $placed = [];
        foreach (ClientAgreementRecurringItem::query()->whereIn('workspace_id', $workspaceIds)->whereIn('client_agreement_id', $agreements->keys()->all())->orderBy('id')->get() as $item) {
            $agreement = $agreements->get($item->client_agreement_id);
            if ($agreement instanceof ClientAgreement && (int) $item->workspace_id === (int) $agreement->workspace_id && RecurringItemBiller::followsBillingCycles($agreement, $item)) {
                $items[$agreement->id][] = $item->id;
                // Placed as if still active: a charge made before it was
                // deactivated was due.
                $placed[$agreement->id][] = (clone $item)->forceFill(['is_active' => true]);
            }
        }
        // Filtered rather than `only()`, which re-indexes an Eloquent collection.
        $agreements = $agreements->filter(static fn (ClientAgreement $agreement): bool => isset($items[$agreement->id]));
        if ($agreements->isEmpty()) {
            return [];
        }

        // The agreement's periods each item's first group of its own cadence
        // spans, counted from the period the item starts in (the agreement's
        // first when it started before it): one for an item on the agreement's
        // cadence or a shorter one, a year of them for an annual licence in a
        // quarterly service. An item added later is read from its own start,
        // so the extra charge in its first group is inside the window even a
        // year or more past the agreement's start. The union over the items.
        $periods = [];
        foreach ($agreements as $agreement) {
            $agreement->setRelation('recurringItems', new Collection($placed[$agreement->id]));
            $periodMonths = $agreement->effectiveBillingCadence()->monthsInCycle();
            $agreementStart = Carbon::instance($agreement->retainerStartsOn())->startOfDay();
            $periods[$agreement->id] = [];
            foreach ($placed[$agreement->id] as $item) {
                $itemStart = Carbon::instance($item->start_date ?? $agreement->starts_on)->startOfDay();
                $origin = $itemStart->gt($agreementStart) ? $itemStart : $agreementStart;
                $months = max($periodMonths, $item->charge_cadence?->monthsInPeriod() ?? 0);
                $count = intdiv($months, $periodMonths);
                $taken = 0;
                // A month past the span, so the last period is never cut short.
                foreach ($this->cycles->cyclesForAgreement($agreement, $origin->copy()->addMonths($months + 1)) as $cycle) {
                    if ($cycle->end->lt($origin)) {
                        continue;
                    }
                    if ($taken++ === $count) {
                        break;
                    }
                    $periods[$agreement->id][$cycle->start->toDateString()] = $cycle;
                }
            }
            ksort($periods[$agreement->id]);
        }

        // Each period's invoice names that period's first day as its cycle
        // start. The earliest live (non-void) one, as the engine itself looks
        // it up.
        $invoices = [];
        ClientInvoice::query()->whereIn('workspace_id', $workspaceIds)->whereIn('client_agreement_id', $agreements->keys()->all())
            ->where(fn (Builder $kind): Builder => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->whereIn('status', InvoiceStatus::live())
            ->where(function (Builder $first) use ($agreements, $periods): void {
                foreach ($agreements as $agreement) {
                    $first->orWhere(fn (Builder $one): Builder => $one->where('client_agreement_id', $agreement->id)->where(function (Builder $starts) use ($agreement, $periods): void {
                        foreach (array_keys($periods[$agreement->id]) as $periodStart) {
                            $starts->orWhereDate('cycle_start', $periodStart);
                        }
                    }));
                }
            })
            ->orderBy('id')->get()
            ->each(function (ClientInvoice $invoice) use ($agreements, &$invoices): void {
                $agreement = $agreements->get($invoice->client_agreement_id);
                if ($agreement instanceof ClientAgreement && (int) $invoice->workspace_id === (int) $agreement->workspace_id && (int) $invoice->client_company_id === (int) $agreement->client_company_id) {
                    $invoices[$agreement->id][(string) $invoice->cycle_start?->toDateString()] ??= $invoice;
                }
            });

        $lines = [];
        if ($invoices !== []) {
            $agreementOf = [];
            foreach ($invoices as $agreementId => $byPeriod) {
                foreach ($byPeriod as $invoice) {
                    $agreementOf[$invoice->id] = $agreementId;
                }
            }
            $lineRows = ClientInvoiceLine::query()->whereIn('workspace_id', $workspaceIds)->whereIn('client_invoice_id', array_keys($agreementOf))
                ->whereIn('client_agreement_recurring_item_id', array_merge(...array_values($items)))
                ->get(['id', 'workspace_id', 'client_invoice_id', 'client_agreement_recurring_item_id', 'line_date', 'total_amount']);
            foreach ($lineRows as $line) {
                $agreementId = $agreementOf[$line->client_invoice_id];
                if ((int) $line->workspace_id === (int) $agreements->get($agreementId)?->workspace_id && in_array((int) $line->client_agreement_recurring_item_id, $items[$agreementId], true)) {
                    $lines[$line->client_invoice_id][] = ['item' => (int) $line->client_agreement_recurring_item_id, 'line_date' => (string) $line->line_date?->toDateString(), 'id' => $line->id, 'total' => (int) $line->total_amount];
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
            $read = [];
            foreach ($periods[$agreement->id] as $periodStart => $cycle) {
                $invoice = $invoices[$agreement->id][$periodStart] ?? null;
                if ($invoice === null) {
                    continue;
                }
                $expected = [];
                foreach ($this->biller->linesForCycle($agreement, $cycle->start, $cycle->end) as $charge) {
                    $expected[$charge['item']->id] = ($expected[$charge['item']->id] ?? 0) + 1;
                }
                $read[] = ['public_id' => $invoice->public_id, 'status' => (string) $invoice->status, 'currency' => (string) $invoice->currency, 'expected' => $expected, 'lines' => $lines[$invoice->id] ?? []];
            }
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
                $read,
            );
        }

        return $findings;
    }
}
