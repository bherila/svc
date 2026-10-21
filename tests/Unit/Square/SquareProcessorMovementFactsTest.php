<?php

namespace Tests\Unit\Square;

use App\Support\Finance\ProcessorMovementKind;
use App\Support\Square\SquareProcessorMovementFacts;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SquareProcessorMovementFactsTest extends TestCase
{
    public function test_refund_entry_preserves_signed_money_date_and_actual_binding(): void
    {
        $facts = SquareProcessorMovementFacts::from($this->entry());
        $this->assertNotNull($facts);
        $this->assertSame(['square', 'poe_synthetic', ['payment_synthetic'], 'refund_synthetic'], [$facts->provider, $facts->identifier, $facts->paymentIdentifiers, $facts->sourceIdentifier]);
        $this->assertSame([-100, -3, -97, 'USD'], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor, $facts->currency]);
        $this->assertSame('2030-01-01T07:30:00+00:00', $facts->occurredAt->toIso8601String());
        $this->assertSame('123456', $facts->occurredAt->format('u'));
        $this->assertSame('square_effective_at', $facts->dateBasis);
        $this->assertSame('payout_synthetic', $facts->payoutIdentifier);
        $this->assertNull($facts->availableOn);
        $this->assertSame(ProcessorMovementKind::Refund, $facts->kind);
    }

    public function test_refund_reversal_uses_actual_credit_not_refund_status(): void
    {
        $entry = array_replace($this->entry(), ['gross_amount_money' => $this->money(100), 'fee_amount_money' => $this->money(0), 'net_amount_money' => $this->money(100)]);
        $facts = SquareProcessorMovementFacts::from($entry, ['id' => 'refund_synthetic', 'payment_id' => 'payment_synthetic', 'status' => 'PENDING', 'amount_money' => $this->money(9000)]);
        $this->assertNotNull($facts);
        $this->assertSame(ProcessorMovementKind::RefundReversal, $facts->kind);
        $this->assertSame(100, $facts->principalMinor);
    }

    public function test_open_dispute_and_dispute_use_the_actual_entry_sign(): void
    {
        foreach (['OPEN_DISPUTE', 'DISPUTE'] as $type) {
            foreach ([-100, 100] as $principal) {
                $entry = $this->entry();
                unset($entry['type_refund_details']);
                $entry['type'] = $type;
                $entry['type_'.strtolower($type).'_details'] = ['payment_id' => 'payment_synthetic', 'dispute_id' => 'dispute_synthetic'];
                $entry['gross_amount_money'] = $this->money($principal);
                $entry['fee_amount_money'] = $this->money(0);
                $entry['net_amount_money'] = $this->money($principal);
                $facts = SquareProcessorMovementFacts::from($entry, ['id' => 'dispute_synthetic', 'disputed_payment' => ['payment_id' => 'payment_synthetic'], 'state' => 'WON']);
                $this->assertNotNull($facts);
                $this->assertSame($principal < 0 ? ProcessorMovementKind::DisputeDebit : ProcessorMovementKind::DisputeCredit, $facts->kind);
            }
        }
    }

    public function test_an_exact_refund_or_dispute_reference_can_resolve_a_missing_payment_id(): void
    {
        $entry = $this->entry();
        unset($entry['type_refund_details']['payment_id']);
        $facts = SquareProcessorMovementFacts::from($entry, ['id' => 'refund_synthetic', 'payment_id' => 'payment_synthetic']);
        $this->assertNotNull($facts);
        $this->assertSame(['payment_synthetic'], $facts->paymentIdentifiers);
    }

    public function test_absent_optional_references_stay_unknown_when_payment_is_explicit(): void
    {
        $entry = $this->entry();
        unset($entry['type_refund_details']['refund_id'], $entry['payout_id']);
        $facts = SquareProcessorMovementFacts::from($entry);
        $this->assertNotNull($facts);
        $this->assertNull($facts->sourceIdentifier);
        $this->assertNull($facts->payoutIdentifier);
    }

    /** @return iterable<string, array{string}> */
    public static function omittedComponents(): iterable
    {
        foreach (['gross_amount_money', 'fee_amount_money', 'net_amount_money'] as $component) {
            yield $component => [$component];
        }
    }

    #[DataProvider('omittedComponents')]
    public function test_exact_ledger_identity_derives_the_third_component_only_from_two_actual_values(string $component): void
    {
        $entry = $this->entry();
        unset($entry[$component]);
        $facts = SquareProcessorMovementFacts::from($entry);
        $this->assertNotNull($facts);
        $this->assertSame([-100, -3, -97], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor]);
    }

    public function test_documented_currency_code_spelling_and_nanosecond_times_are_supported(): void
    {
        $entry = $this->entry();
        foreach (['gross_amount_money', 'fee_amount_money', 'net_amount_money'] as $column) {
            $entry[$column]['currency_code'] = $entry[$column]['currency'];
            unset($entry[$column]['currency']);
        }
        $entry['effective_at'] = '2030-01-01T00:00:00.123456789Z';
        $facts = SquareProcessorMovementFacts::from($entry);
        $this->assertNotNull($facts);
        $this->assertSame('USD', $facts->currency);
        $this->assertSame('123456', $facts->occurredAt->format('u'));
    }

    public function test_an_explicitly_bound_standalone_fee_is_not_principal(): void
    {
        foreach ([-25, 25] as $net) {
            $entry = $this->entry();
            $entry['type'] = 'FEE';
            unset($entry['type_refund_details']);
            $entry['type_fee_details'] = ['payment_id' => 'payment_synthetic'];
            $entry['gross_amount_money'] = $this->money($net);
            $entry['fee_amount_money'] = $this->money(0);
            $entry['net_amount_money'] = $this->money($net);
            $facts = SquareProcessorMovementFacts::from($entry);
            $this->assertNotNull($facts);
            $this->assertSame($net < 0 ? ProcessorMovementKind::Fee : ProcessorMovementKind::FeeReversal, $facts->kind);
            $this->assertSame([0, -$net, $net], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor]);
        }
        $this->assertNull(SquareProcessorMovementFacts::from(['type' => 'CHARGE']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function standaloneFeeTypes(): iterable
    {
        yield 'fee' => ['FEE', 'type_fee_details'];
        yield 'processing' => ['PROCESSING_FEE', 'type_other_details'];
        yield 'processing refund' => ['PROCESSING_FEE_REFUND', 'type_other_details'];
        yield 'undo processing refund' => ['UNDO_PROCESSING_FEE_REFUND', 'type_other_details'];
        yield 'third party' => ['THIRD_PARTY_FEE', 'type_third_party_fee_details'];
        yield 'third party refund' => ['THIRD_PARTY_FEE_REFUND', 'type_third_party_fee_refund_details'];
        yield 'tax on fee' => ['TAX_ON_FEE', 'type_tax_on_fee_details'];
    }

    #[DataProvider('standaloneFeeTypes')]
    public function test_standalone_fees_require_the_actual_entry_payment_binding(string $type, string $details): void
    {
        $entry = $this->entry();
        unset($entry['type_refund_details']);
        $entry['type'] = $type;
        $entry[$details] = ['payment_id' => 'payment_synthetic'];
        $entry['gross_amount_money'] = $this->money(-25);
        $entry['fee_amount_money'] = $this->money(0);
        $entry['net_amount_money'] = $this->money(-25);
        $facts = SquareProcessorMovementFacts::from($entry);
        $this->assertNotNull($facts);
        $this->assertSame([0, 25, -25], [$facts->principalMinor, $facts->feeMinor, $facts->netMinor]);
        unset($entry[$details]['payment_id']);
        $this->expectException(DomainException::class);
        SquareProcessorMovementFacts::from($entry);
    }

    public function test_whole_second_timestamps_are_supported(): void
    {
        $entry = $this->entry();
        $entry['effective_at'] = '2030-01-01T12:00:00Z';
        $facts = SquareProcessorMovementFacts::from($entry);
        $this->assertNotNull($facts);
        $this->assertSame('2030-01-01T12:00:00+00:00', $facts->occurredAt->toIso8601String());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEntries(): iterable
    {
        yield 'missing type' => [['type' => null]];
        yield 'invented reversal type' => [['type' => 'DISPUTE_REVERSAL']];
        yield 'unknown type' => [['type' => 'SYNTHETIC_UNKNOWN']];
        yield 'empty id' => [['id' => '']];
        yield 'spaced id' => [['id' => 'poe_synthetic ']];
        yield 'oversized id' => [['id' => str_repeat('x', 256)]];
        yield 'invalid payout' => [['payout_id' => ['synthetic_bad']]];
        yield 'missing actual date' => [['effective_at' => null]];
        yield 'unreal date' => [['effective_at' => '2030-02-30T00:00:00Z']];
        yield 'unreal time' => [['effective_at' => '2030-01-01T25:00:00Z']];
        yield 'missing zone' => [['effective_at' => '2030-01-01T12:00:00']];
        yield 'float amount' => [['gross_amount_money' => ['amount' => -100.0, 'currency' => 'USD']]];
        yield 'unsafe amount' => [['gross_amount_money' => ['amount' => -9007199254740992, 'currency' => 'USD']]];
        yield 'string amount' => [['gross_amount_money' => ['amount' => '-100', 'currency' => 'USD']]];
        yield 'wrong currency' => [['gross_amount_money' => ['amount' => -100, 'currency' => 'EUR']]];
        yield 'contradictory currency spellings' => [['gross_amount_money' => ['amount' => -100, 'currency' => 'USD', 'currency_code' => 'EUR']]];
        yield 'bad net identity' => [['net_amount_money' => ['amount' => -98, 'currency' => 'USD']]];
        yield 'only one amount' => [['fee_amount_money' => null, 'net_amount_money' => null]];
        yield 'derived gross overflow' => [['gross_amount_money' => null, 'fee_amount_money' => ['amount' => 9007199254740991, 'currency' => 'USD'], 'net_amount_money' => ['amount' => 9007199254740991, 'currency' => 'USD']]];
        yield 'derived fee overflow' => [['fee_amount_money' => null, 'gross_amount_money' => ['amount' => -9007199254740991, 'currency' => 'USD'], 'net_amount_money' => ['amount' => 9007199254740991, 'currency' => 'USD']]];
        yield 'derived net overflow' => [['net_amount_money' => null, 'gross_amount_money' => ['amount' => -9007199254740991, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 9007199254740991, 'currency' => 'USD']]];
        yield 'zero movement' => [['gross_amount_money' => ['amount' => 0, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 0, 'currency' => 'USD'], 'net_amount_money' => ['amount' => 0, 'currency' => 'USD']]];
        yield 'database year underflow' => [['effective_at' => '0999-12-31T12:00:00Z']];
        yield 'UTC year overflow' => [['effective_at' => '9999-12-31T23:30:00-14:00']];
        yield 'no payment proof' => [['type_refund_details' => ['refund_id' => 'refund_synthetic']]];
        yield 'missing details' => [['type_refund_details' => null]];
        yield 'empty payment' => [['type_refund_details' => ['payment_id' => '']]];
        yield 'invalid source ref' => [['type_refund_details' => ['payment_id' => 'payment_synthetic', 'refund_id' => 'bad ref']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidEntries')]
    public function test_missing_or_inconsistent_authoritative_facts_are_refused(array $changes): void
    {
        try {
            SquareProcessorMovementFacts::from(array_replace($this->entry(), $changes));
            $this->fail('An invalid or unbound Square entry was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('The Square processor movement is unreadable.', $exception->getMessage());
        }
    }

    public function test_a_resolved_source_cannot_rebind_an_entry_to_a_foreign_payment(): void
    {
        $this->expectException(DomainException::class);
        SquareProcessorMovementFacts::from($this->entry(), ['id' => 'refund_synthetic', 'payment_id' => 'payment_foreign']);
    }

    public function test_a_resolved_source_must_match_the_actual_entry_reference(): void
    {
        $this->expectException(DomainException::class);
        SquareProcessorMovementFacts::from($this->entry(), ['id' => 'refund_foreign', 'payment_id' => 'payment_synthetic']);
    }

    public function test_a_caller_cannot_supply_an_unreferenced_source_as_payment_proof(): void
    {
        $entry = $this->entry();
        unset($entry['type_refund_details']['refund_id']);
        $this->expectException(DomainException::class);
        SquareProcessorMovementFacts::from($entry, ['id' => 'refund_synthetic', 'payment_id' => 'payment_synthetic']);
    }

    /** @return array<string, mixed> */
    private function entry(): array
    {
        return ['id' => 'poe_synthetic', 'payout_id' => 'payout_synthetic', 'type' => 'REFUND', 'effective_at' => '2029-12-31T23:30:00.123456-08:00',
            'gross_amount_money' => $this->money(-100), 'fee_amount_money' => $this->money(-3), 'net_amount_money' => $this->money(-97),
            'type_refund_details' => ['payment_id' => 'payment_synthetic', 'refund_id' => 'refund_synthetic']];
    }

    /** @return array{amount: int, currency: string} */
    private function money(int $amount): array
    {
        return ['amount' => $amount, 'currency' => 'USD'];
    }
}
