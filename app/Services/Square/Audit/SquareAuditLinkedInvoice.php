<?php

namespace App\Services\Square\Audit;

/** The svc invoice a Square invoice is actively linked to, by public ids. */
final readonly class SquareAuditLinkedInvoice
{
    public function __construct(
        public string $invoiceId,
        public string $companyId,
    ) {}
}
