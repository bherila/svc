<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

use App\Services\Files\AttachmentReader;
use App\Support\Billing\InvoiceLineDetail;

/**
 * The decisions behind Appendix B (receipts): who may be told a receipt exists,
 * which files the PDF draws, and at what size. Pure and database-free, so each
 * decision is unit-tested without a tenant.
 *
 * ## Who may read a receipt
 *
 * The operator audience reads every receipt filed on an expense billed here;
 * the caller has already established that the viewer may read expense receipts
 * (workspace managers), and asks for {@see ReceiptDetail::None} otherwise.
 *
 * The client audience reads a receipt only where the attachment policy lets
 * that same client open it ({@see AttachmentReader}): the file is flagged
 * shared with the client, the expense is billed on an invoice that client can
 * see, and (for a project-scoped contact) on a project they were granted. The
 * builder asks the reader itself, so the document and the download route cannot
 * disagree. A receipt that is not shared is omitted entirely, and so is an
 * expense with none shared: a client is never told that something is withheld.
 *
 * ## What the PDF draws
 *
 * Only JPEG and PNG, only below a per-file size, a per-image pixel count, a
 * per-document image count and a per-document byte total. Everything else is
 * listed by name with a sentence saying so. The limits exist because Dompdf
 * decodes an image into memory (about four bytes a pixel) while laying out the
 * page, and an invoice is rendered in the request.
 */
final class InvoiceReceipts
{
    /** The largest single file the PDF reads: 1.5 MiB. */
    public const MAX_IMAGE_BYTES = 1_572_864;

    /** The most image bytes one document reads: 6 MiB. */
    public const MAX_TOTAL_IMAGE_BYTES = 6_291_456;

    /** The most images one document draws. */
    public const MAX_IMAGES = 24;

    /** The largest decoded image, in pixels: a 6-megapixel photo is about 24 MB in memory. */
    public const MAX_IMAGE_PIXELS = 6_000_000;

    /** The page's content width is 672 CSS pixels; an image is drawn no wider than this. */
    public const MAX_DRAWN_WIDTH = 600;

    /** And no taller, so one receipt never needs more than most of a page. */
    public const MAX_DRAWN_HEIGHT = 520;

    /**
     * Whether a reader of this audience may be told about this receipt, given
     * only its own flag. The reader's per-viewer decision is applied by the
     * query that selected the file; this is the last line before it is listed.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     */
    public static function visibleTo(string $audience, bool $flaggedVisibleToClient): bool
    {
        return match ($audience) {
            InvoiceLineDetail::OPERATOR => true,
            InvoiceLineDetail::CLIENT => $flaggedVisibleToClient,
        };
    }

    /**
     * Whether an expense with no receipt this reader may be told of is still
     * listed. An operator sees "No receipt on file"; a client is shown only the
     * expenses that have a shared receipt, so an unshared one leaves no trace.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     */
    public static function listsExpenseWithoutFiles(string $audience): bool
    {
        return match ($audience) {
            InvoiceLineDetail::OPERATOR => true,
            InvoiceLineDetail::CLIENT => false,
        };
    }

    /**
     * The address a reader opens a receipt at: the operator's own file route,
     * or the portal's receipt route for the client. Both are finished
     * server-chosen addresses; the browser assembles nothing.
     *
     * @param  InvoiceLineDetail::OPERATOR|InvoiceLineDetail::CLIENT  $audience
     */
    public static function hrefFor(string $audience, string $operatorUrl, string $portalUrl): string
    {
        return match ($audience) {
            InvoiceLineDetail::OPERATOR => $operatorUrl,
            InvoiceLineDetail::CLIENT => $portalUrl,
        };
    }

    /** Whether the PDF can draw a file of this stored media type at all. */
    public static function isDrawableType(string $mediaType): bool
    {
        return in_array(strtolower($mediaType), ['image/jpeg', 'image/png'], true);
    }

    /**
     * What the stored metadata alone says about a file, before any is read.
     * {@see ReceiptPlacement::Embedded} here means "worth reading", not "drawn".
     */
    public static function placementFromMetadata(string $mediaType, int $bytes): ReceiptPlacement
    {
        if (! self::isDrawableType($mediaType)) {
            return ReceiptPlacement::ListedOnly;
        }

        return $bytes > self::MAX_IMAGE_BYTES ? ReceiptPlacement::TooLarge : ReceiptPlacement::Embedded;
    }

    /** Whether another image of this size still fits the document's budget. */
    public static function fitsBudget(int $imagesRead, int $bytesRead, int $nextBytes): bool
    {
        return $imagesRead < self::MAX_IMAGES && $bytesRead + $nextBytes <= self::MAX_TOTAL_IMAGE_BYTES;
    }

    /**
     * What an image's own bytes say it is, or null for anything but a JPEG or
     * a PNG of nonzero size. The stored media type is a claim by an uploader;
     * this is what the renderer would actually be handed.
     *
     * @return array{mime: string, width: int, height: int}|null
     */
    public static function inspect(string $contents): ?array
    {
        $info = @getimagesizefromstring($contents);
        if ($info === false) {
            return null;
        }
        $mime = match ($info[2]) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            default => null,
        };

        return $mime === null || $info[0] < 1 || $info[1] < 1 ? null : ['mime' => $mime, 'width' => $info[0], 'height' => $info[1]];
    }

    public static function withinPixelBudget(int $width, int $height): bool
    {
        return $width * $height <= self::MAX_IMAGE_PIXELS;
    }

    /**
     * The size an image is drawn at: scaled down to fit the page, never up.
     *
     * @return array{0: int, 1: int}
     */
    public static function drawnSize(int $width, int $height): array
    {
        $scale = min(1.0, self::MAX_DRAWN_WIDTH / $width, self::MAX_DRAWN_HEIGHT / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /** "B1", "B2": the reference printed beside an expense and its receipts. */
    public static function ref(int $position): string
    {
        return 'B'.$position;
    }
}
