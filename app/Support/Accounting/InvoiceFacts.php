<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** What an invoice line entry says about the invoice, read once from a tenant-scoped row. */
final readonly class InvoiceFacts
{
    public function __construct(
        public string $id,
        public string $number,
        public ?string $issueDate,
        public ?string $servicePeriodStart,
        public ?string $servicePeriodEnd,
        public string $currency,
        public string $clientId,
        /** The workspace's IANA timezone, which dates a recording the way the workspace's calendar does. */
        public string $timezone,
    ) {}
}
