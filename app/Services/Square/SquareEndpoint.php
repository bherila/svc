<?php

namespace App\Services\Square;

/**
 * Every Square endpoint svc may call: the read-only allow-list (#438).
 *
 * The credential is a personal access token that cannot be scope-limited, so
 * read-only is enforced here rather than by Square. {@see SquareClient} can
 * only send a request built from one of these cases, and a test enumerates
 * them: each is a GET, or a POST to a `/search` read endpoint. Adding a case is
 * the only way to widen what svc can do in Square, and must stay a read.
 */
enum SquareEndpoint: string
{
    case ListLocations = 'locations.list';
    case ListCustomers = 'customers.list';
    case SearchCustomers = 'customers.search';
    case RetrieveCustomer = 'customers.get';
    case ListInvoices = 'invoices.list';
    case SearchInvoices = 'invoices.search';
    case GetInvoice = 'invoices.get';
    case RetrieveOrder = 'orders.get';
    case ListPayments = 'payments.list';
    case GetPayment = 'payments.get';

    public function method(): string
    {
        return match ($this) {
            self::SearchCustomers, self::SearchInvoices => 'POST',
            self::ListLocations, self::ListCustomers, self::RetrieveCustomer, self::ListInvoices,
            self::GetInvoice, self::RetrieveOrder, self::ListPayments, self::GetPayment => 'GET',
        };
    }

    /** The path, with `{id}` standing for the one Square id a retrieve takes. */
    public function path(): string
    {
        return match ($this) {
            self::ListLocations => '/v2/locations',
            self::ListCustomers => '/v2/customers',
            self::SearchCustomers => '/v2/customers/search',
            self::RetrieveCustomer => '/v2/customers/{id}',
            self::ListInvoices => '/v2/invoices',
            self::SearchInvoices => '/v2/invoices/search',
            self::GetInvoice => '/v2/invoices/{id}',
            self::RetrieveOrder => '/v2/orders/{id}',
            self::ListPayments => '/v2/payments',
            self::GetPayment => '/v2/payments/{id}',
        };
    }

    public function takesId(): bool
    {
        return str_contains($this->path(), '{id}');
    }
}
