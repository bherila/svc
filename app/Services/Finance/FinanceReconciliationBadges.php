<?php

namespace App\Services\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\Workspace;
use App\Support\Finance\FinanceTransactionLink;

/**
 * Which of a screen's payments the external finance system has reconciled.
 *
 * A payment is "reconciled in finance" while it has at least one active
 * allocation from the configured finance system (`svc.finance.external_system`),
 * whatever its review flag says. Each such payment gets the links to its
 * finance transactions, one per distinct transaction; with no link template
 * configured the list is empty and the badge stands alone.
 *
 * One query for the whole screen, bounded by the workspace and the payment ids.
 */
final readonly class FinanceReconciliationBadges
{
    /**
     * @param  iterable<ClientInvoicePayment>  $payments  payments already resolved inside the workspace
     * @return array<int, array{hrefs: list<string>}> keyed by payment id; a payment with no active allocation is absent
     */
    public function forPayments(Workspace $workspace, iterable $payments): array
    {
        $ids = [];
        foreach ($payments as $payment) {
            $ids[] = (int) $payment->id;
        }
        if ($ids === []) {
            return [];
        }

        $rows = PaymentReconciliation::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_invoice_payment_id', $ids)
            ->where('external_system_slug', $this->externalSystem())
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['client_invoice_payment_id', 'external_transaction_uuid']);

        $links = FinanceTransactionLink::configured();
        $badges = [];
        foreach ($rows as $row) {
            $paymentId = (int) $row->client_invoice_payment_id;
            $badges[$paymentId] ??= ['hrefs' => []];
            $href = $links->href((string) $row->external_transaction_uuid);
            if ($href !== null && ! in_array($href, $badges[$paymentId]['hrefs'], true)) {
                $badges[$paymentId]['hrefs'][] = $href;
            }
        }

        return $badges;
    }

    private function externalSystem(): string
    {
        $system = config('svc.finance.external_system');

        return strtolower(trim(is_string($system) ? $system : ''));
    }
}
