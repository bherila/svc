<?php

namespace App\Services\Square\Audit;

use App\Services\Square\Mirror\SquareSeriesSummary;
use Carbon\CarbonImmutable;

/**
 * Classifies the phase-two Square billing-audit flags (#438) over facts
 * loaded once. Database-free; nothing here reaches Square or writes.
 *
 * - **Unmirrored series period:** a billed member of a series an agreement is
 *   billed in, falling on or after the agreement's start, that no svc invoice
 *   is linked to. A SCHEDULED member is not billed yet, and a canceled, draft
 *   or failed one is no bill, so neither is flagged.
 * - **Unmatched payment:** a Square payment on an invoice in svc's reach - an
 *   actively linked invoice, a member of a billed-in-Square series, or an
 *   invoice of a confirmed customer - that svc has not recorded under
 *   provider `square`. A payment svc recorded against another invoice still
 *   matches: a received payment stays with its original invoice. While the
 *   mirror has yet to read an invoice's payment ids, the audit is incomplete.
 * - **Unlinked customer balance:** a Square customer no client company is
 *   mapped to, still listed by Square, with an open invoice - the same
 *   customers `square.customers.unlinked` lists with an open balance. None
 *   is stated when the reach was bounded, since a balance could be short.
 * - **Series drift:** a billed-in-Square series whose billed members differ
 *   from its agreement in cadence, currency (another one, or more than one)
 *   or latest amount, or which the mirror does not hold although the last
 *   sync listed every Square invoice - none of it
 *   when the member load was bounded, since any series may then be partial,
 *   and the audit then says it is incomplete. SCHEDULED members are not
 *   billed yet and are left out. An agreement that sells no retainer fee is
 *   not compared on amount, and a series with fewer than two dated billed
 *   members has no cadence to compare.
 */
final class SquareBillingAuditPolicy
{
    /** Statuses that are not, or are not yet, a bill. */
    public const array UNBILLED_STATUSES = ['SCHEDULED', 'DRAFT', 'CANCELED', 'FAILED'];

    public const string PAYMENT_NOT_RECORDED = 'not_recorded';

    public const string PAYMENT_INVOICE_NOT_LINKED = 'invoice_not_linked';

    public const string DRIFT_CADENCE = 'cadence';

    public const string DRIFT_CURRENCY = 'currency';

    public const string DRIFT_AMOUNT = 'amount';

    public const string DRIFT_SERIES_NOT_MIRRORED = 'series_not_mirrored';

    public static function classify(SquareBillingAuditFacts $facts): SquareBillingAuditFindings
    {
        $agreements = [];
        foreach ($facts->agreements as $agreement) {
            $agreements[$agreement->seriesKey] = $agreement;
        }

        [$payments, $paymentsResolved] = self::unmatchedPayments($facts, $agreements);

        return new SquareBillingAuditFindings(
            self::unmirroredPeriods($facts, $agreements),
            $payments,
            self::unlinkedCustomerBalances($facts),
            self::drift($facts),
            $facts->complete && $facts->mirrorComplete && $paymentsResolved,
        );
    }

    /**
     * @param  array<string, SquareAuditAgreement>  $agreements
     * @return list<array{agreement: SquareAuditAgreement, invoice: SquareAuditInvoice}>
     */
    private static function unmirroredPeriods(SquareBillingAuditFacts $facts, array $agreements): array
    {
        $periods = [];
        foreach ($facts->invoices as $invoice) {
            $agreement = $invoice->seriesKey === null ? null : ($agreements[$invoice->seriesKey] ?? null);
            if ($agreement === null || $invoice->linked !== null || in_array($invoice->status, self::UNBILLED_STATUSES, true)) {
                continue;
            }
            $date = $invoice->date();
            // History before svc's agreement began is not svc's to mirror.
            if ($date !== null && $date < $agreement->startsOn) {
                continue;
            }
            $periods[] = ['agreement' => $agreement, 'invoice' => $invoice];
        }
        usort($periods, static fn (array $a, array $b): int => [$a['invoice']->date() ?? '9999-12-31', $a['invoice']->squareInvoiceId]
            <=> [$b['invoice']->date() ?? '9999-12-31', $b['invoice']->squareInvoiceId]);

        return $periods;
    }

    /**
     * @param  array<string, SquareAuditAgreement>  $agreements
     * @return array{list<array{invoice: SquareAuditInvoice, payment_id: string, reason: string}>, bool} the unmatched payments, and whether every invoice in reach had its payment ids resolved
     */
    private static function unmatchedPayments(SquareBillingAuditFacts $facts, array $agreements): array
    {
        $unmatched = [];
        $resolved = true;
        foreach ($facts->invoices as $invoice) {
            $inReach = $invoice->linked !== null
                || ($invoice->seriesKey !== null && isset($agreements[$invoice->seriesKey]))
                || ($invoice->customerId !== null && isset($facts->mappedCustomerIds[$invoice->customerId]));
            if (! $inReach) {
                continue;
            }
            if (! $invoice->paymentIdsResolved) {
                $resolved = false;
            }
            foreach ($invoice->paymentIds as $paymentId) {
                if (! isset($facts->recordedPaymentIds[$paymentId])) {
                    $unmatched[] = [
                        'invoice' => $invoice,
                        'payment_id' => $paymentId,
                        'reason' => $invoice->linked === null ? self::PAYMENT_INVOICE_NOT_LINKED : self::PAYMENT_NOT_RECORDED,
                    ];
                }
            }
        }
        usort($unmatched, static fn (array $a, array $b): int => [$a['invoice']->date() ?? '9999-12-31', $a['invoice']->squareInvoiceId, $a['payment_id']]
            <=> [$b['invoice']->date() ?? '9999-12-31', $b['invoice']->squareInvoiceId, $b['payment_id']]);

        return [$unmatched, $resolved];
    }

