<?php

namespace App\Services\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\ChargeCadence;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Collection;

/**
 * Audit non-monthly agreements started mid-month affected by duplicate
 * first-cycle recurring charges (issue #685).
 */
final class PackageRecurringIncidenceAuditor
{
    /**
     * @return array{
     *     summary: array{
     *         agreements_checked: int,
     *         agreements_affected: int,
     *         charged_invoices_affected: int,
     *         draft_invoices_affected: int,
     *         unbilled_agreements: int,
     *         total_overcharge_cents: int,
     *     },
     *     agreements: list<array{
     *         agreement_id: string,
     *         workspace_id: string,
     *         company_name: string,
     *         cadence: string,
     *         starts_on: string,
     *         status: string,
     *         disposition: string,
     *         affected_items: list<array{
     *             item_id: string,
     *             description: string,
     *             cadence: string,
     *             amount_cents: int,
     *         }>,
     *         affected_invoices: list<array{
     *             invoice_id: string,
     *             status: string,
     *             cycle_start: string|null,
     *             cycle_end: string|null,
     *             line_count: int,
     *             overcharge_cents: int,
     *         }>,
     *     }>
     * }
     */
    public function report(?Workspace $workspace = null): array
    {
        $nonMonthlyCadences = [
            BillingCadence::Quarterly->value,
            BillingCadence::SemiAnnual->value,
            BillingCadence::Annual->value,
        ];

        $query = ClientAgreement::query()
            ->with(['recurringItems', 'clientCompany', 'workspace'])
            ->whereIn('billing_cadence', $nonMonthlyCadences);

        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }

        $agreementsChecked = 0;
        $agreementsAffected = 0;
        $chargedInvoicesAffected = 0;
        $draftInvoicesAffected = 0;
        $unbilledAgreements = 0;
        $totalOverchargeCents = 0;
        $records = [];

        $chargedStatuses = InvoiceStatus::charged();

        $query->chunkById(100, function (Collection $agreements) use (
            &$agreementsChecked,
            &$agreementsAffected,
            &$chargedInvoicesAffected,
            &$draftInvoicesAffected,
            &$unbilledAgreements,
            &$totalOverchargeCents,
            &$records,
            $chargedStatuses
        ): void {
            foreach ($agreements as $agreement) {
                // Must have started mid-month (day of month != 1)
                if ($agreement->starts_on === null || (int) $agreement->starts_on->day === 1) {
                    continue;
                }

                // Check for active unanchored periodic recurring items on the agreement
                $unanchoredItems = $agreement->recurringItems->filter(function ($item): bool {
                    $cadence = $item->charge_cadence;
                    $isPeriodic = $cadence !== null && in_array($cadence, [
                        ChargeCadence::Quarterly,
                        ChargeCadence::SemiAnnual,
                        ChargeCadence::Annual,
                    ], true);

                    return $isPeriodic
                        && $item->anchor_day === null
                        && $item->anchor_month === null
                        && $item->is_active !== false;
                });

                if ($unanchoredItems->isEmpty()) {
                    continue;
                }

                $agreementsChecked++;

                $invoices = ClientInvoice::query()
                    ->where('workspace_id', $agreement->workspace_id)
                    ->where('client_agreement_id', $agreement->id)
                    ->where('status', '!=', InvoiceStatus::Void->value)
                    ->with('lines')
                    ->orderBy('cycle_start')
                    ->orderBy('id')
                    ->get();

                $affectedInvoices = [];
                $hasChargedDuplicate = false;
                $hasDraftDuplicate = false;

                foreach ($invoices as $invoice) {
                    foreach ($unanchoredItems as $item) {
                        $lines = $invoice->lines->where('client_agreement_recurring_item_id', $item->id);
                        if ($lines->count() > 1) {
                            $extraAmount = (int) $lines->slice(1)->sum('total_amount');
                            $affectedInvoices[] = [
                                'invoice_id' => $invoice->public_id,
                                'status' => $invoice->status,
                                'cycle_start' => $invoice->cycle_start?->toDateString(),
                                'cycle_end' => $invoice->cycle_end?->toDateString(),
                                'line_count' => $lines->count(),
                                'overcharge_cents' => $extraAmount,
                            ];
                            if (in_array($invoice->status, $chargedStatuses, true)) {
                                $chargedInvoicesAffected++;
                                $hasChargedDuplicate = true;
                                $totalOverchargeCents += $extraAmount;
                            } else {
                                $draftInvoicesAffected++;
                                $hasDraftDuplicate = true;
                            }
                        }
                    }
                }

                if ($invoices->isEmpty()) {
                    $disposition = 'unbilled';
                    $unbilledAgreements++;
                } elseif ($hasChargedDuplicate) {
                    $disposition = 'charged_double';
                    $agreementsAffected++;
                } elseif ($hasDraftDuplicate) {
                    $disposition = 'draft_double';
                    $agreementsAffected++;
                } else {
                    $disposition = 'invoiced_once';
                }

                if ($disposition !== 'invoiced_once') {
                    $records[] = [
                        'agreement_id' => $agreement->public_id,
                        'workspace_id' => $agreement->workspace?->public_id ?? (string) $agreement->workspace_id,
                        'company_name' => $agreement->clientCompany?->name ?? 'Unknown',
                        'cadence' => (string) $agreement->billing_cadence,
                        'starts_on' => $agreement->starts_on->toDateString(),
                        'status' => (string) $agreement->status,
                        'disposition' => $disposition,
                        'affected_items' => $unanchoredItems->map(fn ($item): array => [
                            'item_id' => $item->public_id,
                            'description' => (string) $item->description,
                            'cadence' => (string) $item->cadence,
                            'amount_cents' => (int) $item->amount,
                        ])->values()->all(),
                        'affected_invoices' => $affectedInvoices,
                    ];
                }
            }
        });

        return [
            'summary' => [
                'agreements_checked' => $agreementsChecked,
                'agreements_affected' => $agreementsAffected,
                'charged_invoices_affected' => $chargedInvoicesAffected,
                'draft_invoices_affected' => $draftInvoicesAffected,
                'unbilled_agreements' => $unbilledAgreements,
                'total_overcharge_cents' => $totalOverchargeCents,
            ],
            'agreements' => $records,
        ];
    }
}
