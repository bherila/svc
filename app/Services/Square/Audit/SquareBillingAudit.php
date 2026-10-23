<?php

namespace App\Services\Square\Audit;

use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Support\Square\SquareDashboardPage;
use App\Support\WorkspaceClock;

/**
 * The phase-two Square billing-audit flags (#438), as
 * `billing_audit.stale_and_missing` presents them: one bounded load, a
 * database-free classification, and at most 100 entries per flag, each with
 * finished svc and Square dashboard URLs. Reads svc's mirror only - nothing
 * here reaches or writes to Square - and reports nothing where Square does not
 * serve the workspace.
 */
final class SquareBillingAudit
{
    public const int MAX_ENTRIES = 100;

    public function __construct(
        private readonly SquareBillingAuditRepository $repository,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * The flags, in the shape of the SquareBillingAudit schema.
     *
     * @return array<string, mixed>
     */
    public function report(Workspace $workspace): array
    {
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->servesWorkspace($workspace)) {
            return self::empty();
        }
        $findings = SquareBillingAuditPolicy::classify($this->repository->load($workspace, $this->clock->today($workspace)->toDateString()));
        $dashboard = static fn (string $squareInvoiceId): ?string => SquareDashboardPage::invoice($configuration->environment, $squareInvoiceId);
        $explore = static fn (array $filter): string => route('square.explore', [$workspace, ...$filter]);
        $agreementUrl = static fn (SquareAuditAgreement $agreement): string => route('clients.agreement', [$workspace, $agreement->companyId, $agreement->agreementId]);

        return [
            'complete' => $findings->complete,
            'unmirrored_series_periods' => self::section('periods', $findings->unmirroredPeriods, static fn (array $period): array => [
                'agreement_id' => $period['agreement']->agreementId,
                'agreement_url' => $agreementUrl($period['agreement']),
                'series_key' => $period['agreement']->seriesKey,
                'square_location_id' => $period['agreement']->locationId,
                'square_invoice_id' => $period['invoice']->squareInvoiceId,
                'invoice_number' => $period['invoice']->invoiceNumber,
                'status' => $period['invoice']->status,
                'due_on' => $period['invoice']->date(),
                'currency' => $period['invoice']->currency,
                'total_amount' => $period['invoice']->totalAmount,
                'dashboard_url' => $dashboard($period['invoice']->squareInvoiceId),
            ]),
            'unmatched_payments' => self::section('payments', $findings->unmatchedPayments, static fn (array $payment): array => [
                'payment_id' => $payment['payment_id'],
                'reason' => $payment['reason'],
                'square_invoice_id' => $payment['invoice']->squareInvoiceId,
                'invoice_number' => $payment['invoice']->invoiceNumber,
                'invoice_id' => $payment['invoice']->linked?->invoiceId,
                'invoice_url' => $payment['invoice']->linked === null ? null
                    : route('clients.invoice', [$workspace, $payment['invoice']->linked->companyId, $payment['invoice']->linked->invoiceId]),
                'dashboard_url' => $dashboard($payment['invoice']->squareInvoiceId),
            ]),
            'unlinked_customer_balances' => self::section('customers', $findings->unlinkedCustomerBalances, static fn (array $customer): array => [
                ...$customer,
                'explore_url' => $explore(['customer_id' => $customer['customer_id']]),
            ]),
            'series_drift' => self::section('agreements', $findings->seriesDrift, static fn (array $drift): array => [
                'agreement_id' => $drift['agreement']->agreementId,
                'agreement_url' => $agreementUrl($drift['agreement']),
                'series_key' => $drift['agreement']->seriesKey,
                'square_location_id' => $drift['agreement']->locationId,
                'codes' => $drift['codes'],
                'agreement_cadence' => $drift['agreement']->cadence?->value,
                'series_cadence' => $drift['series_cadence'],
                'agreement_currency' => $drift['agreement']->currency,
                'series_currency' => $drift['series_currency'],
                'agreement_amount' => $drift['agreement']->periodAmount,
                'series_amount' => $drift['series_amount'],
                // The series in its own location (#488); the prefix everywhere for a marker naming none.
                'explore_url' => $explore(array_filter(['series_key' => $drift['agreement']->seriesKey, 'location_id' => $drift['agreement']->locationId], is_string(...))),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return [
            'complete' => true,
            'unmirrored_series_periods' => ['count' => 0, 'periods' => [], 'truncated' => false],
            'unmatched_payments' => ['count' => 0, 'payments' => [], 'truncated' => false],
            'unlinked_customer_balances' => ['count' => 0, 'customers' => [], 'truncated' => false],
            'series_drift' => ['count' => 0, 'agreements' => [], 'truncated' => false],
        ];
    }

    /**
     * The first entries of one flag, presented, with the full count.
     *
     * @template T
     *
     * @param  list<T>  $findings
     * @param  callable(T): array<string, mixed>  $present
     * @return array<string, int|bool|list<array<string, mixed>>>
     */
    private static function section(string $key, array $findings, callable $present): array
    {
        $shown = array_map($present, array_slice($findings, 0, self::MAX_ENTRIES));

        return ['count' => count($findings), $key => $shown, 'truncated' => count($findings) > count($shown)];
    }
}
