<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinanceDoor;
use App\Support\Finance\FinancePaymentQuery;
use App\Support\Finance\ReconciliationAllocation;
use App\Support\Finance\ReconciliationTarget;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The finance reconciliation inputs (#457), parsed the same way at every door
 * and exactly as the finance API always parsed them. No database.
 */
final class FinanceReconciliationInputTest extends TestCase
{
    private const string PAYMENT = '00000000-0000-4000-8000-00000000000a';

    private const string TRANSACTION = '00000000-0000-4000-8000-00000000000B';

    public function test_the_query_defaults_to_one_page_of_fifty_succeeded_payments(): void
    {
        $query = FinancePaymentQuery::fromInput([]);

        $this->assertSame(InvoicePaymentStatus::Succeeded, $query->status);
        $this->assertNull($query->invoicePublicId);
        $this->assertNull($query->receivedFrom);
        $this->assertNull($query->receivedTo);
        $this->assertSame(50, $query->perPage);
        $this->assertSame(1, $query->page);
    }

    public function test_the_query_reads_every_filter(): void
    {
        $query = FinancePaymentQuery::fromInput([
            'status' => 'canceled', 'invoice' => self::PAYMENT, 'received_from' => '2026-08-01',
            'received_to' => '2026-08-31', 'per_page' => '100', 'page' => '3',
        ]);

        $this->assertSame(InvoicePaymentStatus::Canceled, $query->status);
        $this->assertSame(self::PAYMENT, $query->invoicePublicId);
        $this->assertSame('2026-08-01', $query->receivedFrom);
        $this->assertSame('2026-08-31', $query->receivedTo);
        $this->assertSame(100, $query->perPage);
        $this->assertSame(3, $query->page);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusablePages(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-2'];
        yield 'text' => ['last'];
        yield 'fraction' => ['1.5'];
    }

    #[DataProvider('unusablePages')]
    public function test_an_unusable_page_is_the_first_as_the_paginator_always_read_it(mixed $page): void
    {
        $this->assertSame(1, FinancePaymentQuery::fromInput(['page' => $page])->page);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'unknown status' => [['status' => 'reconciling'], 'status'];
        yield 'invoice not a uuid' => [['invoice' => 'INV-1'], 'invoice'];
        yield 'bad date' => [['received_from' => '2026-13-01'], 'received_from'];
        yield 'window backwards' => [['received_from' => '2026-08-02', 'received_to' => '2026-08-01'], 'received_to'];
        yield 'page too large' => [['per_page' => '101'], 'per_page'];
        yield 'page too small' => [['per_page' => '0'], 'per_page'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidQueries')]
    public function test_an_invalid_filter_names_its_field(array $input, string $field): void
    {
        try {
            FinancePaymentQuery::fromInput($input);
            $this->fail('Accepted '.json_encode($input));
        } catch (ValidationException $exception) {
            $this->assertSame([$field], array_keys($exception->errors()));
        }
    }

    public function test_an_allocation_that_does_not_say_leaves_its_activity_to_the_allocation(): void
    {
        $allocation = ReconciliationAllocation::fromInput(['allocated_amount' => '2500', 'currency' => 'USD', 'unknown' => 'ignored']);

        $this->assertSame(2500, $allocation->allocatedAmount);
        $this->assertSame('USD', $allocation->currency);
        $this->assertNull($allocation->reconciledOn);
        $this->assertNull($allocation->isActive);
        $target = ReconciliationTarget::of(self::PAYMENT, 'synthetic-ledger', self::TRANSACTION);
        $this->assertSame([
            'external_system_slug' => 'synthetic-ledger',
            'external_transaction_uuid' => strtolower(self::TRANSACTION),
            'allocated_amount' => 2500,
            'currency' => 'USD',
            'reconciled_on' => null,
            'is_active' => null,
        ], $allocation->attributesFor($target));
    }

    public function test_a_door_that_needs_the_activity_stated_refuses_an_allocation_without_it(): void
    {
        try {
            ReconciliationAllocation::fromInput(['allocated_amount' => 1, 'currency' => 'USD'], statesActivity: true);
            $this->fail('Accepted an allocation without is_active.');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_active'], array_keys($exception->errors()));
        }
        $this->assertFalse(ReconciliationAllocation::fromInput(['allocated_amount' => 1, 'currency' => 'USD', 'is_active' => false], statesActivity: true)->isActive);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function activity(): iterable
    {
        yield 'true' => [true, true];
        yield 'one' => [1, true];
        yield 'text one' => ['1', true];
        yield 'false' => [false, false];
        yield 'zero' => [0, false];
        yield 'text zero' => ['0', false];
    }

    #[DataProvider('activity')]
    public function test_is_active_takes_the_spellings_the_boolean_rule_admits(mixed $given, bool $active): void
    {
        $allocation = ReconciliationAllocation::fromInput(['allocated_amount' => 1, 'currency' => 'USD', 'is_active' => $given, 'reconciled_on' => '2026-08-15']);

        $this->assertSame($active, $allocation->isActive);
        $this->assertSame('2026-08-15', $allocation->reconciledOn);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidAllocations(): iterable
    {
        yield 'no amount' => [['currency' => 'USD'], 'allocated_amount'];
        yield 'zero amount' => [['allocated_amount' => 0, 'currency' => 'USD'], 'allocated_amount'];
        yield 'fractional amount' => [['allocated_amount' => 1.5, 'currency' => 'USD'], 'allocated_amount'];
        yield 'lowercase currency' => [['allocated_amount' => 1, 'currency' => 'usd'], 'currency'];
        yield 'no currency' => [['allocated_amount' => 1], 'currency'];
        yield 'bad date' => [['allocated_amount' => 1, 'currency' => 'USD', 'reconciled_on' => '15/08/2026'], 'reconciled_on'];
        yield 'not boolean' => [['allocated_amount' => 1, 'currency' => 'USD', 'is_active' => 'yes'], 'is_active'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidAllocations')]
    public function test_an_invalid_allocation_names_its_field(array $input, string $field): void
    {
        try {
            ReconciliationAllocation::fromInput($input);
            $this->fail('Accepted '.json_encode($input));
        } catch (ValidationException $exception) {
            $this->assertSame([$field], array_keys($exception->errors()));
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidTargets(): iterable
    {
        yield 'payment not a uuid' => ['nope', 'synthetic-ledger', self::TRANSACTION, 'payment'];
        yield 'system not a slug' => [self::PAYMENT, 'Synthetic Ledger', self::TRANSACTION, 'external_system_slug'];
        yield 'system too long' => [self::PAYMENT, str_repeat('a', 81), self::TRANSACTION, 'external_system_slug'];
        yield 'transaction not a uuid' => [self::PAYMENT, 'synthetic-ledger', 'txn-1', 'external_transaction_uuid'];
    }

    #[DataProvider('invalidTargets')]
    public function test_an_invalid_target_names_its_field(string $payment, string $system, string $transaction, string $field): void
    {
        try {
            ReconciliationTarget::of($payment, $system, $transaction);
            $this->fail('Accepted '.$system);
        } catch (ValidationException $exception) {
            $this->assertSame([$field], array_keys($exception->errors()));
        }
    }

    public function test_a_target_of_the_longest_slug_is_accepted(): void
    {
        $target = ReconciliationTarget::of(self::PAYMENT, str_repeat('a', 80), self::TRANSACTION);

        $this->assertSame(self::PAYMENT, $target->paymentPublicId);
        $this->assertSame(str_repeat('a', 80), $target->externalSystem);
        $this->assertSame(strtolower(self::TRANSACTION), $target->externalTransactionUuid);
    }

    public function test_only_the_finance_token_lets_a_member_read(): void
    {
        $user = new User;
        $stored = (new PersonalAccessToken)->forceFill(['id' => 7]);
        $this->assertTrue(FinanceCaller::financeToken($user, $stored)->door->membersMayRead());
        $this->assertFalse(FinanceCaller::oauth($user, 'synthetic-client')->door->membersMayRead());
        $this->assertFalse(FinanceCaller::operator($user)->door->membersMayRead());

        $this->assertSame('finance-token:7', FinanceCaller::financeToken($user, $stored)->credential);
        $this->assertSame('finance-token:transient', FinanceCaller::financeToken($user, new TransientToken)->credential);
        $this->assertSame('synthetic-client', FinanceCaller::oauth($user, 'synthetic-client')->credential);
        $this->assertSame('console', FinanceCaller::operator($user)->credential);
        $this->assertSame(FinanceDoor::Console, FinanceCaller::operator($user)->door);
        $this->assertSame($user, FinanceCaller::operator($user)->user);
    }
}
