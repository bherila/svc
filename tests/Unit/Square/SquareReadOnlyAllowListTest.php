<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareClient;
use App\Services\Square\SquareEndpoint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * svc is read-only against Square (#438). The credential cannot be scoped
 * down, so this is the guard: the allow-list holds exactly these reads, each
 * a GET or a POST to a `/search` read, and the client exposes nothing else.
 * Changing either list is a deliberate, reviewed act.
 */
final class SquareReadOnlyAllowListTest extends TestCase
{
    private const array ALLOWED = [
        'locations.list' => ['GET', '/v2/locations'],
        'customers.list' => ['GET', '/v2/customers'],
        'customers.search' => ['POST', '/v2/customers/search'],
        'customers.get' => ['GET', '/v2/customers/{id}'],
        'invoices.list' => ['GET', '/v2/invoices'],
        'invoices.search' => ['POST', '/v2/invoices/search'],
        'invoices.get' => ['GET', '/v2/invoices/{id}'],
        'orders.get' => ['GET', '/v2/orders/{id}'],
        'payments.list' => ['GET', '/v2/payments'],
        'payments.get' => ['GET', '/v2/payments/{id}'],
        'refunds.get' => ['GET', '/v2/refunds/{id}'],
        'disputes.list' => ['GET', '/v2/disputes'],
        'disputes.get' => ['GET', '/v2/disputes/{id}'],
        // Settlement (S7): the seller's payouts and what each settled.
        'payouts.list' => ['GET', '/v2/payouts'],
        'payouts.get' => ['GET', '/v2/payouts/{id}'],
        'payouts.entries.list' => ['GET', '/v2/payouts/{id}/payout-entries'],
    ];

    /** Path words of Square's write endpoints; none may appear in an allowed path. */
    private const array WRITE_WORDS = [
        'create', 'update', 'delete', 'cancel', 'publish', 'complete', 'refund', 'pay', 'upsert',
        'batch', 'bulk', 'clone', 'attachments', 'disable', 'enable', 'link', 'unlink', 'remove', 'void',
    ];

    public function test_the_allow_list_is_exactly_these_reads(): void
    {
        $actual = [];
        foreach (SquareEndpoint::cases() as $endpoint) {
            $actual[$endpoint->value] = [$endpoint->method(), $endpoint->path()];
        }

        $this->assertSame(self::ALLOWED, $actual);
    }

    public function test_no_allowed_endpoint_is_a_write(): void
    {
        foreach (SquareEndpoint::cases() as $endpoint) {
            $this->assertContains($endpoint->method(), ['GET', 'POST'], $endpoint->value);
            if ($endpoint->method() === 'POST') {
                // Square's read searches are POSTs; nothing else may be.
                $this->assertStringEndsWith('/search', $endpoint->path(), $endpoint->value);
            }
            $this->assertStringStartsWith('/v2/', $endpoint->path(), $endpoint->value);
            foreach (explode('/', trim($endpoint->path(), '/')) as $segment) {
                $this->assertNotContains(strtolower($segment), self::WRITE_WORDS, $endpoint->value);
            }
        }
    }

    /** The client's public surface is the reads, one per allowed endpoint, and nothing generic. */
    public function test_the_client_exposes_only_the_allowed_reads(): void
    {
        $public = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(SquareClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
            ),
        );
        sort($public);

        $this->assertSame([
            'getDispute', 'getInvoice', 'getPayment', 'getPaymentRefund', 'getPayout', 'listCustomers', 'listDisputes', 'listInvoices', 'listLocations', 'listPayments',
            'listPayoutEntries', 'listPayouts', 'retrieveCustomer', 'retrieveOrder', 'searchCustomers', 'searchInvoices',
        ], $public);
        $this->assertCount(count(SquareEndpoint::cases()), $public);
    }
}
