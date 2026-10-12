<?php

namespace App\Services\Square;

/**
 * The fields of a Square record svc hands to an operator or agent (#438).
 *
 * An allow-list, not a filter: each shape names the fields it returns, so
 * anything Square adds later stays out until it is chosen here. Card
 * fingerprints, BINs, expiry dates, stored-card ids, receipt links and version
 * tokens are never among them; a stored card shows only as `card_on_file`.
 * Every string is bounded, and every list is capped.
 *
 * @phpstan-type Money array{amount: int, currency: string}|null
 */
final class SquarePresenter
{
    private const array SQUARE_HOSTS = ['squareup.com', 'squareupsandbox.com'];

    /**
     * @param  array<mixed>  $location
     * @return array<string, mixed>
     */
    public function location(array $location): array
    {
        return [
            'id' => self::text($location, 'id'),
            'name' => self::text($location, 'name'),
            'business_name' => self::text($location, 'business_name'),
            'status' => self::text($location, 'status', 32),
            'type' => self::text($location, 'type', 32),
            'currency' => self::text($location, 'currency', 3),
            'country' => self::text($location, 'country', 2),
            'timezone' => self::text($location, 'timezone', 64),
            'created_at' => self::text($location, 'created_at', 64),
        ];
    }

    /**
     * @param  array<mixed>  $customer
     * @return array<string, mixed>
     */
    public function customer(array $customer): array
    {
        return [
            'id' => self::text($customer, 'id'),
            'version' => self::integer($customer, 'version'),
            'given_name' => self::text($customer, 'given_name'),
            'family_name' => self::text($customer, 'family_name'),
            'nickname' => self::text($customer, 'nickname'),
            'company_name' => self::text($customer, 'company_name'),
            'email_address' => self::text($customer, 'email_address'),
            'phone_number' => self::text($customer, 'phone_number', 64),
            'reference_id' => self::text($customer, 'reference_id'),
            'creation_source' => self::text($customer, 'creation_source', 64),
            'created_at' => self::text($customer, 'created_at', 64),
            'updated_at' => self::text($customer, 'updated_at', 64),
        ];
    }

    /**
     * @param  array<mixed>  $invoice
     * @return array<string, mixed>
     */
    public function invoice(array $invoice): array
    {
        $number = self::text($invoice, 'invoice_number', 191);
        $recipient = is_array($invoice['primary_recipient'] ?? null) ? $invoice['primary_recipient'] : null;
        $methods = is_array($invoice['accepted_payment_methods'] ?? null) ? $invoice['accepted_payment_methods'] : [];

        return [
            'id' => self::text($invoice, 'id'),
            'version' => self::integer($invoice, 'version'),
            'location_id' => self::text($invoice, 'location_id'),
            'order_id' => self::text($invoice, 'order_id'),
            'invoice_number' => $number,
            ...SquareInvoiceSeries::of($number),
            'title' => self::text($invoice, 'title'),
            'description' => self::text($invoice, 'description', 2000),
            'status' => self::text($invoice, 'status', 32),
            'delivery_method' => self::text($invoice, 'delivery_method', 32),
            'public_url' => self::squareUrl($invoice['public_url'] ?? null),
            'scheduled_at' => self::text($invoice, 'scheduled_at', 64),
            'sale_or_service_date' => self::text($invoice, 'sale_or_service_date', 32),
            'timezone' => self::text($invoice, 'timezone', 64),
            'created_at' => self::text($invoice, 'created_at', 64),
            'updated_at' => self::text($invoice, 'updated_at', 64),
            'next_payment_amount_money' => self::money($invoice['next_payment_amount_money'] ?? null),
            'store_payment_method_enabled' => self::boolean($invoice, 'store_payment_method_enabled'),
            'accepted_payment_methods' => [
                'card' => self::boolean($methods, 'card'),
                'bank_account' => self::boolean($methods, 'bank_account'),
                'square_gift_card' => self::boolean($methods, 'square_gift_card'),
                'buy_now_pay_later' => self::boolean($methods, 'buy_now_pay_later'),
                'cash_app_pay' => self::boolean($methods, 'cash_app_pay'),
            ],
            'primary_recipient' => $recipient === null ? null : [
                'customer_id' => self::text($recipient, 'customer_id'),
                'given_name' => self::text($recipient, 'given_name'),
                'family_name' => self::text($recipient, 'family_name'),
                'company_name' => self::text($recipient, 'company_name'),
                'email_address' => self::text($recipient, 'email_address'),
                'phone_number' => self::text($recipient, 'phone_number', 64),
            ],
            'payment_requests' => array_map(static fn (array $request): array => [
                'uid' => self::text($request, 'uid'),
                'request_type' => self::text($request, 'request_type', 32),
                'due_date' => self::text($request, 'due_date', 32),
                'computed_amount_money' => self::money($request['computed_amount_money'] ?? null),
                'total_completed_amount_money' => self::money($request['total_completed_amount_money'] ?? null),
                'automatic_payment_source' => self::text($request, 'automatic_payment_source', 32),
                'card_on_file' => is_string($request['card_id'] ?? null) && $request['card_id'] !== '',
                'tipping_enabled' => self::boolean($request, 'tipping_enabled'),
                'reminders' => array_map(static fn (array $reminder): array => [
                    'uid' => self::text($reminder, 'uid'),
                    'relative_scheduled_days' => self::integer($reminder, 'relative_scheduled_days'),
                    'status' => self::text($reminder, 'status', 32),
                    'sent_at' => self::text($reminder, 'sent_at', 64),
                ], self::list($request['reminders'] ?? null, 10)),
            ], self::list($invoice['payment_requests'] ?? null, 20)),
            'attachment_count' => count(is_array($invoice['attachments'] ?? null) ? $invoice['attachments'] : []),
        ];
    }

