<?php

namespace App\Services\Billing\Pdf;

/** A rendered invoice: the PDF bytes and how many pages they hold. */
final readonly class RenderedInvoicePdf
{
    public function __construct(public string $bytes, public int $pageCount) {}
}