    /** @return list<array{customer_id: string, recipient_name: string|null, open_invoice_count: int, currency: string|null, open_amount: int|null}> */
    private static function unlinkedCustomerBalances(SquareBillingAuditFacts $facts): array
    {
        // A customer's balance is only stated over all of its open invoices.
        if (! $facts->reachComplete) {
            return [];
        }
        $customers = [];
        foreach ($facts->invoices as $invoice) {
            $id = $invoice->customerId;
            if ($id === null || isset($facts->mappedCustomerIds[$id]) || isset($facts->retiredCustomerIds[$id])
                || ! in_array($invoice->status, SquareSeriesSummary::OPEN_STATUSES, true)) {
                continue;
            }
            $customer = $customers[$id] ?? ['customer_id' => $id, 'currencies' => [], 'count' => 0, 'amount' => 0, 'recipient_name' => null];
            $customer['count']++;
            if ($invoice->currency !== null && ! in_array($invoice->currency, $customer['currencies'], true)) {
                $customer['currencies'][] = $invoice->currency;
            }
            // An invoice with no amount owes nothing known; an overpaid one owes nothing.
            if ($invoice->totalAmount !== null) {
                $customer['amount'] += max(0, $invoice->totalAmount - ($invoice->paidAmount ?? 0));
            }
            $customer['recipient_name'] ??= $invoice->recipientName;
            $customers[$id] = $customer;
        }
        $balances = [];
        foreach ($customers as $customer) {
            $single = count($customer['currencies']) <= 1;
            $balances[] = [
                'customer_id' => $customer['customer_id'],
                'recipient_name' => $customer['recipient_name'],
                'open_invoice_count' => $customer['count'],
                'currency' => $single ? ($customer['currencies'][0] ?? null) : null,
                'open_amount' => $single ? $customer['amount'] : null,
            ];
        }
        // Most owed first; a balance in several currencies cannot be ranked, so it comes last.
        usort($balances, static fn (array $a, array $b): int => [$a['open_amount'] === null, $b['open_amount'], $a['customer_id']]
            <=> [$b['open_amount'] === null, $a['open_amount'], $b['customer_id']]);

        return $balances;
    }

    /** @return list<array{agreement: SquareAuditAgreement, codes: non-empty-list<string>, series_cadence: string|null, series_currency: string|null, series_amount: int|null}> */
    private static function drift(SquareBillingAuditFacts $facts): array
    {
        $members = [];
        foreach ($facts->invoices as $invoice) {
            if ($invoice->seriesKey !== null) {
                $members[$invoice->seriesKey][] = $invoice;
            }
        }
        // A bounded member load may have cut any series short, and a partial
        // series would drift on whichever members happened to load.
        if (! $facts->seriesComplete) {
            return [];
        }
        $today = CarbonImmutable::parse($facts->today);
        $drift = [];
        foreach ($facts->agreements as $agreement) {
            $series = $members[$agreement->seriesKey] ?? [];
            if ($series === []) {
                // Absent from the mirror is absent from Square only after a
                // sync that listed every Square invoice.
                if ($facts->mirrorComplete) {
                    $drift[] = ['agreement' => $agreement, 'codes' => [self::DRIFT_SERIES_NOT_MIRRORED], 'series_cadence' => null, 'series_currency' => null, 'series_amount' => null];
                }

                continue;
            }
            // Only what Square has billed: a SCHEDULED member is not a bill yet.
            $billed = array_filter($series, static fn (SquareAuditInvoice $invoice): bool => ! in_array($invoice->status, self::UNBILLED_STATUSES, true));
            if ($billed === []) {
                continue;
            }
            usort($billed, static fn (SquareAuditInvoice $a, SquareAuditInvoice $b): int => [$a->seriesOccurrence ?? PHP_INT_MAX, $a->squareInvoiceId] <=> [$b->seriesOccurrence ?? PHP_INT_MAX, $b->squareInvoiceId]);
            $summary = SquareSeriesSummary::of($agreement->seriesKey, array_map(static fn (SquareAuditInvoice $invoice): array => $invoice->seriesMember(), $billed), $today);
            $cadence = is_string($summary['cadence']) ? $summary['cadence'] : null;
            $currencies = array_values(array_unique(array_filter(array_map(static fn (SquareAuditInvoice $invoice): ?string => $invoice->currency, $billed), 'is_string')));
            $currency = count($currencies) === 1 ? $currencies[0] : null;
            $amount = is_int($summary['amount']) ? $summary['amount'] : null;
            $codes = [];
            if ($agreement->cadence === null || ($cadence !== null && $cadence !== 'unknown' && $cadence !== $agreement->cadence->value)) {
                $codes[] = self::DRIFT_CADENCE;
            }
            // Billed in several currencies, or in another one: an amount in
            // another currency is not comparable, so it is not compared.
            if (count($currencies) > 1 || ($currency !== null && $currency !== $agreement->currency)) {
                $codes[] = self::DRIFT_CURRENCY;
            } elseif ($agreement->periodAmount !== null && $amount !== null && $amount !== $agreement->periodAmount) {
                $codes[] = self::DRIFT_AMOUNT;
            }
            if ($codes !== []) {
                $drift[] = ['agreement' => $agreement, 'codes' => $codes, 'series_cadence' => $cadence, 'series_currency' => $currency, 'series_amount' => $amount];
            }
        }
        usort($drift, static fn (array $a, array $b): int => $a['agreement']->seriesKey <=> $b['agreement']->seriesKey);

        return $drift;
    }
}
