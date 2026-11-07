<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/**
 * One receipt file on an expense.
 *
 * `href` is a finished address the server chose for this viewer, or null where
 * the viewer may not open the file. `imageDataUri` and the drawn size exist only
 * for a placement of {@see ReceiptPlacement::Embedded} and never reach the
 * screen's JSON.
 */
final readonly class InvoiceReceiptFile
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $mediaType,
        public int $bytes,
        public ?string $href,
        public ReceiptPlacement $placement,
        public ?string $imageDataUri = null,
        public int $drawnWidth = 0,
        public int $drawnHeight = 0,
    ) {}

    /** @return array{id: string, filename: string, media_type: string, bytes: int, href: string|null, note: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'media_type' => $this->mediaType,
            'bytes' => $this->bytes,
            'href' => $this->href,
            'note' => $this->placement->note(),
        ];
    }
}
