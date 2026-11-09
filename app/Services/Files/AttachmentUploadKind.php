<?php

namespace App\Services\Files;

/**
 * What an upload is for, and so which {@see AttachmentFamily} values it accepts.
 *
 *  - Receipt: an expense's receipt. Strict: a PDF or a JPEG, PNG or WebP image, nothing else.
 *    These are the types a person can read in a browser tab or on a phone, and the types the
 *    invoice PDF can draw (JPEG and PNG; a PDF or WebP receipt is listed by name). HEIC and GIF
 *    are not accepted: nothing in SVC previews either, so a client could not open them.
 *  - General: every other business attachment (companies, projects, tasks, proposals, agreements,
 *    invoices, collaboration). Open: every family except Active, so today's uploads of
 *    documents, spreadsheets, text, archives, images and media keep working.
 */
enum AttachmentUploadKind: string
{
    case Receipt = 'receipt';
    case General = 'general';

    /**
     * For a receipt, the extensions each accepted sniffed type may carry. A receipt is accepted only
     * when its bytes sniff as one of these types AND its name ends in one of that type's extensions.
     *
     * @return array<string, list<string>>
     */
    public function exactTypes(): array
    {
        return match ($this) {
            self::Receipt => [
                'application/pdf' => ['pdf'],
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
            self::General => [],
        };
    }

    /** @return list<AttachmentFamily> The families accepted by name, for a kind with no exact list. */
    public function families(): array
    {
        return match ($this) {
            self::Receipt => [AttachmentFamily::Pdf, AttachmentFamily::Image],
            self::General => [
                AttachmentFamily::Pdf, AttachmentFamily::Image, AttachmentFamily::Office, AttachmentFamily::Text,
                AttachmentFamily::Archive, AttachmentFamily::Media, AttachmentFamily::Other,
            ],
        };
    }
}
