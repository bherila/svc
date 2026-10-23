<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\StripeProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementKind;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeProcessorMovementFactsTest extends TestCase
{
    public function test_actual_refund_entries_keep_their_own_signed_money_and_dates(): void
    {
        $refund = $this->refund();
        $refund['status'] = 'failed';
        $refund['amount'] = 9999;
        $facts = StripeProcessorMovementFacts::refund($refund, $this->entry(), payoutIdentifier: 'po_synthetic');
        $this->assertNotNull($facts);
        $this->assertSame('stripe', $facts->provider);
        $this->assertSame('txn_synthetic_debit', $facts->identifier);
        $this->assertSame('re_synthetic', $facts->sourceIdentifier);
        $this->assertSame(['pi_synthetic', 'ch_synthetic'], $facts->paymentIdentifiers);
        $this->assertSame([-100, -3, -97], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor]);
        $this->assertSame(ProcessorMovementKind::Refund, $facts->kind);
        $this->assertSame('USD', $facts->currency);
        $this->assertSame(1893457800, $facts->occurredAt->getTimestamp());
        $this->assertSame('UTC', $facts->occurredAt->getTimezone()->getName());
        $this->assertSame('stripe_balance_created', $facts->dateBasis);
        $this->assertSame(1893542400, $facts->availableOn?->getTimestamp());
        $this->assertSame('po_synthetic', $facts->payoutIdentifier);
    }

    public function test_failed_refund_credit_is_a_separate_actual_balance_transaction(): void
    {
        $refund = $this->refund();
        $refund['failure_balance_transaction'] = 'txn_synthetic_credit';
        $credit = array_replace($this->entry(), ['id' => 'txn_synthetic_credit', 'amount' => 100, 'fee' => 0, 'net' => 100, 'created' => 1893542400, 'type' => 'refund_failure']);
        $facts = StripeProcessorMovementFacts::refund($refund, $credit, failure: true);
        $this->assertNotNull($facts);
        $this->assertSame(ProcessorMovementKind::RefundReversal, $facts->kind);
        $this->assertSame('txn_synthetic_credit', $facts->identifier);
        $this->assertSame(100, $facts->principalMinor);
    }

    public function test_null_pointer_never_invents_a_movement_from_refund_status(): void
    {
        $refund = $this->refund();
        $refund['balance_transaction'] = null;
        $refund['status'] = 'succeeded';
        $this->assertNull(StripeProcessorMovementFacts::refund($refund, null));
        $this->assertNull(StripeProcessorMovementFacts::refund($refund, null, failure: true));
    }

    public function test_dispute_debit_and_credit_remain_two_entries_regardless_of_status(): void
    {
        $debit = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'adjustment', 'fee' => 5, 'net' => -105]);
        $credit = array_replace($debit, ['id' => 'txn_synthetic_credit', 'amount' => 100, 'fee' => -5, 'net' => 105, 'created' => 1893542400]);
        $dispute = ['id' => 'dp_synthetic', 'object' => 'dispute', 'charge' => 'ch_synthetic', 'payment_intent' => 'pi_synthetic', 'status' => 'won', 'amount' => 9999, 'balance_transactions' => [$debit, $credit]];
        $withdrawal = StripeProcessorMovementFacts::dispute($dispute, $debit);
        $reinstatement = StripeProcessorMovementFacts::dispute($dispute, $credit);
        $this->assertNotNull($withdrawal);
        $this->assertNotNull($reinstatement);
        $this->assertSame([ProcessorMovementKind::DisputeDebit, ProcessorMovementKind::DisputeCredit], [$withdrawal->kind, $reinstatement->kind]);
        $this->assertSame([-100, 100], [$withdrawal->principalMinor, $reinstatement->principalMinor]);
        $this->assertNotSame($withdrawal->identifier, $reinstatement->identifier);
        $dispute['balance_transactions'] = [];
        $this->assertNull(StripeProcessorMovementFacts::dispute($dispute));
    }

    public function test_expanded_parent_and_source_identities_are_checked_and_used(): void
    {
        $refund = $this->refund();
        $refund['charge'] = ['id' => 'ch_synthetic', 'payment_intent' => ['id' => 'pi_synthetic']];
        unset($refund['payment_intent']);
        $entry = $this->entry();
        $entry['source'] = $refund;
        $refund['balance_transaction'] = $entry;
        unset($entry['available_on']);
        unset($refund['balance_transaction']['available_on']);
        $facts = StripeProcessorMovementFacts::refund($refund, $entry);
        $this->assertNotNull($facts);
        $this->assertSame(['pi_synthetic', 'ch_synthetic'], $facts->paymentIdentifiers);
        $this->assertNull($facts->availableOn);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEntries(): iterable
    {
        yield 'wrong pointer' => [['id' => 'txn_foreign']];
        yield 'empty entry id' => [['id' => '']];
        yield 'spaced entry id' => [['id' => 'txn_synthetic_debit ']];
        yield 'wrong source' => [['source' => 're_foreign']];
        yield 'wrong expanded source payment' => [['source' => ['id' => 're_synthetic', 'charge' => 'ch_foreign']]];
        yield 'wrong object' => [['object' => 'charge']];
        yield 'wrong transaction type' => [['type' => 'charge']];
        yield 'wrong net' => [['net' => -96]];
        yield 'float principal' => [['amount' => -100.0]];
        yield 'unsafe principal' => [['amount' => -9007199254740992]];
        yield 'missing fee' => [['fee' => null]];
        yield 'numeric fee string' => [['fee' => '3']];
        yield 'currency' => [['currency' => 'US1']];
        yield 'missing actual created date' => [['created' => null]];
        yield 'numeric created string' => [['created' => '1893457800']];
        yield 'negative created date' => [['created' => -1]];
        yield 'unrepresentable created date' => [['created' => PHP_INT_MAX]];
        yield 'unsafe available date' => [['available_on' => 'tomorrow']];
        yield 'opposite refund sign' => [['amount' => 100, 'fee' => 0, 'net' => 100]];
        yield 'zero principal' => [['amount' => 0, 'fee' => 0, 'net' => 0]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidEntries')]
    public function test_unreadable_or_unbound_ledger_entries_are_refused(array $changes): void
    {
        try {
            StripeProcessorMovementFacts::refund($this->refund(), array_replace($this->entry(), $changes));
            $this->fail('An invalid or unbound Stripe entry was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('The Stripe processor movement is unreadable.', $exception->getMessage());
        }
    }

    public function test_missing_parent_payment_identity_is_not_filled_by_the_caller(): void
    {
        $refund = $this->refund();
        unset($refund['charge'], $refund['payment_intent']);
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::refund($refund, $this->entry());
    }

    public function test_pointer_presence_requires_the_transaction_to_be_read(): void
    {
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::refund($this->refund(), null);
    }

    public function test_expanded_pointer_cannot_disagree_with_the_retrieved_transaction(): void
    {
        $refund = $this->refund();
        $refund['balance_transaction'] = array_replace($this->entry(), ['amount' => -101, 'net' => -98]);
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::refund($refund, $this->entry());
    }

    public function test_a_transaction_not_in_the_dispute_array_cannot_be_assigned_to_it(): void
    {
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => []], $this->entry());
    }

    public function test_a_duplicate_dispute_member_does_not_create_ambiguous_history(): void
    {
        $entry = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'adjustment']);
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => [$entry, $entry]], $entry);
    }

    public function test_a_missing_dispute_balance_array_is_unknown_not_an_empty_history(): void
    {
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'status' => 'won']);
    }

    public function test_a_dispute_member_cannot_disagree_with_retrieved_immutable_money(): void
    {
        $entry = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'adjustment']);
        $other = array_replace($entry, ['amount' => -101, 'net' => -98]);
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => [$other]], $entry);
    }

    public function test_an_expanded_pointer_source_cannot_hide_a_foreign_payment_binding(): void
    {
        $refund = $this->refund();
        $refund['balance_transaction'] = array_replace($this->entry(), ['source' => ['id' => 're_synthetic', 'charge' => 'ch_foreign']]);
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::refund($refund, $this->entry());
    }

    public function test_contradictory_expanded_charge_and_top_level_intent_are_refused(): void
    {
        $refund = $this->refund();
        $refund['charge'] = ['id' => 'ch_synthetic', 'payment_intent' => 'pi_foreign'];
        $this->expectException(DomainException::class);
        StripeProcessorMovementFacts::refund($refund, $this->entry());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidParentIdentifiers(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'non-string' => [123];
        yield 'leading punctuation' => ['!re_synthetic'];
        yield 'trailing punctuation' => ['re_synthetic!'];
        yield 'terminal newline' => ["re_synthetic\n"];
        yield 'over width' => [str_repeat('r', 256)];
        yield 'leading underscore' => ['_re_synthetic'];
    }

    #[DataProvider('invalidParentIdentifiers')]
    public function test_parent_identity_is_required_even_when_no_movement_is_published(mixed $identifier): void
    {
        foreach (['refund', 'dispute'] as $object) {
            $parent = ['id' => $identifier, 'object' => $object, 'balance_transactions' => []];
            try {
                $object === 'refund'
                    ? StripeProcessorMovementFacts::refund($parent, null)
                    : StripeProcessorMovementFacts::dispute($parent);
                $this->fail('A malformed parent identity was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The Stripe processor movement is unreadable.', $exception->getMessage());
            }
        }
    }

    public function test_parent_object_is_checked_before_an_empty_history_is_accepted(): void
    {
        foreach (['refund', 'dispute'] as $object) {
            $parent = ['id' => 'parent_synthetic', 'object' => 'charge', 'balance_transactions' => []];
            try {
                $object === 'refund'
                    ? StripeProcessorMovementFacts::refund($parent, null)
                    : StripeProcessorMovementFacts::dispute($parent);
                $this->fail('A foreign parent object was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The Stripe processor movement is unreadable.', $exception->getMessage());
            }
        }
    }

    public function test_dispute_members_must_be_a_bounded_list_before_any_entry_is_accepted(): void
    {
        $entry = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'adjustment']);
        foreach ([[1 => $entry], [$entry, array_replace($entry, ['id' => 'txn_second']), array_replace($entry, ['id' => 'txn_third'])]] as $members) {
            try {
                StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => $members], $entry);
                $this->fail('An unbounded or non-list dispute history was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('The Stripe processor movement is unreadable.', $exception->getMessage());
            }
        }
    }

    public function test_a_valid_entry_still_requires_an_actual_matching_dispute_member(): void
    {
        $entry = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'adjustment']);
        $member = array_replace($entry, ['id' => 'txn_other_synthetic']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The Stripe processor movement is unreadable.');
        StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => [$member]], $entry);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidDisputeEntries(): iterable
    {
        yield 'zero principal' => [['amount' => 0, 'fee' => 0, 'net' => 0]];
        yield 'numeric string principal' => [['amount' => '-100']];
        yield 'float principal' => [['amount' => -100.0]];
        yield 'foreign ledger type' => [['type' => 'charge']];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidDisputeEntries')]
    public function test_matching_dispute_entries_still_require_signed_integer_principal_and_dispute_type(array $changes): void
    {
        $entry = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'adjustment'], $changes);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The Stripe processor movement is unreadable.');
        StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => [$entry]], $entry);
    }

    public function test_a_one_minor_unit_dispute_debit_remains_valid(): void
    {
        $entry = array_replace($this->entry(), ['source' => 'dp_synthetic', 'type' => 'payment_reversal', 'amount' => -1, 'fee' => 0, 'net' => -1]);
        $facts = StripeProcessorMovementFacts::dispute(['id' => 'dp_synthetic', 'charge' => 'ch_synthetic', 'balance_transactions' => [$entry]], $entry);
        $this->assertNotNull($facts);
        $this->assertSame(ProcessorMovementKind::DisputeDebit, $facts->kind);
        $this->assertSame([-1, 0, -1], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor]);
    }

    public function test_expanded_pointer_principal_alone_cannot_disagree_with_the_retrieved_entry(): void
    {
        $refund = $this->refund();
        $refund['balance_transaction'] = array_replace($this->entry(), ['amount' => -101]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The Stripe processor movement is unreadable.');
        StripeProcessorMovementFacts::refund($refund, $this->entry());
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedCurrencies(): iterable
    {
        yield 'leading punctuation' => ['!usd'];
        yield 'trailing punctuation' => ['usd!'];
        yield 'terminal newline' => ["usd\n"];
        yield 'array' => [['usd']];
        yield 'boolean' => [true];
    }

    #[DataProvider('malformedCurrencies')]
    public function test_malformed_currency_is_refused_with_the_safe_adapter_exception(mixed $currency): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The Stripe processor movement is unreadable.');
        StripeProcessorMovementFacts::refund($this->refund(), array_replace($this->entry(), ['currency' => $currency]));
    }

    public function test_payout_identifier_has_the_same_provider_identity_rules_as_other_references(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The Stripe processor movement is unreadable.');
        StripeProcessorMovementFacts::refund($this->refund(), $this->entry(), payoutIdentifier: 'po_synthetic!');
    }

    /** @return iterable<string, array{int, string}> */
    public static function timestampBounds(): iterable
    {
        yield 'epoch' => [0, '1970-01-01T00:00:00+00:00'];
        yield 'last supported second' => [253402300799, '9999-12-31T23:59:59+00:00'];
    }

    #[DataProvider('timestampBounds')]
    public function test_exact_timestamp_boundaries_are_preserved_in_utc(int $timestamp, string $formatted): void
    {
        $facts = StripeProcessorMovementFacts::refund($this->refund(), array_replace($this->entry(), ['created' => $timestamp, 'available_on' => $timestamp]));
        $this->assertNotNull($facts);
        $this->assertSame($timestamp, $facts->occurredAt->getTimestamp());
        $this->assertSame($timestamp, $facts->availableOn?->getTimestamp());
        $this->assertSame($formatted, $facts->occurredAt->toIso8601String());
        $this->assertSame($formatted, $facts->availableOn?->toIso8601String());
        $this->assertSame('UTC', $facts->occurredAt->getTimezone()->getName());
    }

    public function test_maximum_length_parent_identity_is_valid_without_published_money(): void
    {
        $this->assertNull(StripeProcessorMovementFacts::refund(['id' => str_repeat('r', 255)], null));
        $this->assertNull(StripeProcessorMovementFacts::dispute(['id' => str_repeat('d', 255), 'balance_transactions' => []]));
    }

    /** @return array<string, mixed> */
    private function refund(): array
    {
        return ['id' => 're_synthetic', 'object' => 'refund', 'charge' => 'ch_synthetic', 'payment_intent' => 'pi_synthetic', 'balance_transaction' => 'txn_synthetic_debit'];
    }

    /** @return array<string, mixed> */
    private function entry(): array
    {
        return ['id' => 'txn_synthetic_debit', 'object' => 'balance_transaction', 'type' => 'refund', 'source' => 're_synthetic', 'amount' => -100, 'fee' => -3, 'net' => -97, 'currency' => 'usd', 'created' => 1893457800, 'available_on' => 1893542400];
    }
}
