<?php

namespace App\Services\Square\Audit;

use App\Services\Square\Mirror\SquareSeriesSummary;
use Carbon\CarbonImmutable;

/**
 * Classifies the phase-two Square billing-audit flags (#438) over facts
 * loaded once. Database-free; nothing here reaches Square or writes.
 *
 * - **Unmirrored series period:** a billed member of a series an agreement is
 *   billed in, falling within the agreement (from its start to its end, once
 *   it has one), that no svc invoice
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
 *   is stated when the reach or the series members were bounded, since a
 *   balance could be short.
 * - **Series drift:** a billed-in-Square series whose billed members differ
 *   from its agreement in cadence, currency (another one, or more than one)
 *   or latest amount, or which the mirror does not hold although the last
 *   sync listed every Square invoice. Members dated before the agreement's
 *   start are history and left out, and Square still billing after it ended
 *   is reported as `billed_after_end` rather than compared. Square numbers
 *   invoices uniquely per location only, so a series is its prefix in the
 *   agreement's Square location (#488): the same prefix elsewhere is another
 *   series and none of this agreement's. A prefix that cannot be placed - a
 *   located series with a bill naming no location, or a marker from before
 *   markers named a location whose prefix is billed from more than one - is
 *   reported as `multiple_locations` and not compared - none of it
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

    public const string DRIFT_MULTIPLE_LOCATIONS = 'multiple_locations';

    public const string DRIFT_BILLED_AFTER_END = 'billed_after_end';

    /**
     * Whether an invoice's payments are svc's to account for: it is actively
     * linked, a member of a series an agreement is billed in dated within
     * that agreement, or a confirmed customer's.
     *
     * @param  array<string, SquareAuditAgreement>  $billedSeries  keyed by {@see SquareAuditAgreement::key()}
     * @param  array<string, true>  $mappedCustomers
     */
    public static function inPaymentReach(bool $linked, ?string $seriesKey, ?string $locationId, ?string $customerId, ?string $date, array $billedSeries, array $mappedCustomers): bool
    {
        return $linked
            || (self::agreementOf($billedSeries, $seriesKey, $locationId)?->governs($date) ?? false)
            || ($customerId !== null && isset($mappedCustomers[$customerId]));
    }

    /**
     * The agreement whose series a Square invoice with this prefix and
     * location belongs to: the one marking the prefix in that location, else
     * one marking it in no location (from before markers named one).
     *
     * @param  array<string, SquareAuditAgreement>  $agreements  keyed by {@see SquareAuditAgreement::key()}
     */
    public static function agreementOf(array $agreements, ?string $seriesKey, ?string $locationId): ?SquareAuditAgreement
    {
        // @infection-ignore-all Equivalent: without it a null prefix looks up the keys "|" and "{location}|", which no agreement has, since every marker names a six-digit prefix.
        if ($seriesKey === null) {
            return null;
        }

        return $agreements[($locationId ?? '').'|'.$seriesKey] ?? $agreements['|'.$seriesKey] ?? null;
    }

    /**
     * The agreements, by {@see SquareAuditAgreement::key()}, whose series
     * cannot be told apart from another location's, from each agreement's
     * start on. Square numbers invoices uniquely per location only. A located
     * series is ambiguous once a bill with its prefix names no location; a
     * marker that names none, once its prefix is billed from more than one
     * location or by a bill that names none.
     *
     * @param  list<SquareAuditAgreement>  $agreements
     * @param  iterable<array{string|null, string, string|null, string|null}>  $members  [series key, status, date, location]
     * @return array<string, true>
     */
    public static function ambiguousSeries(array $agreements, iterable $members): array
    {
        $bySeries = [];
        foreach ($agreements as $agreement) {
            $bySeries[$agreement->seriesKey][] = $agreement;
        }
        $locations = [];
        $ambiguous = [];
        foreach ($members as [$seriesKey, $status, $date, $location]) {
            if ($seriesKey === null || in_array($status, self::UNBILLED_STATUSES, true)) {
                continue;
            }
            foreach ($bySeries[$seriesKey] ?? [] as $agreement) {
                if ($date !== null && $date < $agreement->startsOn) {
                    continue;
                }
                if ($agreement->locationId === null) {
                    $locations[$agreement->key()][] = $location;
                } elseif ($location === null) {
                    // A bill naming no location may be this series' or another location's.
                    $ambiguous[$agreement->key()] = true;
                }
            }
        }
        foreach ($locations as $key => $seen) {
            // A bill with no location cannot be tied to any series, so it
            // makes the prefix as unclassifiable as a second location.
            if (in_array(null, $seen, true) || count(array_unique($seen)) > 1) {
                // @infection-ignore-all PHP normalises array keys either way; the cast only states the string key static analysis requires.
                $ambiguous[(string) $key] = true;
            }
        }

        return $ambiguous;
    }

    public static function classify(SquareBillingAuditFacts $facts): SquareBillingAuditFindings
    {
        $ambiguous = self::ambiguousSeries($facts->agreements, array_map(static fn (SquareAuditInvoice $invoice): array => [$invoice->seriesKey, $invoice->status, $invoice->date(), $invoice->locationId], $facts->invoices));
        // A prefix shared by two locations is two series svc cannot tell
        // apart, so nothing is derived from it but that finding - and when
        // the members were bounded, or the last sync did not list all of
        // Square, an omitted one could be in another location, so nothing is
        // derived from any series.
        $agreements = [];
        foreach ($facts->agreements as $agreement) {
            if ($facts->seriesComplete && $facts->mirrorComplete && ! isset($ambiguous[$agreement->key()])) {
                $agreements[$agreement->key()] = $agreement;
            }
        }

        [$payments, $paymentsResolved] = self::unmatchedPayments($facts, $agreements);

        return new SquareBillingAuditFindings(
            self::unmirroredPeriods($facts, $agreements),
            $payments,
            self::unlinkedCustomerBalances($facts),
            self::drift($facts, $ambiguous),
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
            $agreement = self::agreementOf($agreements, $invoice->seriesKey, $invoice->locationId);
            if ($agreement === null || $invoice->linked !== null || in_array($invoice->status, self::UNBILLED_STATUSES, true)) {
                continue;
            }
            // History before svc's agreement began, or after it ended, is not
            // svc's to mirror; billing after the end is drift instead.
            if (! $agreement->governs($invoice->date())) {
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
            if (! self::inPaymentReach($invoice->linked !== null, $invoice->seriesKey, $invoice->locationId, $invoice->customerId, $invoice->date(), $agreements, $facts->mappedCustomerIds)) {
                continue;
            }
            // Ids read for an earlier state, or by earlier rules, may name
            // tenders that took no money: they wait for the re-read.
            if (! $invoice->paymentIdsResolved) {
                $resolved = false;

                continue;
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
        if (! $facts->reachComplete || ! $facts->seriesComplete || ! $facts->mirrorComplete) {
            return [];
        }
        $customers = [];
        foreach ($facts->invoices as $invoice) {
            $id = $invoice->customerId;
            if ($id === null || isset($facts->mappedCustomerIds[$id]) || isset($facts->retiredCustomerIds[$id])
                || ! in_array($invoice->status, SquareSeriesSummary::OPEN_STATUSES, true)) {
                continue;
            }
            $customer = $customers[$id] ?? ['customer_id' => $id, 'currencies' => [], 'count' => 0, 'amount' => 0, 'unknown' => false, 'recipient_name' => null];
            $customer['count']++;
            if ($invoice->currency !== null && ! in_array($invoice->currency, $customer['currencies'], true)) {
                $customer['currencies'][] = $invoice->currency;
            }
            // An invoice with no known amount, or an unknown paid amount where
            // something may have been paid, leaves the balance unknown; an
            // overpaid one owes nothing.
            if ($invoice->totalAmount === null || ($invoice->paidAmount === null && $invoice->status !== 'UNPAID')) {
                $customer['unknown'] = true;
            } else {
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
                'open_amount' => $single && ! $customer['unknown'] ? $customer['amount'] : null,
            ];
        }
        // Most owed first; a balance in several currencies cannot be ranked, so it comes last.
        usort($balances, static fn (array $a, array $b): int => [$a['open_amount'] === null, $b['open_amount'], $a['customer_id']]
            <=> [$b['open_amount'] === null, $a['open_amount'], $b['customer_id']]);

        return $balances;
    }

    /**
     * @param  array<string, true>  $ambiguous  agreement keys whose series cannot be placed in one location
     * @return list<array{agreement: SquareAuditAgreement, codes: non-empty-list<string>, series_cadence: string|null, series_currency: string|null, series_amount: int|null}>
     */
    private static function drift(SquareBillingAuditFacts $facts, array $ambiguous): array
    {
        $members = [];
        foreach ($facts->invoices as $invoice) {
            if ($invoice->seriesKey !== null) {
                $members[$invoice->seriesKey][] = $invoice;
            }
        }
        // A bounded member load may have cut any series short, and a partial
        // series would drift on whichever members happened to load.
        if (! $facts->seriesComplete || ! $facts->mirrorComplete) {
            return [];
        }
        $today = CarbonImmutable::parse($facts->today);
        $drift = [];
        foreach ($facts->agreements as $agreement) {
            if (isset($ambiguous[$agreement->key()])) {
                $drift[] = ['agreement' => $agreement, 'codes' => [self::DRIFT_MULTIPLE_LOCATIONS], 'series_cadence' => null, 'series_currency' => null, 'series_amount' => null];

                continue;
            }
            // The prefix in another location is another series (#488).
            $series = array_filter($members[$agreement->seriesKey] ?? [], static fn (SquareAuditInvoice $invoice): bool => $agreement->bills($invoice->seriesKey, $invoice->locationId));
            if ($series === []) {
                // Absent from a complete mirror is absent from Square - once
                // the agreement has started, when a first bill could exist.
                if ($agreement->startsOn <= $facts->today) {
                    $drift[] = ['agreement' => $agreement, 'codes' => [self::DRIFT_SERIES_NOT_MIRRORED], 'series_cadence' => null, 'series_currency' => null, 'series_amount' => null];
                }

                continue;
            }
            // Only what Square has billed (a SCHEDULED member is not a bill
            // yet) while the agreement governed it - as for unmirrored
            // periods. Square still billing after the agreement ended is its
            // own finding.
            $allBilled = array_filter($series, static fn (SquareAuditInvoice $invoice): bool => ! in_array($invoice->status, self::UNBILLED_STATUSES, true));
            $after = array_filter($allBilled, static fn (SquareAuditInvoice $invoice): bool => $agreement->endedBefore($invoice->date()));
            $afterEnd = $after !== [];
            $billed = array_filter($allBilled, static fn (SquareAuditInvoice $invoice): bool => $agreement->governs($invoice->date()));
            if ($billed === []) {
                if ($afterEnd) {
                    $drift[] = ['agreement' => $agreement, 'codes' => [self::DRIFT_BILLED_AFTER_END], 'series_cadence' => null, 'series_currency' => null, 'series_amount' => null];
                }

                continue;
            }
            usort($billed, static fn (SquareAuditInvoice $a, SquareAuditInvoice $b): int => [$a->seriesOccurrence ?? PHP_INT_MAX, $a->squareInvoiceId] <=> [$b->seriesOccurrence ?? PHP_INT_MAX, $b->squareInvoiceId]);
            $summary = SquareSeriesSummary::of($agreement->seriesKey, array_map(static fn (SquareAuditInvoice $invoice): array => $invoice->seriesMember(), $billed), $today);
            $cadence = is_string($summary['cadence']) ? $summary['cadence'] : null;
            $currencies = array_values(array_unique(array_filter(array_map(static fn (SquareAuditInvoice $invoice): ?string => $invoice->currency, $billed), 'is_string')));
            $currency = count($currencies) === 1 ? $currencies[0] : null;
            // The latest bill's own amount: an unknown one is not compared,
            // rather than an older one standing in for it.
            $amount = $billed[array_key_last($billed)]->totalAmount;
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
            if ($afterEnd) {
                $codes[] = self::DRIFT_BILLED_AFTER_END;
            }
            if ($codes !== []) {
                $drift[] = ['agreement' => $agreement, 'codes' => $codes, 'series_cadence' => $cadence, 'series_currency' => $currency, 'series_amount' => $amount];
            }
        }
        $order = static fn (SquareAuditAgreement $agreement): array => [$agreement->seriesKey, $agreement->locationId ?? ''];
        usort($drift, static fn (array $a, array $b): int => $order($a['agreement']) <=> $order($b['agreement']));

        return $drift;
    }
}
