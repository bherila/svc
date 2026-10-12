<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A whole synthetic Square account behind Http::fake, for the mirror sync
 * (#438): one location, two customers, and invoice pages a test may change
 * between syncs. Every id, name and amount is invented. Use with FakesSquare.
 */
trait ServesSyntheticSquareAccount
{
    /** @var array{pages: list<list<array<string, mixed>>>, customers: list<array<string, mixed>>, loop: bool}|null */
    private ?array $squareAccount = null;

    /**
     * Two invoices of the recurring series 000001 (one unpaid, one paid) and
     * a one-off without autopay.
     *
     * @return list<array<string, mixed>>
     */
    protected function syntheticInvoices(): array
    {
        $paid = self::squareInvoice('inv:0-fixture2', '000001-R-0002');
        $paid['status'] = 'PAID';
        $paid['updated_at'] = '2026-10-02T15:00:00Z';
        $paid['payment_requests'][0]['total_completed_amount_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $oneOff = self::squareInvoice('inv:0-fixture3', '000002');
        $oneOff['order_id'] = 'order_fixture_3';
        $oneOff['payment_requests'][0]['automatic_payment_source'] = 'NONE';
        unset($oneOff['payment_requests'][0]['card_id']);

        return [self::squareInvoice(), $paid, $oneOff];
    }

    /**
     * Serves the account; calling it again changes what the already
     * registered fake serves, because a later Http::fake() is consulted only
     * after an earlier one.
     *
     * @param  list<array<string, mixed>>|null  $invoices
     * @param  list<list<array<string, mixed>>>|null  $invoicePages
     */
    protected function serveSquareAccount(?array $invoices = null, ?array $invoicePages = null, bool $loopCursor = false): void
    {
        $second = self::squareCustomer('cust_fixture_b');
        $second['company_name'] = null;
        $second['email_address'] = 'blake@example.test';
        $first = $this->squareAccount === null;
        $this->squareAccount = ['pages' => $invoicePages ?? [$invoices ?? $this->syntheticInvoices()], 'customers' => [self::squareCustomer(), $second], 'loop' => $loopCursor];
        if (! $first) {
            return;
        }
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $account = $this->squareAccount ?? ['pages' => [[]], 'customers' => [], 'loop' => false];

            if ($path === '/v2/invoices') {
                $pages = $account['pages'];
                $index = isset($query['cursor']) ? (int) substr((string) $query['cursor'], 7) - 1 : 0;
                $next = $index + 1 < count($pages) ? 'cursor-'.($index + 2) : ($account['loop'] ? 'cursor-'.($index + 1) : null);

                return Http::response(array_filter(['invoices' => $pages[$index], 'cursor' => $next]));
            }

            return match (true) {
                $path === '/v2/locations' => Http::response(['locations' => [self::squareLocation()]]),
                $path === '/v2/customers' => Http::response(['customers' => $account['customers']]),
                str_starts_with($path, '/v2/orders/') => Http::response(['order' => self::squareOrder(rawurldecode(substr($path, strlen('/v2/orders/'))))]),
                default => Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], 404),
            };
        });
    }
}
