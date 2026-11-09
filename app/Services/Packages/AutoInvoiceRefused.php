<?php

namespace App\Services\Packages;

use App\Support\Packages\AutoInvoiceResult;
use Exception;

/**
 * Carries a refused period out of {@see PackageRecurringInvoices}'s
 * transaction, so everything it wrote rolls back before the refusal is
 * recorded. Never leaves that class.
 */
final class AutoInvoiceRefused extends Exception
{
    public function __construct(public readonly AutoInvoiceResult $result)
    {
        parent::__construct($result->detail ?? (string) $result->refusal?->message());
    }
}
