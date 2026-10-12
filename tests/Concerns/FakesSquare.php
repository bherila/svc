<?php

namespace Tests\Concerns;

use App\Models\Workspace;

/**
 * Synthetic Square records for the read-only pass-through (#438). Every id,
 * name, number and amount here is invented; none comes from a real account.
 */
trait FakesSquare
{
    protected const string SQUARE_TOKEN = 'synthetic-square-access-token-0001';

    protected const string SQUARE_BASE = 'https://connect.squareupsandbox.com';

    protected function connectSquare(?Workspace $workspace, array $overrides = []): void
    {
        config(['services.square' => [
            'access_token' => self::SQUARE_TOKEN,
            'environment' => 'sandbox',
            'application_id' => 'sandbox-sq0idb-synthetic',
            'version' => '2026-09-16',
            'workspace' => $workspace?->public_id,
            'timeout_seconds' => 5,
            'connect_timeout_seconds' => 2,
            'max_attempts' => 3,
            'retry_base_milliseconds' => 100,
            'rate_limit_per_minute' => 60,
            ...$overrides,
        ]]);
    }

    /** @return array<string, mixed> */
    protected static function squareLocation(string $id = 'LOCFIXTURE01'): array
    {
        return [
            'id' => $id,
            'name' => 'Synthetic studio',
            'business_name' => 'Synthetic Studio LLC',
            'status' => 'ACTIVE',
            'type' => 'PHYSICAL',
            'currency' => 'USD',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'created_at' => '2024-01-02T03:04:05Z',
            'address' => ['address_line_1' => '1 Synthetic Way'],
            'merchant_id' => 'MERCHANTFIXTURE',
        ];
    }

    /** @return array<string, mixed> */
    protected static function squareCustomer(string $id = 'cust_fixture_a'): array
    {
        return [
            'id' => $id,
            'version' => 3,
            'given_name' => 'Avery',
            'family_name' => 'Fixture',
            'company_name' => 'Fixture Holdings',
            'email_address' => 'avery@example.test',
            'phone_number' => '+15555550100',
            'reference_id' => 'ref-fixture-a',
            'creation_source' => 'THIRD_PARTY',
            'created_at' => '2024-02-03T04:05:06Z',
            'updated_at' => '2024-02-04T04:05:06Z',
            'note' => 'Private operator note',
            'address' => ['address_line_1' => '2 Synthetic Way'],
            'cards' => [['id' => 'ccof:fixture-card', 'fingerprint' => 'sq-1-fixturefingerprint', 'bin' => '411111']],
        ];
    }

    /** @return array<string, mixed> */
    protected static function squareInvoice(string $id = 'inv:0-fixture1', string $number = '000001-R-0001'): array
    {
        return [
            'id' => $id,
            'version' => 2,
            'location_id' => 'LOCFIXTURE01',
            'order_id' => 'order_fixture_1',
            'invoice_number' => $number,
            'title' => 'Monthly retainer',
            'description' => 'Synthetic recurring invoice',
            'status' => 'UNPAID',
            'delivery_method' => 'EMAIL',
            'public_url' => 'https://squareupsandbox.com/pay-invoice/inv:0-fixture1',
            'scheduled_at' => '2026-10-01T15:00:00Z',
            'sale_or_service_date' => '2026-10-01',
            'timezone' => 'America/New_York',
            'created_at' => '2026-09-01T15:00:00Z',
            'updated_at' => '2026-10-01T15:00:00Z',
            'creator_team_member_id' => 'TMFIXTURE',
            'next_payment_amount_money' => ['amount' => 12_345, 'currency' => 'USD'],
            'store_payment_method_enabled' => true,
            'accepted_payment_methods' => ['card' => true, 'bank_account' => false, 'square_gift_card' => false, 'buy_now_pay_later' => false, 'cash_app_pay' => false],
            'primary_recipient' => [
                'customer_id' => 'cust_fixture_a',
                'given_name' => 'Avery',
                'family_name' => 'Fixture',
                'email_address' => 'avery@example.test',
                'company_name' => 'Fixture Holdings',
                'phone_number' => '+15555550100',
                'address' => ['address_line_1' => '2 Synthetic Way'],
            ],
            'payment_requests' => [[
                'uid' => 'pr-fixture-1',
                'request_type' => 'BALANCE',
                'due_date' => '2026-10-15',
                'computed_amount_money' => ['amount' => 12_345, 'currency' => 'USD'],
                'total_completed_amount_money' => ['amount' => 0, 'currency' => 'USD'],
                'automatic_payment_source' => 'CARD_ON_FILE',
                'card_id' => 'ccof:fixture-card',
                'tipping_enabled' => false,
                'reminders' => [['uid' => 'rem-fixture-1', 'relative_scheduled_days' => -1, 'status' => 'PENDING']],
            ]],
            'attachments' => [['attachment_id' => 'att-fixture', 'filename' => 'fixture.pdf']],
        ];
    }