    /**
     * @param  array<mixed>  $order
     * @return array<string, mixed>
     */
    public function order(array $order): array
    {
        $lines = is_array($order['line_items'] ?? null) ? $order['line_items'] : [];

        return [
            'id' => self::text($order, 'id'),
            'version' => self::integer($order, 'version'),
            'location_id' => self::text($order, 'location_id'),
            'customer_id' => self::text($order, 'customer_id'),
            'reference_id' => self::text($order, 'reference_id'),
            'state' => self::text($order, 'state', 32),
            'created_at' => self::text($order, 'created_at', 64),
            'updated_at' => self::text($order, 'updated_at', 64),
            'closed_at' => self::text($order, 'closed_at', 64),
            'line_items' => array_map(static fn (array $line): array => [
                'uid' => self::text($line, 'uid'),
                'name' => self::text($line, 'name'),
                'variation_name' => self::text($line, 'variation_name'),
                'quantity' => self::text($line, 'quantity', 32),
                'base_price_money' => self::money($line['base_price_money'] ?? null),
                'gross_sales_money' => self::money($line['gross_sales_money'] ?? null),
                'total_discount_money' => self::money($line['total_discount_money'] ?? null),
                'total_tax_money' => self::money($line['total_tax_money'] ?? null),
                'total_money' => self::money($line['total_money'] ?? null),
            ], self::list($lines, 100)),
            'line_items_truncated' => count($lines) > 100,
            'total_money' => self::money($order['total_money'] ?? null),
            'total_tax_money' => self::money($order['total_tax_money'] ?? null),
            'total_discount_money' => self::money($order['total_discount_money'] ?? null),
            'total_tip_money' => self::money($order['total_tip_money'] ?? null),
            'total_service_charge_money' => self::money($order['total_service_charge_money'] ?? null),
            'net_amount_due_money' => self::money($order['net_amount_due_money'] ?? null),
            'tenders' => array_map(static fn (array $tender): array => [
                'id' => self::text($tender, 'id'),
                'type' => self::text($tender, 'type', 32),
                'amount_money' => self::money($tender['amount_money'] ?? null),
                'payment_id' => self::text($tender, 'payment_id'),
            ], self::list($order['tenders'] ?? null, 20)),
        ];
    }

    /**
     * @param  array<mixed>  $payment
     * @return array<string, mixed>
     */
    public function payment(array $payment): array
    {
        $details = is_array($payment['card_details'] ?? null) ? $payment['card_details'] : null;
        $card = is_array($details['card'] ?? null) ? $details['card'] : [];

        return [
            'id' => self::text($payment, 'id'),
            'status' => self::text($payment, 'status', 32),
            'source_type' => self::text($payment, 'source_type', 32),
            'location_id' => self::text($payment, 'location_id'),
            'order_id' => self::text($payment, 'order_id'),
            'customer_id' => self::text($payment, 'customer_id'),
            'reference_id' => self::text($payment, 'reference_id'),
            'receipt_number' => self::text($payment, 'receipt_number', 64),
            'created_at' => self::text($payment, 'created_at', 64),
            'updated_at' => self::text($payment, 'updated_at', 64),
            'amount_money' => self::money($payment['amount_money'] ?? null),
            'tip_money' => self::money($payment['tip_money'] ?? null),
            'total_money' => self::money($payment['total_money'] ?? null),
            'approved_money' => self::money($payment['approved_money'] ?? null),
            'refunded_money' => self::money($payment['refunded_money'] ?? null),
            'processing_fee' => array_map(static fn (array $fee): array => [
                'type' => self::text($fee, 'type', 32),
                'effective_at' => self::text($fee, 'effective_at', 64),
                'amount_money' => self::money($fee['amount_money'] ?? null),
            ], self::list($payment['processing_fee'] ?? null, 10)),
            'refund_ids' => array_values(array_filter(
                array_map(static fn (mixed $id): ?string => is_string($id) ? mb_substr($id, 0, 255) : null, array_slice(is_array($payment['refund_ids'] ?? null) ? $payment['refund_ids'] : [], 0, 50)),
                static fn (?string $id): bool => $id !== null,
            )),
            'card' => $details === null ? null : [
                'status' => self::text($details, 'status', 32),
                'entry_method' => self::text($details, 'entry_method', 32),
                'card_brand' => self::text($card, 'card_brand', 32),
                'card_type' => self::text($card, 'card_type', 32),
                'last_4' => self::lastFour($card['last_4'] ?? null),
            ],
        ];
    }

    /**
     * Square's hosted invoice page, only when it is HTTPS on a Square host.
     */
    public static function squareUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::SQUARE_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        return null;
    }

    /** @return Money */
    private static function money(mixed $money): ?array
    {
        if (! is_array($money) || ! is_int($money['amount'] ?? null) || ! is_string($money['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $money['currency']) !== 1) {
            return null;
        }

        return ['amount' => $money['amount'], 'currency' => $money['currency']];
    }

    /**
     * @param  array<mixed>  $record
     */
    private static function text(array $record, string $key, int $max = 255): ?string
    {
        $value = $record[$key] ?? null;

        return is_string($value) ? mb_substr($value, 0, $max) : null;
    }

    /**
     * @param  array<mixed>  $record
     */
    private static function integer(array $record, string $key): ?int
    {
        $value = $record[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $record
     */
    private static function boolean(array $record, string $key): ?bool
    {
        $value = $record[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    private static function lastFour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}$/D', $value) === 1 ? $value : null;
    }

    /** @return list<array<mixed>> */
    private static function list(mixed $items, int $max): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_slice(array_values(array_filter($items, 'is_array')), 0, $max);
    }
}
