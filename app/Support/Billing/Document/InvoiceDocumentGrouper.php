<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/**
 * Lines into sections: the order of {@see DocumentSection::cases()}, empty
 * sections left out, and each section's lines in the order they were given
 * (the invoice's own sort order). Pure and database-free.
 */
final class InvoiceDocumentGrouper
{
    /**
     * @param  list<InvoiceDocumentLine>  $lines
     * @return list<InvoiceDocumentSectionBlock>
     */
    public static function group(array $lines): array
    {
        $bySection = [];
        foreach ($lines as $line) {
            $bySection[DocumentSection::forStored($line->type)->value][] = $line;
        }

        $blocks = [];
        foreach (DocumentSection::cases() as $section) {
            if (isset($bySection[$section->value])) {
                $blocks[] = new InvoiceDocumentSectionBlock($section, $bySection[$section->value]);
            }
        }

        return $blocks;
    }
}
