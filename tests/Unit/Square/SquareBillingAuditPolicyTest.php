<?php

namespace Tests\Unit\Square;

use App\Services\Square\Audit\SquareAuditAgreement;
use App\Services\Square\Audit\SquareAuditInvoice;
use App\Services\Square\Audit\SquareAuditLinkedInvoice;
use App\Services\Square\Audit\SquareBillingAuditFacts;
use App\Services\Square\Audit\SquareBillingAuditFindings;
use App\Services\Square\Audit\SquareBillingAuditPolicy;
use App\Support\Billing\BillingCadence;
use PHPUnit\Framework\TestCase;

/**
 * The phase-two Square billing-audit classification (#438), over synthetic
 * facts and without a database.
 */
final class SquareBillingAuditPolicyTest extends TestCase
{
    private const string AGREEMENT = '00000000-0000-4000-8000-00000000a001';

    private const string COMPANY = '00000000-0000-4000-8000-00000000c001';

    public function test_a_billed_series_member_with_no_linked_invoice_is_an_unmirrored_period(): void
    {
        $findings = self::classify([
            self::invoice('inv_fixture_1', 1, '2030-01-15', 'PAID', linked: true),
            self::invoice('inv_fixture_3', 3, '2030-03-15', 'UNPAID'),
            self::invoice('inv_fixture_2', 2, '2030-02-15', 'PAID'),
            self::invoice('inv_fixture_4', 4, '2030-04-15', 'SCHEDULED'),
            self::invoice('inv_fixture_5', 5, '2030-05-15', 'CANCELED'),
            self::invoice('inv_fixture_6', 6, '2030-06-15', 'DRAFT'),
            self::invoice('inv_fixture_7', 7, '2030-07-15', 'FAILED'),
            // Before svc's agreement began: history, not a missing mirror.
            self::invoice('inv_fixture_0', 0, '2029-12-15', 'PAID'),
            // In another series, which no agreement is billed in.
            self::invoice('inv_fixture_x', 1, '2030-02-01', 'UNPAID', series: '000999'),
        ]);

        $this->assertSame(['inv_fixture_2', 'inv_fixture_3'], array_map(static fn (array $period): string => $period['invoice']->squareInvoiceId, $findings->unmirroredPeriods));
        $this->assertSame(self::AGREEMENT, $findings->unmirroredPeriods[0]['agreement']->agreementId);
    }

    public function test_a_member_on_the_agreement_start_or_with_no_date_is_still_flagged(): void
    {
        $findings = self::classify([
            self::invoice('inv_fixture_b', 2, null, 'UNPAID'),
            self::invoice('inv_fixture_a', 1, '2030-01-01', 'PAID'),
        ]);

        $this->assertSame(['inv_fixture_a', 'inv_fixture_b'], array_map(static fn (array $period): string => $period['invoice']->squareInvoiceId, $findings->unmirroredPeriods));
    }

    public function test_a_square_payment_svc_has_not_recorded_is_unmatched_with_a_reason(): void
    {
        $findings = self::classify([
            self::invoice('inv_fixture_1', 1, '2030-01-15', 'PAID', linked: true, payments: ['pay_recorded', 'pay_missing']),
            self::invoice('inv_fixture_2', 2, '2030-02-15', 'PAID', payments: ['pay_unlinked']),
            // A confirmed customer's one-off invoice is in reach too.
            self::invoice('inv_fixture_o', null, '2030-02-20', 'PAID', series: null, payments: ['pay_one_off'], customer: 'cust_mapped'),
            // Neither linked, in a billed series, nor a confirmed customer's.
            self::invoice('inv_fixture_s', null, '2030-02-21', 'PAID', series: null, payments: ['pay_stranger'], customer: 'cust_stranger'),
        ], recorded: ['pay_recorded']);

        $this->assertSame([
            ['inv_fixture_1', 'pay_missing', SquareBillingAuditPolicy::PAYMENT_NOT_RECORDED],
            ['inv_fixture_2', 'pay_unlinked', SquareBillingAuditPolicy::PAYMENT_INVOICE_NOT_LINKED],
            ['inv_fixture_o', 'pay_one_off', SquareBillingAuditPolicy::PAYMENT_INVOICE_NOT_LINKED],
        ], array_map(static fn (array $payment): array => [$payment['invoice']->squareInvoiceId, $payment['payment_id'], $payment['reason']], $findings->unmatchedPayments));
    }