    /** @return array<string, mixed> */
    protected static function squareOrder(string $id = 'order_fixture_1'): array
    {
        return [
            'id' => $id,
            'version' => 4,
            'location_id' => 'LOCFIXTURE01',
            'customer_id' => 'cust_fixture_a',
            'state' => 'OPEN',
            'created_at' => '2026-09-01T15:00:00Z',
            'updated_at' => '2026-09-01T15:00:00Z',
            'line_items' => [[
                'uid' => 'li-fixture-1',
                'name' => 'Retainer hours',
                'quantity' => '1',
                'base_price_money' => ['amount' => 12_345, 'currency' => 'USD'],
                'gross_sales_money' => ['amount' => 12_345, 'currency' => 'USD'],
                'total_discount_money' => ['amount' => 0, 'currency' => 'USD'],
                'total_tax_money' => ['amount' => 0, 'currency' => 'USD'],
                'total_money' => ['amount' => 12_345, 'currency' => 'USD'],
                'catalog_object_id' => 'CATFIXTURE',
            ]],
            'total_money' => ['amount' => 12_345, 'currency' => 'USD'],
            'tenders' => [['id' => 'tender_fixture_1', 'type' => 'CARD', 'amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'payment_id' => 'pay_fixture_1', 'card_details' => ['card' => ['fingerprint' => 'sq-1-fixturefingerprint']]]],
        ];
    }

    /** @return array<string, mixed> */
    protected static function squarePayment(string $id = 'pay_fixture_1'): array
    {
        return [
            'id' => $id,
            'status' => 'COMPLETED',
            'source_type' => 'CARD',
            'location_id' => 'LOCFIXTURE01',
            'order_id' => 'order_fixture_1',
            'customer_id' => 'cust_fixture_a',
            'receipt_number' => 'FIXT',
            'receipt_url' => 'https://squareupsandbox.com/receipt/preview/fixture-receipt-token',
            'version_token' => 'fixture-version-token',
            'created_at' => '2026-10-15T16:00:00Z',
            'updated_at' => '2026-10-15T16:00:05Z',
            'amount_money' => ['amount' => 12_345, 'currency' => 'USD'],
            'total_money' => ['amount' => 12_345, 'currency' => 'USD'],
            'approved_money' => ['amount' => 12_345, 'currency' => 'USD'],
            'processing_fee' => [['effective_at' => '2026-10-15T18:00:00Z', 'type' => 'INITIAL', 'amount_money' => ['amount' => 388, 'currency' => 'USD']]],
            'buyer_email_address' => 'avery@example.test',
            'card_details' => [
                'status' => 'CAPTURED',
                'entry_method' => 'ON_FILE',
                'card' => [
                    'card_brand' => 'VISA',
                    'last_4' => '1111',
                    'exp_month' => 12,
                    'exp_year' => 2030,
                    'fingerprint' => 'sq-1-fixturefingerprint',
                    'card_type' => 'CREDIT',
                    'bin' => '411111',
                ],
                'avs_status' => 'AVS_ACCEPTED',
            ],
        ];
    }
}
