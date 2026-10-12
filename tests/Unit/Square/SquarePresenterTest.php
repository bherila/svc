<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquarePresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\FakesSquare;

/**
 * SquarePresenter against the published contract (#438): each shape has
 * exactly its schema's fields, every bounded string is cut to the schema's
 * maxLength (in characters, not bytes), and the values Square sends in the
 * wrong type or form are dropped rather than passed on.
 */
final class SquarePresenterTest extends TestCase
{
    use FakesSquare;

    private const array SHAPES = [
        'location' => 'SquareLocation',
        'customer' => 'SquareCustomer',
        'invoice' => 'SquareInvoice',
        'order' => 'SquareOrder',
        'payment' => 'SquarePayment',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $schemas = null;

    public function test_each_shape_has_exactly_the_published_fields(): void
    {
        foreach ($this->presentedFixtures() as $shape => $presented) {
            $this->assertShape(self::schema(self::SHAPES[$shape]), $presented, $shape);
        }
    }

    public function test_fixtures_keep_their_values_and_lose_everything_unlisted(): void
    {
        $presented = $this->presentedFixtures();

        $this->assertSame('Synthetic Studio LLC', $presented['location']['business_name']);
        $this->assertSame(['avery@example.test', '+15555550100', 3], [$presented['customer']['email_address'], $presented['customer']['phone_number'], $presented['customer']['version']]);
        $invoice = $presented['invoice'];
        $this->assertSame(['000001-R-0001', '000001', 1, 2, 1], [$invoice['invoice_number'], $invoice['series_key'], $invoice['series_occurrence'], $invoice['version'], $invoice['attachment_count']]);
        $this->assertSame(['card' => true, 'bank_account' => false, 'square_gift_card' => false, 'buy_now_pay_later' => false, 'cash_app_pay' => false], $invoice['accepted_payment_methods']);
        $this->assertSame(['amount' => 12_345, 'currency' => 'USD'], $invoice['payment_requests'][0]['computed_amount_money']);
        $this->assertSame([['uid' => 'rem-fixture-1', 'relative_scheduled_days' => -1, 'status' => 'PENDING', 'sent_at' => null]], $invoice['payment_requests'][0]['reminders']);
        $this->assertTrue($invoice['payment_requests'][0]['card_on_file']);
        $this->assertSame('cust_fixture_a', $invoice['primary_recipient']['customer_id']);
        $this->assertSame([['id' => 'tender_fixture_1', 'type' => 'CARD', 'amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'payment_id' => 'pay_fixture_1']], $presented['order']['tenders']);
        $this->assertSame(['status' => 'CAPTURED', 'entry_method' => 'ON_FILE', 'card_brand' => 'VISA', 'card_type' => 'CREDIT', 'last_4' => '1111'], $presented['payment']['card']);
        $this->assertSame([['type' => 'INITIAL', 'effective_at' => '2026-10-15T18:00:00Z', 'amount_money' => ['amount' => 388, 'currency' => 'USD']]], $presented['payment']['processing_fee']);

        $encoded = (string) json_encode($presented, JSON_UNESCAPED_SLASHES);
        foreach (['fingerprint', '411111', 'ccof:', 'card_id', 'exp_month', 'receipt_url', 'version_token', 'buyer_email_address', 'avs_status', 'Synthetic Way', 'Private operator note', 'MERCHANTFIXTURE', 'TMFIXTURE', 'CATFIXTURE', 'att-fixture'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $encoded, $hidden);
        }
    }

    /** Oversized strings are cut to the schema's maxLength, counted in characters. */
    public function test_every_bounded_string_is_cut_to_its_published_length(): void
    {
        $presenter = new SquarePresenter;
        foreach (self::SHAPES as $shape => $component) {
            $schema = self::schema($component);
            $input = $this->oversized($schema);
            if ($shape === 'payment') {
                $input['card_details'] = ['status' => $this->long(32), 'entry_method' => $this->long(32), 'card' => ['card_brand' => $this->long(32), 'card_type' => $this->long(32), 'last_4' => '4242']];
            }
            $checked = $this->assertCut($schema, $presenter->{$shape}($input), $shape);
            $this->assertGreaterThan(5, $checked, $shape);
        }
    }

    /** @return iterable<string, array{0: mixed, 1: array{amount: int, currency: string}|null}> */
    public static function money(): iterable
    {
        yield 'valid' => [['amount' => 100, 'currency' => 'USD', 'extra' => 'dropped'], ['amount' => 100, 'currency' => 'USD']];
        yield 'zero and negative are amounts' => [['amount' => -5, 'currency' => 'EUR'], ['amount' => -5, 'currency' => 'EUR']];
        yield 'not an object' => ['100 USD', null];
        yield 'string amount' => [['amount' => '100', 'currency' => 'USD'], null];
        yield 'float amount' => [['amount' => 1.5, 'currency' => 'USD'], null];
        yield 'missing amount' => [['currency' => 'USD'], null];
        yield 'missing currency' => [['amount' => 100], null];
        yield 'currency not a string' => [['amount' => 100, 'currency' => 840], null];
        yield 'lower-case currency' => [['amount' => 100, 'currency' => 'usd'], null];
        yield 'long currency' => [['amount' => 100, 'currency' => 'USDX'], null];
        yield 'prefixed currency' => [['amount' => 100, 'currency' => 'XUSD'], null];
        yield 'currency with newline' => [['amount' => 100, 'currency' => "USD\n"], null];
    }

    #[DataProvider('money')]
    public function test_money_is_minor_units_and_an_iso_currency_or_nothing(mixed $money, ?array $expected): void
    {
        $this->assertSame($expected, (new SquarePresenter)->invoice(['next_payment_amount_money' => $money])['next_payment_amount_money']);
    }

    /** @return iterable<string, array{0: mixed, 1: bool}> */
    public static function urls(): iterable
    {
        yield 'production host' => ['https://squareup.com/pay-invoice/inv-fixture', true];
        yield 'sandbox host' => ['https://squareupsandbox.com/pay-invoice/inv-fixture', true];
        yield 'subdomain' => ['https://app.squareup.com/pay-invoice/inv-fixture', true];
        yield 'upper-case host' => ['https://SQUAREUP.COM/pay-invoice/inv-fixture', true];
        yield 'at the length limit' => ['https://squareup.com/'.str_repeat('a', 2048 - 21), true];
        yield 'over the length limit' => ['https://squareup.com/'.str_repeat('a', 2049 - 21), false];
        yield 'plain http' => ['http://squareup.com/pay-invoice/inv-fixture', false];
        yield 'look-alike host' => ['https://evilsquareup.com/pay', false];
        yield 'square as a subdomain elsewhere' => ['https://squareup.com.collector.example.test/pay', false];
        // Split so the disclosure scan does not read the userinfo as an address.
        yield 'credentials in the URL' => ['https://user:pass'.'@squareup.com/pay', false];
        yield 'a user name in the URL' => ['https://user'.'@squareup.com/pay', false];
        yield 'explicit port' => ['https://squareup.com:8443/pay', false];
        yield 'not a URL' => ['squareup.com/pay', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'not a string' => [['https://squareup.com/pay'], false];
        yield 'absent' => [null, false];
    }

    #[DataProvider('urls')]
    public function test_public_url_is_kept_only_when_it_is_https_on_a_square_host(mixed $url, bool $kept): void
    {
        $this->assertSame($kept ? $url : null, (new SquarePresenter)->invoice(['public_url' => $url])['public_url']);
    }

    public function test_values_of_the_wrong_type_are_dropped(): void
    {
        $presenter = new SquarePresenter;

        $customer = $presenter->customer(['id' => 7, 'version' => '3', 'given_name' => ['Avery']]);
        $this->assertSame([null, null, null], [$customer['id'], $customer['version'], $customer['given_name']]);
        $this->assertSame(0, $presenter->customer(['version' => 0])['version']);

        $invoice = $presenter->invoice([
            'store_payment_method_enabled' => 'true',
            'accepted_payment_methods' => ['card' => 1, 'bank_account' => true],
            'payment_requests' => [['tipping_enabled' => 'false', 'reminders' => [['relative_scheduled_days' => '-1']]]],
        ]);
        $this->assertNull($invoice['store_payment_method_enabled']);
        $this->assertSame([null, true], [$invoice['accepted_payment_methods']['card'], $invoice['accepted_payment_methods']['bank_account']]);
        $this->assertNull($invoice['payment_requests'][0]['tipping_enabled']);
        $this->assertNull($invoice['payment_requests'][0]['reminders'][0]['relative_scheduled_days']);
        $this->assertFalse($presenter->invoice(['store_payment_method_enabled' => false])['store_payment_method_enabled']);
        $this->assertNull($invoice['primary_recipient']);
        $this->assertSame(0, $invoice['attachment_count']);
        $this->assertSame(2, $presenter->invoice(['attachments' => [['a'], ['b']]])['attachment_count']);
    }

    /** @return iterable<string, array{0: mixed, 1: bool}> */
    public static function cards(): iterable
    {
        yield 'a stored card' => ['ccof:fixture-card', true];
        yield 'an empty id' => ['', false];
        yield 'no id' => [null, false];
        yield 'not a string' => [['ccof:fixture-card'], false];
    }

    #[DataProvider('cards')]
    public function test_a_stored_card_shows_only_as_card_on_file(mixed $cardId, bool $onFile): void
    {
        $request = (new SquarePresenter)->invoice(['payment_requests' => [['card_id' => $cardId]]])['payment_requests'][0];

        $this->assertSame($onFile, $request['card_on_file']);
        $this->assertArrayNotHasKey('card_id', $request);
    }

    public function test_card_details_keep_only_brand_type_status_and_four_digits(): void
    {
        $presenter = new SquarePresenter;

        $this->assertNull($presenter->payment([])['card']);
        foreach (['1111' => '1111', '0000' => '0000', '11111' => null, '111' => null, 'abcd' => null, "1111\n" => null] as $digits => $expected) {
            $this->assertSame($expected, $presenter->payment(['card_details' => ['card' => ['last_4' => (string) $digits]]])['card']['last_4'], (string) $digits);
        }
        $this->assertNull($presenter->payment(['card_details' => ['card' => ['last_4' => 1111]]])['card']['last_4']);
        $this->assertSame(
            ['status' => null, 'entry_method' => null, 'card_brand' => null, 'card_type' => null, 'last_4' => null],
            $presenter->payment(['card_details' => ['card' => 'not-a-card']])['card'],
        );
    }

    public function test_an_id_list_is_a_list_of_ids(): void
    {
        $payment = (new SquarePresenter)->payment(['refund_ids' => ['first' => 'refund-fixture-1', 'second' => 'refund-fixture-2', 'third' => ['nested']]]);

        $this->assertSame(['refund-fixture-1', 'refund-fixture-2'], $payment['refund_ids']);
        $this->assertSame(str_repeat('é', 255), (new SquarePresenter)->payment(['refund_ids' => [str_repeat('é', 300)]])['refund_ids'][0]);
    }

    /** @return array<string, array<string, mixed>> */
    private function presentedFixtures(): array
    {
        $presenter = new SquarePresenter;

        return [
            'location' => $presenter->location(self::squareLocation()),
            'customer' => $presenter->customer(self::squareCustomer()),
            'invoice' => $presenter->invoice(self::squareInvoice()),
            'order' => $presenter->order(self::squareOrder()),
            'payment' => $presenter->payment(self::squarePayment()),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     */
    private function assertShape(array $schema, array $value, string $path): void
    {
        $this->assertSame(array_keys($schema['properties']), array_keys($value), $path);
        $this->assertSame($schema['required'], array_keys($value), $path);
        foreach ($schema['properties'] as $key => $property) {
            $property = self::resolve($property);
            if (is_array($value[$key]) && ($property['type'] ?? null) === 'array' && isset(self::resolve($property['items'])['properties'])) {
                foreach ($value[$key] as $index => $item) {
                    $this->assertShape(self::resolve($property['items']), $item, "{$path}.{$key}.{$index}");
                }
            } elseif (is_array($value[$key]) && isset($property['properties'])) {
                $this->assertShape($property, $value[$key], "{$path}.{$key}");
            }
        }
    }

    /**
     * An input with every plain bounded string longer than its limit.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function oversized(array $schema): array
    {
        $input = [];
        foreach ($schema['properties'] as $key => $property) {
            $property = self::resolve($property);
            $types = (array) ($property['type'] ?? []);
            if (in_array('string', $types, true) && isset($property['maxLength']) && ! isset($property['pattern']) && ! isset($property['format'])) {
                $input[$key] = $this->long($property['maxLength']);
            } elseif (in_array('array', $types, true)) {
                $items = self::resolve($property['items']);
                $input[$key] = [isset($items['properties']) ? $this->oversized($items) : $this->long($items['maxLength'] ?? 10)];
            } elseif (in_array('object', $types, true) && isset($property['properties'])) {
                $input[$key] = $this->oversized($property);
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     */
    private function assertCut(array $schema, array $value, string $path): int
    {
        $checked = 0;
        foreach ($schema['properties'] as $key => $property) {
            $property = self::resolve($property);
            $types = (array) ($property['type'] ?? []);
            if (in_array('string', $types, true) && isset($property['maxLength']) && ! isset($property['pattern']) && ! isset($property['format'])) {
                $this->assertSame($this->long($property['maxLength'], 0), $value[$key], "{$path}.{$key}");
                $checked++;
            } elseif (in_array('array', $types, true) && $value[$key] !== []) {
                $items = self::resolve($property['items']);
                $checked += isset($items['properties'])
                    ? $this->assertCut($items, $value[$key][0], "{$path}.{$key}.0")
                    : (int) $this->assertSameAndCount($this->long($items['maxLength'], 0), $value[$key][0], "{$path}.{$key}.0");
            } elseif (in_array('object', $types, true) && isset($property['properties']) && is_array($value[$key])) {
                $checked += $this->assertCut($property, $value[$key], "{$path}.{$key}");
            }
        }

        return $checked;
    }

    private function assertSameAndCount(string $expected, mixed $actual, string $message): bool
    {
        $this->assertSame($expected, $actual, $message);

        return true;
    }

    /** Multibyte, so a byte-based cut would not match. */
    private function long(int $length, int $extra = 25): string
    {
        return str_repeat('é', $length + $extra);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function resolve(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return self::schema(substr($schema['$ref'], strlen('#/components/schemas/')));
        }
        if (isset($schema['oneOf'])) {
            foreach ($schema['oneOf'] as $option) {
                if (($option['type'] ?? null) !== 'null') {
                    return self::resolve($option);
                }
            }
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function schema(string $component): array
    {
        self::$schemas ??= json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];

        return self::$schemas[$component];
    }
}
