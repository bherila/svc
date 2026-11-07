<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/**
 * How much of Appendix B (receipts) a caller asks the document to carry.
 *
 * A request, not a grant: whatever the caller asks, the audience policy in
 * {@see InvoiceReceipts} still decides which receipts a reader may be told
 * about. The default everywhere is {@see self::None}, so a caller that was
 * never taught about receipts (a proforma, a stored copy, an email) prints
 * none rather than inheriting them.
 */
enum ReceiptDetail
{
    /** No Appendix B. The viewer may not read receipts, or the caller does not draw them. */
    case None;

    /** Metadata and finished links, for the screen. No file is opened. */
    case Listed;

    /** Metadata, and the image receipts read through the storage adapter, for the PDF. */
    case Embedded;

    public function readsFiles(): bool
    {
        return match ($this) {
            self::None, self::Listed => false,
            self::Embedded => true,
        };
    }

    public function drawsAppendix(): bool
    {
        return match ($this) {
            self::None => false,
            self::Listed, self::Embedded => true,
        };
    }
}
