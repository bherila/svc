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

    public function test_payment_reach_is_a_link_a_billed_series_or_a_confirmed_customer(): void
    {
        $series = ['000123' => self::agreement()];
        $mapped = ['cust_mapped' => true];

        $this->assertTrue(SquareBillingAuditPolicy::inPaymentReach(true, null, null, null, $series, $mapped));
        $this->assertTrue(SquareBillingAuditPolicy::inPaymentReach(false, '000123', null, '2030-02-01', $series, $mapped));
        $this->assertTrue(SquareBillingAuditPolicy::inPaymentReach(false, null, 'cust_mapped', '2020-01-01', $series, $mapped));
        $this->assertFalse(SquareBillingAuditPolicy::inPaymentReach(false, '000999', 'cust_other', null, $series, $mapped));
        $this->assertFalse(SquareBillingAuditPolicy::inPaymentReach(false, null, null, null, $series, $mapped));
        $this->assertFalse(SquareBillingAuditPolicy::inPaymentReach(false, '000123', null, '2029-12-31', $series, $mapped), 'History before the agreement is not in reach by its series.');
        $this->assertTrue(SquareBillingAuditPolicy::inPaymentReach(false, '000123', 'cust_mapped', '2029-12-31', $series, $mapped), 'A confirmed customer reaches it all the same.');
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

    public function test_periods_and_payments_are_ordered_by_date_then_id_with_undated_last(): void
    {
        $invoices = [
            self::invoice('inv_c', 3, '2030-03-15', 'UNPAID', payments: ['pay_c']),
            self::invoice('inv_n', 4, null, 'UNPAID', payments: ['pay_n']),
            self::invoice('inv_b', 2, '2030-01-15', 'PAID', payments: ['pay_b2', 'pay_b1']),
            self::invoice('inv_a', 1, '2030-01-15', 'PAID', payments: ['pay_a']),
            self::invoice('inv_d', 5, '2030-02-15', 'PAID', payments: ['pay_d']),
        ];

        $findings = self::classify($invoices);

        $this->assertSame(['inv_a', 'inv_b', 'inv_d', 'inv_c', 'inv_n'], array_map(static fn (array $period): string => $period['invoice']->squareInvoiceId, $findings->unmirroredPeriods));
        $this->assertSame(['pay_a', 'pay_b1', 'pay_b2', 'pay_d', 'pay_c', 'pay_n'], array_column($findings->unmatchedPayments, 'payment_id'));
    }

    public function test_a_payment_on_a_series_no_agreement_is_billed_in_is_out_of_reach(): void
    {
        $findings = self::classify([self::invoice('inv_other', 1, '2030-01-15', 'PAID', series: '000999', payments: ['pay_other'])]);

        $this->assertSame([], $findings->unmatchedPayments);
    }

    public function test_an_unknown_amount_leaves_the_balance_unknown_and_an_unknown_payment_is_none(): void
    {
        $findings = self::classify([
            self::invoice('inv_a1', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_a', total: null),
            self::invoice('inv_a2', null, '2030-01-11', 'UNPAID', series: null, customer: 'cust_a', total: 3_000, paid: null),
            self::invoice('inv_z1', null, '2030-01-11', 'UNPAID', series: null, customer: 'cust_z', total: 0),
            self::invoice('inv_p1', null, '2030-01-11', 'UNPAID', series: null, customer: 'cust_p', total: 3_000, paid: null),
            self::invoice('inv_y1', null, '2030-01-11', 'UNPAID', series: null, customer: 'cust_y', total: 1, currency: 'EUR'),
            self::invoice('inv_y2', null, '2030-01-11', 'UNPAID', series: null, customer: 'cust_y', total: 1),
        ]);

        $this->assertSame([['cust_p', 3_000], ['cust_z', 0], ['cust_a', null], ['cust_y', null]], array_map(static fn (array $customer): array => [$customer['customer_id'], $customer['open_amount']], $findings->unlinkedCustomerBalances));
    }

    public function test_drift_reads_members_in_occurrence_order_whatever_order_they_were_loaded_in(): void
    {
        $members = [
            self::invoice('inv_late', 3, '2030-03-15', 'PAID', linked: true, total: 12_345),
            self::invoice('inv_early', 1, '2030-01-15', 'PAID', linked: true, total: 9_000),
            self::invoice('inv_mid', 2, '2030-02-15', 'PAID', linked: true, total: 9_000),
        ];
        $agreement = self::agreement();

        $this->assertSame([], self::classify($members, agreement: $agreement)->seriesDrift);
        $drift = self::classify(array_reverse($members), agreement: self::agreement(BillingCadence::Monthly, 9_000))->seriesDrift;
        $this->assertSame([SquareBillingAuditPolicy::DRIFT_AMOUNT], $drift[0]['codes']);
        $this->assertSame(12_345, $drift[0]['series_amount']);
    }

    public function test_a_drift_entry_names_its_agreement(): void
    {
        $agreement = self::agreement(BillingCadence::Monthly, 1);

        $this->assertSame($agreement, self::classify(self::monthly(12_345), agreement: $agreement)->seriesDrift[0]['agreement']);
    }

    public function test_an_invoice_is_dated_by_its_due_date_before_its_schedule_and_is_a_series_member(): void
    {
        $invoice = new SquareAuditInvoice('inv_fixture', '000123-R-0002', '000123', 2, 'cust_fixture', 'Synthetic Recipient', 'LOCFIXTURE01', 'UNPAID', 'USD', 12_345, 45, '2030-02-15', '2030-02-01', true, [], null, true);

        $this->assertSame('2030-02-15', $invoice->date());
        $this->assertSame('2030-02-01', (new SquareAuditInvoice('inv_fixture', null, null, null, null, null, null, 'SCHEDULED', null, null, null, null, '2030-02-01', false, [], null, true))->date());
        $this->assertSame([
            'series_occurrence' => 2, 'due_on' => '2030-02-15', 'scheduled_on' => '2030-02-01', 'status' => 'UNPAID', 'currency' => 'USD', 'total_amount' => 12_345,
            'paid_amount' => 45, 'autopay' => true, 'square_customer_id' => 'cust_fixture', 'recipient_name' => 'Synthetic Recipient', 'square_location_id' => 'LOCFIXTURE01',
        ], $invoice->seriesMember());
    }

    public function test_a_scheduled_member_does_not_drift_a_series_before_it_is_billed(): void
    {
        $members = self::monthly(12_345);
        $members[] = self::invoice('inv_next', 9, '2030-12-15', 'SCHEDULED', total: 20_000, currency: 'EUR');

        $this->assertSame([], self::classify($members)->seriesDrift);
    }

    public function test_a_series_with_only_unbilled_members_is_neither_drift_nor_absent(): void
    {
        $this->assertSame([], self::classify([self::invoice('inv_next', 1, '2030-12-15', 'SCHEDULED', total: 1)])->seriesDrift);
    }

    public function test_a_series_billed_in_two_currencies_drifts_on_currency_and_not_amount(): void
    {
        $members = self::monthly(12_345);
        $members[] = self::invoice('inv_eur', 9, '2030-09-15', 'PAID', total: 99_000, currency: 'EUR');

        $drift = self::classify($members)->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_CURRENCY], $drift[0]['codes']);
        $this->assertNull($drift[0]['series_currency']);
    }

    public function test_a_member_with_no_currency_does_not_make_a_series_multi_currency(): void
    {
        $members = self::monthly(12_345);
        $members[0] = self::invoice('inv_fixture_1', 1, '2030-01-15', 'PAID', linked: true, total: 12_345, currency: null);
        $members[] = self::invoice('inv_fixture_9', 9, '2030-09-15', 'PAID', linked: true, total: 13_000);

        $drift = self::classify($members)->seriesDrift;

        $this->assertSame([SquareBillingAuditPolicy::DRIFT_AMOUNT], $drift[0]['codes']);
        $this->assertSame('USD', $drift[0]['series_currency']);
    }

    public function test_an_unresolved_invoice_in_reach_makes_the_audit_incomplete(): void
    {
        $unresolved = new SquareAuditInvoice('inv_pending', '000123-R-0001', '000123', 1, 'cust_series', null, 'LOCFIXTURE01', 'PAID', 'USD', 12_345, 12_345, '2030-01-15', null, false, ['pay_stale_rules'], null, false);
        $stranger = new SquareAuditInvoice('inv_stranger', '000500', null, null, 'cust_stranger', null, null, 'PAID', 'USD', 100, 100, '2030-01-15', null, false, [], null, false);

        $this->assertFalse(self::classify([$unresolved])->complete);
        $this->assertSame([], self::classify([$unresolved])->unmatchedPayments, 'Ids awaiting a re-read are not reported.');
        $this->assertSame(['pay_current'], array_column(self::classify([$unresolved, self::invoice('inv_fixture_2', 2, '2030-02-15', 'PAID', payments: ['pay_current'])])->unmatchedPayments, 'payment_id'), 'Later invoices are still checked.');
        $this->assertFalse(self::classify([$unresolved, self::invoice('inv_fixture_2', 2, '2030-02-15', 'PAID')])->complete);
        $this->assertFalse(self::classify([self::invoice('inv_fixture_2', 2, '2030-02-15', 'PAID'), $unresolved])->complete);
        $this->assertTrue(self::classify([self::invoice('inv_fixture_2', 2, '2030-02-15', 'PAID'), self::invoice('inv_fixture_3', 3, '2030-03-15', 'PAID')])->complete);
        $this->assertTrue(self::classify([$stranger])->complete, 'An invoice outside the payment check cannot leave it incomplete.');
    }

    public function test_drift_is_withheld_when_the_series_load_was_bounded(): void
    {
        $facts = new SquareBillingAuditFacts('2030-10-01', [self::agreement(BillingCadence::Annual, 1)], self::monthly(12_345), [], [], [], false, false);

        $findings = SquareBillingAuditPolicy::classify($facts);

        $this->assertSame([], $findings->seriesDrift);
        $this->assertFalse($findings->complete);
    }

    public function test_a_truncated_mirror_claims_no_absence_and_a_bounded_reach_states_no_balance(): void
    {
        $open = self::invoice('inv_a1', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_a', total: 5_000);
        $truncated = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [self::agreement()], [$open], [], [], [], true, true, true, false));
        $bounded = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [self::agreement()], [$open], [], [], [], false, true, false, true));

        $this->assertSame([[], false, 0], [$truncated->seriesDrift, $truncated->complete, count($truncated->unlinkedCustomerBalances)]);
        $membersBounded = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [self::agreement()], [$open], [], [], [], false, false, true, true));
        $this->assertSame([], $membersBounded->unlinkedCustomerBalances, 'A series member could hold part of a balance.');
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_SERIES_NOT_MIRRORED], [], false], [$bounded->seriesDrift[0]['codes'], $bounded->unlinkedCustomerBalances, $bounded->complete]);
    }

    public function test_members_before_the_agreement_began_do_not_drift_it(): void
    {
        $members = self::monthly(12_345);
        array_unshift($members, self::invoice('inv_old_1', -1, '2029-11-15', 'PAID', total: 99, currency: 'EUR'), self::invoice('inv_old_2', 0, '2029-12-15', 'PAID', total: 99, currency: 'EUR'));

        $this->assertSame([], self::classify($members)->seriesDrift);
        $this->assertSame([], self::classify([self::invoice('inv_old_1', 1, '2029-11-15', 'PAID', total: 99)])->seriesDrift, 'Only history: nothing to compare.');
        $this->assertSame([SquareBillingAuditPolicy::DRIFT_CURRENCY], self::classify([self::invoice('inv_start', 1, '2030-01-01', 'PAID', currency: 'EUR')])->seriesDrift[0]['codes'], 'A member on the start day is the agreement\'s.');
        $this->assertSame([SquareBillingAuditPolicy::DRIFT_AMOUNT], self::classify([...self::monthly(12_345), self::invoice('inv_undated', 9, null, 'PAID', total: 1)])->seriesDrift[0]['codes'], 'An undated member is compared.');
    }

    public function test_a_prefix_in_two_locations_is_reported_and_not_compared(): void
    {
        $members = self::monthly(12_345);
        $members[] = new SquareAuditInvoice('inv_elsewhere', '000123-R-0001', '000123', 1, 'cust_other', null, 'LOCFIXTURE02', 'PAID', 'EUR', 1, 1, '2030-01-20', null, false, [], null, true);

        $drift = self::classify($members)->seriesDrift;

        $this->assertCount(1, $drift);
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_MULTIPLE_LOCATIONS], null, null, null], [$drift[0]['codes'], $drift[0]['series_cadence'], $drift[0]['series_currency'], $drift[0]['series_amount']]);
        $this->assertSame(self::AGREEMENT, $drift[0]['agreement']->agreementId);
        $later = new SquareAuditAgreement('00000000-0000-4000-8000-00000000a009', self::COMPANY, '000999', '2030-01-01', BillingCadence::Monthly, 1, 'USD');
        $both = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [self::agreement(), $later], [...$members, self::invoice('inv_later', 1, '2030-01-15', 'PAID', series: '000999')], [], [], [], true));
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_MULTIPLE_LOCATIONS], [SquareBillingAuditPolicy::DRIFT_AMOUNT]], array_column($both->seriesDrift, 'codes'), 'The next agreement is still compared.');
        $members[count($members) - 1] = new SquareAuditInvoice('inv_elsewhere', '000123-R-0001', '000123', 1, 'cust_other', null, null, 'PAID', 'USD', 12_345, 1, '2030-01-20', null, false, [], null, true);
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_MULTIPLE_LOCATIONS]], array_column(self::classify($members)->seriesDrift, 'codes'), 'An unknown location cannot be tied to the others.');
    }

    public function test_an_ended_agreement_governs_nothing_after_its_end_and_later_billing_is_reported(): void
    {
        $ended = new SquareAuditAgreement(self::AGREEMENT, self::COMPANY, '000123', '2030-01-01', BillingCadence::Monthly, 12_345, 'USD', '2030-06-30');
        $members = self::monthly(12_345);
        $members[] = self::invoice('inv_after', 9, '2030-07-15', 'UNPAID', total: 99, currency: 'EUR');

        $findings = self::classify($members, agreement: $ended);

        $this->assertSame([], $findings->unmirroredPeriods, 'The later member is not for svc to mirror.');
        $this->assertSame([SquareBillingAuditPolicy::DRIFT_BILLED_AFTER_END], $findings->seriesDrift[0]['codes'], 'The later member is not compared.');
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_BILLED_AFTER_END]], array_column(self::classify([self::invoice('inv_after', 9, '2030-07-15', 'PAID')], agreement: $ended)->seriesDrift, 'codes'));
        $this->assertSame(['inv_end_day'], array_map(static fn (array $period): string => $period['invoice']->squareInvoiceId, self::classify([self::invoice('inv_end_day', 6, '2030-06-30', 'UNPAID')], agreement: $ended)->unmirroredPeriods), 'The end day is within it.');
        $this->assertSame([], self::classify([self::invoice('inv_scheduled_after', 9, '2030-07-15', 'SCHEDULED')], agreement: $ended)->seriesDrift, 'Nothing billed after it yet.');
        $this->assertTrue($ended->governs(null));
        $this->assertFalse($ended->endedBefore(null));
        $this->assertFalse(self::agreement()->endedBefore('2099-01-01'));
        $this->assertFalse($ended->endedBefore('2030-06-30'));
        $this->assertTrue($ended->endedBefore('2030-07-01'));
        $later = new SquareAuditAgreement('00000000-0000-4000-8000-00000000a009', self::COMPANY, '000999', '2030-01-01', BillingCadence::Monthly, 1, 'USD');
        $both = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [$ended, $later], [self::invoice('inv_after', 9, '2030-07-15', 'PAID'), self::invoice('inv_later', 1, '2030-01-15', 'PAID', series: '000999')], [], [], [], true));
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_BILLED_AFTER_END], [SquareBillingAuditPolicy::DRIFT_AMOUNT]], array_column($both->seriesDrift, 'codes'), 'The next agreement is still compared.');
        $this->assertSame($ended, $both->seriesDrift[0]['agreement']);
        $elsewhere = new SquareAuditInvoice('inv_after_elsewhere', '000123-R-0009', '000123', 9, 'cust_other', null, 'LOCFIXTURE02', 'PAID', 'USD', 12_345, 0, '2030-07-15', null, false, [], null, true);
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_MULTIPLE_LOCATIONS]], array_column(self::classify([self::invoice('inv_inside', 1, '2030-01-15', 'PAID', linked: true), $elsewhere], agreement: $ended)->seriesDrift, 'codes'), 'A later bill in another location is ambiguity, not billing after the end.');
    }

    public function test_only_billed_members_in_the_agreement_count_towards_locations(): void
    {
        $members = self::monthly(12_345);
        $members[] = new SquareAuditInvoice('inv_scheduled_elsewhere', '000123-R-0009', '000123', 9, 'cust_other', null, 'LOCFIXTURE02', 'SCHEDULED', 'USD', 12_345, 0, '2030-09-15', null, false, [], null, true);
        $members[] = new SquareAuditInvoice('inv_history_elsewhere', '000123-R-0000', '000123', 0, 'cust_other', null, 'LOCFIXTURE02', 'PAID', 'USD', 12_345, 0, '2029-09-15', null, false, [], null, true);

        $this->assertSame([], self::classify($members)->seriesDrift);
    }

    public function test_a_prefix_in_two_locations_derives_no_periods_or_payments(): void
    {
        $members = [
            self::invoice('inv_here', 1, '2030-01-15', 'UNPAID', payments: ['pay_here']),
            new SquareAuditInvoice('inv_there', '000123-R-0001', '000123', 1, 'cust_other', null, 'LOCFIXTURE02', 'PAID', 'USD', 12_345, 12_345, '2030-01-20', null, false, ['pay_there'], null, true),
        ];

        $findings = self::classify($members);

        $this->assertSame([], $findings->unmirroredPeriods);
        $this->assertSame([], $findings->unmatchedPayments);
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_MULTIPLE_LOCATIONS]], array_column($findings->seriesDrift, 'codes'));
        $this->assertSame([], SquareBillingAuditPolicy::ambiguousSeries([self::agreement()], [['000123', 'PAID', '2029-01-01', 'LOCFIXTURE02'], ['000123', 'SCHEDULED', '2030-05-01', 'LOCFIXTURE03'], ['000123', 'PAID', null, 'LOCFIXTURE01'], ['000999', 'PAID', null, 'LOCFIXTURE09'], [null, 'PAID', null, 'LOCFIXTURE08'], ['000123', 'CANCELED', '2030-02-01', null]]));
        $this->assertSame(['000123' => true], SquareBillingAuditPolicy::ambiguousSeries([self::agreement()], [['000123', 'PAID', null, 'LOCFIXTURE01'], ['000123', 'PAID', '2030-02-01', null]]), 'A bill with no location cannot be tied to the others.');
        $this->assertSame(['000123' => true], SquareBillingAuditPolicy::ambiguousSeries([self::agreement()], [['000123', 'PAID', '2030-02-01', null]]), 'Nor, alone, to any series.');
        $this->assertSame(['000123' => true], SquareBillingAuditPolicy::ambiguousSeries([self::agreement()], [['000123', 'PAID', '2030-01-01', 'LOCFIXTURE02'], ['000123', 'PAID', null, 'LOCFIXTURE01']]));
        $other = new SquareAuditAgreement('00000000-0000-4000-8000-00000000a009', self::COMPANY, '000999', '2030-01-01', BillingCadence::Monthly, 1, 'USD');
        $this->assertSame(['000123' => true, '000999' => true], SquareBillingAuditPolicy::ambiguousSeries([self::agreement(), $other], [
            ['000123', 'PAID', null, 'LOCFIXTURE01'], ['000123', 'PAID', null, 'LOCFIXTURE01'], ['000123', 'PAID', null, 'LOCFIXTURE02'],
            ['000999', 'PAID', null, 'LOCFIXTURE01'], ['000999', 'PAID', null, 'LOCFIXTURE02'],
        ]));
        $this->assertSame([], SquareBillingAuditPolicy::ambiguousSeries([self::agreement()], [['000123', 'PAID', null, 'LOCFIXTURE01'], ['000123', 'PAID', null, 'LOCFIXTURE01']]), 'One location twice is one location.');
    }

    public function test_a_bounded_member_load_derives_no_series_periods_or_payments(): void
    {
        $members = [self::invoice('inv_here', 1, '2030-01-15', 'UNPAID', payments: ['pay_here'])];
        $findings = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [self::agreement()], $members, [], [], [], false, false));

        $this->assertSame([[], []], [$findings->unmirroredPeriods, $findings->unmatchedPayments]);
    }

    public function test_an_unknown_paid_amount_leaves_the_balance_unknown_unless_nothing_was_paid(): void
    {
        $findings = self::classify([
            self::invoice('inv_u', null, '2030-01-10', 'UNPAID', series: null, customer: 'cust_unpaid', total: 4_000, paid: null),
            self::invoice('inv_p', null, '2030-01-10', 'PARTIALLY_PAID', series: null, customer: 'cust_partial', total: 4_000, paid: null),
        ]);

        $this->assertSame([['cust_unpaid', 4_000], ['cust_partial', null]], array_map(static fn (array $customer): array => [$customer['customer_id'], $customer['open_amount']], $findings->unlinkedCustomerBalances));
    }

    public function test_drift_compares_the_latest_bill_itself_not_an_older_amount(): void
    {
        $members = self::monthly(9_000);
        $members[] = self::invoice('inv_fixture_9', 9, '2030-09-15', 'PAID', linked: true, total: null);

        $this->assertSame([], self::classify($members)->seriesDrift, 'The latest amount is unknown, so it is not compared.');
    }

    public function test_an_incomplete_mirror_derives_no_series_findings_or_balances(): void
    {
        $members = [...self::monthly(9_000), self::invoice('inv_open', 9, '2030-09-15', 'UNPAID', payments: ['pay_here'])];
        $members[] = self::invoice('inv_stranger', null, '2030-02-01', 'UNPAID', series: null, customer: 'cust_stranger', total: 100);
        $findings = SquareBillingAuditPolicy::classify(new SquareBillingAuditFacts('2030-10-01', [self::agreement()], $members, [], [], [], true, true, true, false));

        $this->assertSame([[], [], [], [], false], [$findings->unmirroredPeriods, $findings->unmatchedPayments, $findings->seriesDrift, $findings->unlinkedCustomerBalances, $findings->complete]);
    }

    public function test_a_series_is_not_missing_before_its_agreement_starts(): void
    {
        $future = new SquareAuditAgreement(self::AGREEMENT, self::COMPANY, '000123', '2030-10-02', BillingCadence::Monthly, 1, 'USD');
        $today = new SquareAuditAgreement(self::AGREEMENT, self::COMPANY, '000123', '2030-10-01', BillingCadence::Monthly, 1, 'USD');

        $this->assertSame([], self::classify([], agreement: $future)->seriesDrift);
        $this->assertSame([[SquareBillingAuditPolicy::DRIFT_SERIES_NOT_MIRRORED]], array_column(self::classify([], agreement: $today)->seriesDrift, 'codes'));
    }

    public function test_absence_is_not_claimed_when_the_series_load_was_bounded(): void
    {
        $facts = new SquareBillingAuditFacts('2030-10-01', [self::agreement()], [], [], [], [], false, false);

        $this->assertSame([], SquareBillingAuditPolicy::classify($facts)->seriesDrift);
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
        ?int $total = 12_345,
        ?int $paid = 0,
        ?string $currency = 'USD',
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
            true,
        );
    }
}
