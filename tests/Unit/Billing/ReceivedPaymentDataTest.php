<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\ReceivedPaymentData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Pure request facts: neither models nor a database are needed to parse money received. */
final class ReceivedPaymentDataTest extends TestCase
{
    public function test_agent_facts_are_succeeded_and_optional_web_facts_remain_available_only_to_web_callers(): void
    {
        $input = ['amount' => '1200', 'currency' => 'USD', 'method' => 'Synthetic wire', 'received_on' => '2026-10-08', 'reference' => 'SYNTHETIC-REFERENCE', 'status' => 'pending', 'notes' => 'Synthetic private bookkeeping'];
        $agent = ReceivedPaymentData::from($input);
        $this->assertSame(1200, $agent->amount);
        $this->assertSame(InvoicePaymentStatus::Succeeded, $agent->status);
        $this->assertNull($agent->notes);
        $web = ReceivedPaymentData::from($input, requireDate: false, includeBookkeeping: true);
        $this->assertSame(InvoicePaymentStatus::Pending, $web->status);
        $this->assertSame('Synthetic private bookkeeping', $web->notes);
        unset($input['received_on']);
        $this->assertNull(ReceivedPaymentData::from($input, requireDate: false, includeBookkeeping: true)->receivedOn);
    }

    public function test_domain_keys_preserve_existing_receipts_and_separate_operations(): void
    {
        $standalone = RecordReceivedPayment::agentKey(17, 'synthetic-client', 'synthetic-key');
        $issue = RecordReceivedPayment::agentKey(17, 'synthetic-client', 'synthetic-key', 'invoices.issue');
        $this->assertSame('agent-payment:'.hash('sha256', json_encode([17, 'synthetic-client', 'synthetic-key'], JSON_THROW_ON_ERROR)), $standalone);
        $this->assertSame('agent-payment:'.hash('sha256', json_encode([17, 'synthetic-client', 'invoices.issue', 'synthetic-key'], JSON_THROW_ON_ERROR)), $issue);
        $this->assertNotSame($standalone, $issue);
        $this->assertNotSame($standalone, RecordReceivedPayment::agentKey(18, 'synthetic-client', 'synthetic-key'));
        $this->assertNotSame($standalone, RecordReceivedPayment::agentKey(17, 'another-client', 'synthetic-key'));
    }

    public function test_public_rules_require_received_money_facts_and_exclude_bookkeeping_by_default(): void
    {
        $rules = ReceivedPaymentData::rules();
        $this->assertSame(['amount', 'currency', 'received_on', 'method', 'reference'], array_keys($rules));
        $this->assertSame(['amount', 'currency', 'received_on', 'method'], Validator::make([], $rules)->errors()->keys());
    }

    public function test_nullable_web_facts_preserve_the_full_persistence_attributes_and_key_override(): void
    {
        $input = ['amount' => 1200, 'currency' => 'USD', 'method' => 'Synthetic wire', 'received_on' => null,
            'reference' => null, 'notes' => null, 'status' => null, 'external_finance_transaction_uuid' => null, 'idempotency_key' => null];
        $facts = ReceivedPaymentData::from($input, requireDate: false, includeBookkeeping: true);
        $this->assertSame(['amount' => 1200, 'currency' => 'USD', 'received_on' => null, 'method' => 'Synthetic wire',
            'reference' => null, 'status' => 'succeeded', 'notes' => null, 'external_finance_transaction_uuid' => null, 'idempotency_key' => null], $facts->attributes());
        $this->assertSame('synthetic-adapter-key', $facts->attributes('synthetic-adapter-key')['idempotency_key']);
        $bookkeeping = ['reference' => 'SYNTHETIC-REFERENCE', 'notes' => 'Synthetic private notes', 'status' => 'pending',
            'external_finance_transaction_uuid' => '11111111-1111-4111-8111-111111111111', 'idempotency_key' => 'synthetic-web-key'];
        $populated = ReceivedPaymentData::from($bookkeeping + $input, requireDate: false, includeBookkeeping: true);
        $this->assertSame(array_replace($facts->attributes(), $bookkeeping), $populated->attributes());
        $this->assertSame('synthetic-adapter-key', $populated->attributes('synthetic-adapter-key')['idempotency_key']);
    }

    public function test_nested_payment_validation_keeps_its_transport_error_prefix(): void
    {
        $input = ['amount' => 0, 'currency' => 'USD', 'method' => 'Synthetic wire', 'received_on' => '2026-10-08'];
        try {
            ReceivedPaymentData::from($input, errorPrefix: 'payment');
            $this->fail('Invalid received money must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['payment.amount'], array_keys($exception->errors()));
        }
        $input['amount'] = 1200;
        $this->assertSame(1200, ReceivedPaymentData::from($input, errorPrefix: 'payment')->amount);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFacts(): iterable
    {
        yield 'zero money' => [['amount' => 0]];
        yield 'negative money' => [['amount' => -1]];
        yield 'fraction money' => [['amount' => '1.5']];
        yield 'bad currency' => [['currency' => 'usd']];
        yield 'relative date' => [['received_on' => 'next Friday']];
        yield 'missing date' => [['received_on' => null]];
        yield 'long method' => [['method' => str_repeat('x', 41)]];
        yield 'long reference' => [['reference' => str_repeat('x', 256)]];
    }

    #[DataProvider('invalidFacts')]
    public function test_shared_validation_refuses_invalid_received_payment_facts(array $changes): void
    {
        $this->expectException(ValidationException::class);
        ReceivedPaymentData::from($changes + ['amount' => 1200, 'currency' => 'USD', 'method' => 'Synthetic wire', 'received_on' => '2026-10-08']);
    }
}
