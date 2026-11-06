<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/** Whether a document is the invoice itself or a preview of one not yet issued. */
enum DocumentMode: string
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';

    /** The stamp printed at the head of a proforma, and nothing on an invoice. */
    public function stamp(): ?string
    {
        return match ($this) {
            self::Invoice => null,
            self::Proforma => 'PROFORMA · NOT AN INVOICE',
        };
    }

    /** The document's own title. */
    public function heading(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Proforma => 'Proforma invoice',
        };
    }
}
