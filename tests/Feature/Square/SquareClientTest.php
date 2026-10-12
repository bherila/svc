<?php

namespace Tests\Feature\Square;

use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareEndpoint;
use App\Services\Square\SquareRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;
use Throwable;

final class SquareClientTest extends TestCase
{
    use FakesSquare;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->connectSquare(null, ['workspace' => 'synthetic-workspace']);
    }

    public function test_every_read_sends_the_pinned_version_and_credential_to_an_allow_listed_endpoint_only(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response(['ok' => true])]);
        $client = app(SquareClient::class);

        $client->listLocations();
        $client->listCustomers('cursor-fixture', 500);
        $client->searchCustomers(['email_address' => ['fuzzy' => 'avery@example.test']], null, 25);
        $client->retrieveCustomer('cust_fixture_a');
        $client->listInvoices('LOCFIXTURE01', null, 500);
        $client->searchInvoices('LOCFIXTURE01', 'cust_fixture_a', true, 'cursor-fixture', 10);
        $client->getInvoice('inv:0-fixture1');
        $client->retrieveOrder('order_fixture_1');
        $client->listPayments('2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', 'LOCFIXTURE01', false, null, 500);
        $client->getPayment('pay_fixture_1');

        $allowed = [];
        foreach (SquareEndpoint::cases() as $endpoint) {
            $allowed[] = $endpoint->method().' '.preg_quote($endpoint->path(), '~');
        }
        $pattern = '~^(?:'.str_replace('\{id\}', '[^/?]+', implode('|', $allowed)).')(?:\?.*)?$~';
        $recorded = Http::recorded();
        $this->assertCount(10, $recorded);
        foreach ($recorded as [$request]) {
            /** @var Request $request */
            $this->assertStringStartsWith(self::SQUARE_BASE.'/v2/', $request->url());
            $this->assertMatchesRegularExpression($pattern, $request->method().' '.substr($request->url(), strlen(self::SQUARE_BASE)));
            $this->assertSame(['Bearer '.self::SQUARE_TOKEN], $request->header('Authorization'));
            $this->assertSame(['2026-09-16'], $request->header('Square-Version'));
        }

        // Limits are clamped to Square's own maxima; filters pass through as given.
        $this->assertStringContainsString('limit=100', $recorded[1][0]->url());
        $this->assertStringContainsString('cursor=cursor-fixture', $recorded[1][0]->url());
        $this->assertSame(['limit' => 25, 'query' => ['filter' => ['email_address' => ['fuzzy' => 'avery@example.test']]]], $recorded[2][0]->data());
        $this->assertStringContainsString('location_id=LOCFIXTURE01', $recorded[4][0]->url());
        $this->assertStringContainsString('limit=200', $recorded[4][0]->url());
        $this->assertSame([
            'cursor' => 'cursor-fixture',
            'limit' => 10,
            'query' => ['filter' => ['location_ids' => ['LOCFIXTURE01'], 'customer_ids' => ['cust_fixture_a']], 'sort' => ['field' => 'INVOICE_SORT_DATE', 'order' => 'DESC']],
        ], $recorded[5][0]->data());
        $this->assertSame(self::SQUARE_BASE.'/v2/invoices/inv%3A0-fixture1', $recorded[6][0]->url());
        $this->assertStringContainsString('sort_order=ASC', $recorded[8][0]->url());
        $this->assertStringContainsString('limit=100', $recorded[8][0]->url());
    }

    public function test_reads_retry_429_and_5xx_with_backoff_then_succeed(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::sequence()
            ->push(['errors' => [['category' => 'RATE_LIMIT_ERROR', 'code' => 'RATE_LIMITED']]], 429, ['Retry-After' => '1'])
            ->push(['errors' => [['category' => 'API_ERROR', 'code' => 'SERVICE_UNAVAILABLE']]], 503)
            ->push(['invoice' => ['id' => 'inv:0-fixture1']])]);

        $this->assertSame(['invoice' => ['id' => 'inv:0-fixture1']], app(SquareClient::class)->getInvoice('inv:0-fixture1'));

        Http::assertSentCount(3);
        // Retry-After (one second) outranks the base delay; the next doubles the base.
        Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(200)->milliseconds()]);
    }

    public function test_searches_are_reads_and_retry_too(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::sequence()->push([], 500)->push(['invoices' => []])]);

        app(SquareClient::class)->searchInvoices('LOCFIXTURE01', null, true, null, 25);

        Http::assertSentCount(2);
    }

    public function test_a_long_retry_after_is_answered_at_once_rather_than_waited_out(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response(['errors' => [['category' => 'RATE_LIMIT_ERROR', 'code' => 'RATE_LIMITED']]], 429, ['Retry-After' => '60'])]);

        $failure = $this->failure(fn () => app(SquareClient::class)->listLocations());

        $this->assertSame(429, $failure->getStatusCode());
        $this->assertSame('60', $failure->getHeaders()['Retry-After']);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_server_errors_stop_after_the_configured_attempts(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response(['errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR']]], 500)]);

        $failure = $this->failure(fn () => app(SquareClient::class)->listLocations());

        $this->assertSame(503, $failure->getStatusCode());
        $this->assertSame('Square is unavailable. Retry later.', $failure->getMessage());
        Http::assertSentCount(3);
    }

    public function test_a_rejected_request_is_not_retried_and_keeps_only_squares_category_and_code(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response(['errors' => [[
            'category' => 'INVALID_REQUEST_ERROR',
            'code' => 'INVALID_CURSOR',
            'detail' => 'Cursor rejected for request with Authorization Bearer '.self::SQUARE_TOKEN,
            'field' => 'cursor',
        ]]], 400)]);

        $failure = $this->failure(fn () => app(SquareClient::class)->listCustomers('cursor-fixture', 25));

        $this->assertSame(422, $failure->getStatusCode());
        $this->assertSame('Square rejected the request (INVALID_REQUEST_ERROR: INVALID_CURSOR).', $failure->getMessage());
        $this->assertSame('INVALID_REQUEST_ERROR', $failure->squareCategory);
        $this->assertSame('INVALID_CURSOR', $failure->squareCode);
        Http::assertSentCount(1);
    }

    public function test_square_error_codes_that_are_not_codes_are_not_repeated(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response(['errors' => [['category' => 'Bearer '.self::SQUARE_TOKEN, 'code' => '<b>not a code</b>']]], 400)]);

        $failure = $this->failure(fn () => app(SquareClient::class)->listLocations());

        $this->assertSame('Square rejected the request (UNKNOWN: UNKNOWN).', $failure->getMessage());
    }

    public function test_a_refused_credential_and_a_missing_record_map_to_safe_errors(): void
    {
        Http::fake([
            self::SQUARE_BASE.'/v2/locations' => Http::response(['errors' => [['category' => 'AUTHENTICATION_ERROR', 'code' => 'UNAUTHORIZED']]], 401),
            self::SQUARE_BASE.'/v2/payments/*' => Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], 404),
        ]);

        $refused = $this->failure(fn () => app(SquareClient::class)->listLocations());
        $this->assertSame(502, $refused->getStatusCode());
        $this->assertSame('Square refused the configured connection (AUTHENTICATION_ERROR: UNAUTHORIZED). An operator must check it.', $refused->getMessage());

        $missing = $this->failure(fn () => app(SquareClient::class)->getPayment('pay_fixture_missing'));
        $this->assertSame(404, $missing->getStatusCode());
        Http::assertSentCount(2);
    }

    public function test_a_redirect_is_never_followed(): void
    {
        Http::fake([
            self::SQUARE_BASE.'/*' => Http::response('', 302, ['Location' => 'https://collector.example.test/steal']),
            'collector.example.test/*' => Http::response(['locations' => []]),
        ]);

        $failure = $this->failure(fn () => app(SquareClient::class)->listLocations());

        $this->assertSame(502, $failure->getStatusCode());
        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'collector.example.test'));
    }

    public function test_a_connection_failure_is_retried_then_reported_without_the_transport_message(): void
    {
        Http::fake(static fn () => throw new ConnectionException('cURL error 28 for '.self::SQUARE_BASE.'/v2/locations with Authorization: Bearer '.self::SQUARE_TOKEN));

        $failure = $this->failure(fn () => app(SquareClient::class)->listLocations());

        $this->assertSame(503, $failure->getStatusCode());
        $this->assertNull($failure->getPrevious());
        $this->assertStringNotContainsString(self::SQUARE_TOKEN, (string) $failure);
        $this->assertStringNotContainsString('connect.squareupsandbox.com', (string) $failure);
        Sleep::assertSleptTimes(2);
    }

    public function test_a_success_that_is_not_json_is_unreadable(): void
    {
        Http::fake([self::SQUARE_BASE.'/*' => Http::response('<html>maintenance</html>', 200)]);

        $this->assertSame(502, $this->failure(fn () => app(SquareClient::class)->listLocations())->getStatusCode());
    }

    public function test_nothing_is_sent_while_square_is_not_configured(): void
    {
        Http::fake();
        foreach (['access_token' => null, 'workspace' => null, 'environment' => 'staging', 'version' => 'latest'] as $key => $value) {
            $this->connectSquare(null, ['workspace' => 'synthetic-workspace', $key => $value]);
            $failure = $this->failure(fn () => app(SquareClient::class)->listLocations());
            $this->assertSame(404, $failure->getStatusCode(), $key);
        }

        Http::assertNothingSent();
    }

    public function test_ids_and_cursors_that_are_not_squares_are_refused_before_sending(): void
    {
        Http::fake();
        $client = app(SquareClient::class);
        foreach ([
            fn () => $client->getInvoice('../locations'),
            fn () => $client->getInvoice("inv:0-fixture1\n"),
            fn () => $client->retrieveOrder(''),
            fn () => $client->listInvoices('LOC?x=1', null, 25),
            fn () => $client->listCustomers('a cursor with spaces', 25),
            fn () => $client->searchCustomers(['given_name' => ['fuzzy' => 'Avery']], null, 25),
            fn () => $client->searchCustomers([], null, 25),
        ] as $call) {
            try {
                $call();
                $this->fail('A malformed read was sent.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_configuration_never_prints_the_credential(): void
    {
        $configuration = SquareConfiguration::fromConfig();

        $this->assertStringNotContainsString(self::SQUARE_TOKEN, print_r($configuration, true));
        $this->assertStringNotContainsString(self::SQUARE_TOKEN, (string) json_encode($configuration));
        $this->expectException(LogicException::class);
        serialize($configuration);
    }

    private function failure(callable $call): SquareRequestFailed
    {
        try {
            $call();
        } catch (SquareRequestFailed $failure) {
            $this->assertStringNotContainsString(self::SQUARE_TOKEN, (string) $failure);

            return $failure;
        } catch (Throwable $other) {
            $this->fail('Expected a Square failure, got '.$other::class);
        }
        $this->fail('Expected a Square failure.');
    }
}