    public function test_unmapped_customers_with_open_invoices_are_ranked_by_what_they_owe(): void
    {
        $findings = self::classify([
            self::invoice('inv_a1', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_a', total: 5_000, paid: 0, name: 'Synthetic A'),
            self::invoice('inv_a2', null, '2030-01-11', 'PARTIALLY_PAID', series: null, customer: 'cust_a', total: 9_000, paid: 4_000),
            self::invoice('inv_a3', null, '2030-01-12', 'PAID', series: null, customer: 'cust_a', total: 1_000, paid: 1_000),
            self::invoice('inv_b1', null, '2030-01-10', 'PAYMENT_PENDING', series: null, customer: 'cust_b', total: 20_000, paid: 0),
            self::invoice('inv_c1', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_c', total: 1_000, paid: 0),
            self::invoice('inv_c2', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_c', total: 1_000, paid: 0, currency: 'EUR'),
            // A confirmed customer, a customer Square no longer lists, a
            // scheduled invoice, and an invoice with no customer.
            self::invoice('inv_m1', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_mapped', total: 99_000),
            self::invoice('inv_r1', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_retired', total: 99_000),
            self::invoice('inv_d1', null, '2030-01-10', 'SCHEDULED', series: null, customer: 'cust_d', total: 99_000),
            self::invoice('inv_n1', null, '2030-01-10', 'UNPAID', series: null, customer: null, total: 99_000),
        ], retired: ['cust_retired']);

        $this->assertSame([
            ['customer_id' => 'cust_b', 'recipient_name' => null, 'open_invoice_count' => 1, 'currency' => 'USD', 'open_amount' => 20_000],
            ['customer_id' => 'cust_a', 'recipient_name' => 'Synthetic A', 'open_invoice_count' => 2, 'currency' => 'USD', 'open_amount' => 10_000],
            ['customer_id' => 'cust_c', 'recipient_name' => null, 'open_invoice_count' => 2, 'currency' => null, 'open_amount' => null],
        ], $findings->unlinkedCustomerBalances);
    }

    public function test_an_overpaid_open_invoice_owes_nothing_rather_than_a_credit(): void
    {
        $findings = self::classify([
            self::invoice('inv_a1', null, '2030-01-10', 'PARTIALLY_PAID', series: null, customer: 'cust_a', total: 1_000, paid: 1_500),
        ]);

        $this->assertSame(0, $findings->unlinkedCustomerBalances[0]['open_amount']);
    }

    public function test_a_series_matching_its_agreement_has_no_drift(): void
    {
        $this->assertSame([], self::classify(self::monthly(12_345))->seriesDrift);
    }

    public function test_amount_drift_reads_the_latest_billed_member(): void
    {
        $members = self::monthly(12_345);
        $members[] = self::invoice('inv_fixture_9', 9, '2030-09-15', 'UNPAID', total: 13_000);
        $members[] = self::invoice('inv_fixture_10', 10, '2030-10-15', 'CANCELED', total: 12_345);

        $drift = self::classify($members)->seriesDrift;

        $this->assertCount(1, $drift);
        $this->assertSame([SquareBillingAuditPolicy::DRIFT_AMOUNT], $drift[0]['codes']);
        $this->assertSame([13_000, 'monthly', 'USD'], [$drift[0]['series_amount'], $drift[0]['series_cadence'], $drift[0]['series_currency']]);
    }

    public function test_cadence_drift_against_a_quarterly_agreement(): void
    {
        $drift = self::classify(self::monthly(37_035), agreement: self::agreement(BillingCadence::Quarterly, 37_035))->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_CADENCE], $drift[0]['codes']);
        $this->assertSame('monthly', $drift[0]['series_cadence']);
    }

    public function test_an_agreement_that_bills_no_cycle_drifts_from_any_series(): void
    {
        $drift = self::classify(self::monthly(12_345), agreement: self::agreement(null, 12_345))->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_CADENCE], $drift[0]['codes']);
    }

    public function test_a_currency_difference_is_reported_instead_of_an_amount_difference(): void
    {
        $drift = self::classify(self::monthly(10_000, currency: 'EUR'))->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_CURRENCY], $drift[0]['codes']);
    }

    public function test_cadence_and_amount_drift_are_reported_together(): void
    {
        $drift = self::classify(self::monthly(500), agreement: self::agreement(BillingCadence::Annual, 120_000))->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_CADENCE, SquareBillingAuditPolicy::DRIFT_AMOUNT], $drift[0]['codes']);
    }

    public function test_an_agreement_with_no_retainer_fee_is_not_compared_on_amount(): void
    {
        $this->assertSame([], self::classify(self::monthly(9_999), agreement: self::agreement(BillingCadence::Monthly, null))->seriesDrift);
    }

    public function test_a_single_member_has_no_cadence_to_compare(): void
    {
        $this->assertSame([], self::classify([self::invoice('inv_fixture_1', 1, '2030-01-15', 'PAID')], agreement: self::agreement(BillingCadence::Annual, 12_345))->seriesDrift);
    }

    public function test_a_series_the_mirror_does_not_hold_is_reported(): void
    {
        $drift = self::classify([])->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_SERIES_NOT_MIRRORED], $drift[0]['codes']);
        $this->assertSame([null, null, null], [$drift[0]['series_cadence'], $drift[0]['series_currency'], $drift[0]['series_amount']]);
    }

    public function test_drift_is_ordered_by_series_and_completeness_is_carried(): void
    {
        $facts = new SquareBillingAuditFacts('2030-10-01', [
            new SquareAuditAgreement('00000000-0000-4000-8000-00000000a002', self::COMPANY, '000200', '2030-01-01', BillingCadence::Monthly, 1, 'USD'),
            new SquareAuditAgreement(self::AGREEMENT, self::COMPANY, '000100', '2030-01-01', BillingCadence::Monthly, 1, 'USD'),
        ], [], [], [], [], false);

        $findings = SquareBillingAuditPolicy::classify($facts);

        $this->assertSame(['000100', '000200'], array_map(static fn (array $drift): string => $drift['agreement']->seriesKey, $findings->seriesDrift));
        $this->assertFalse($findings->complete);
        $this->assertTrue(self::classify([])->complete);
    }

    /** @return list<SquareAuditInvoice> */
    private static function monthly(int $amount, string $currency = 'USD'): array
    {
        $members = [];
        for ($occurrence = 1; $occurrence <= 8; $occurrence++) {
            $members[] = self::invoice('inv_fixture_'.$occurrence, $occurrence, sprintf('2030-%02d-15', $occurrence), 'PAID', linked: true, total: $amount, currency: $currency);
        }

        return $members;
    }

    private static function agreement(?BillingCadence $cadence = BillingCadence::Monthly, ?int $amount = 12_345): SquareAuditAgreement
    {
        return new SquareAuditAgreement(self::AGREEMENT, self::COMPANY, '000123', '2030-01-01', $cadence, $amount, 'USD');
    }

    /**
     * @param  list<SquareAuditInvoice>  $invoices
     * @param  list<string>  $recorded
     * @param  list<string>  $retired
     */
    private static function classify(array $invoices, array $recorded = [], array $retired = [], ?SquareAuditAgreement $agreement = null): SquareBillingAuditFindings
    {
        return SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts(
            '2030-10-01',
            [$agreement ?? self::agreement()],
            $invoices,
            ['cust_mapped' => true],
            array_fill_keys($retired, true),
            array_fill_keys($recorded, true),
            true,
        ));
    }

    /** @param  list<string>  $payments */
    private static function invoice(
        string $id,
        ?int $occurrence,
        ?string $due,
        string $status,
        ?string $series = '000123',
        bool $linked = false,
        array $payments = [],
        ?string $customer = 'cust_series',
        int $total = 12_345,
        int $paid = 0,
        string $currency = 'USD',
        ?string $name = null,
    ): SquareAuditInvoice {
        return new SquareAuditInvoice(
            $id,
            $series === null ? null : sprintf('%s-R-%04d', $series, $occurrence ?? 0),
            $series,
            $occurrence,
            $customer,
            $name,
            'LOCFIXTURE01',
            $status,
            $currency,
            $total,
            $paid,
            $due,
            null,
            false,
            $payments,
            $linked ? new SquareAuditLinkedInvoice('00000000-0000-4000-8000-0000000b'.substr(md5($id), 0, 4), self::COMPANY) : null,
        );
    }
}
