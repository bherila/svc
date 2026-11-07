<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/** What the document does with one receipt file. */
enum ReceiptPlacement: string
{
    /** An image drawn under its row in the PDF. */
    case Embedded = 'embedded';

    /** Not an image (a PDF, a spreadsheet): named in the list and opened from the invoice. */
    case ListedOnly = 'listed_only';

    /** An image over the per-file, per-document or pixel budget. */
    case TooLarge = 'too_large';

    /** Stored as an image but not readable, or not the image it says it is. */
    case Unreadable = 'unreadable';

    /** The sentence beside a file that is not drawn, or null where nothing needs saying. */
    public function note(): ?string
    {
        return match ($this) {
            self::Embedded => null,
            self::ListedOnly => 'Not drawn in the PDF; open the file from the invoice online.',
            self::TooLarge => 'Too large to draw in the PDF; open the file from the invoice online.',
            self::Unreadable => 'Could not be drawn in the PDF; open the file from the invoice online.',
        };
    }
}
