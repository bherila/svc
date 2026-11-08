<?php

namespace App\Services\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides whether an uploaded file's type is acceptable, enforced on the server
 * for every path that stores an attachment ({@see AttachmentStorageService}).
 *
 * The decision never trusts the client: the type is what the file's bytes sniff as (the same
 * `getMimeType()` value that is stored as the attachment's media type), and the name's extension is
 * only ever used to refuse more, never to admit. A receipt must sniff as an accepted type and carry
 * an extension that type owns, so a script renamed `receipt.pdf` or a PDF named `receipt.png` is
 * refused. Active content (HTML, SVG, XML, scripts, programs) is refused for every upload; its
 * download is also served as opaque bytes (see AttachmentStorageService::download).
 *
 * Database-free: {@see self::refusal()} is a pure function of three strings.
 */
final class AttachmentTypePolicy
{
    private const string RECEIPT_ACCEPTS = 'A receipt must be a PDF or a JPEG, PNG or WebP image.';

    public static function assertAcceptable(AttachmentUploadKind $kind, UploadedFile $file): void
    {
        $message = self::refusal($kind, $file->getMimeType(), $file->getClientOriginalExtension());

        if ($message !== null) {
            throw ValidationException::withMessages(['file' => [$message]]);
        }
    }

    /** The sentence to show when the file is refused, or null when it is acceptable. */
    public static function refusal(AttachmentUploadKind $kind, ?string $sniffedType, string $extension): ?string
    {
        $extension = strtolower(ltrim($extension, '.'));
        $family = AttachmentFamily::classify($sniffedType, $extension);

        if ($family === AttachmentFamily::Active) {
            return 'Web pages, scripts, SVG images, XML files and programs cannot be uploaded. Attach a PDF or an ordinary image or document instead.';
        }

        // Only a receipt can be refused here: a general upload accepts every family but Active, which is gone by now.
        if (! in_array($family, $kind->families(), true)) {
            return self::RECEIPT_ACCEPTS.' This file is '.$family->label().'.';
        }

        $exact = $kind->exactTypes();
        if ($exact === []) {
            return null;
        }

        $type = strtolower(trim(explode(';', (string) $sniffedType)[0]));
        if (! isset($exact[$type])) {
            return self::RECEIPT_ACCEPTS.' This image format is not supported.';
        }
        if (! in_array($extension, $exact[$type], true)) {
            return sprintf(
                'The file name ends in %s but the file is %s. Rename it to end in .%s, or upload the original file.',
                $extension === '' ? 'nothing' : '.'.$extension,
                self::describe($type),
                $exact[$type][0],
            );
        }

        return null;
    }

    private static function describe(string $type): string
    {
        return match ($type) {
            'application/pdf' => 'a PDF',
            'image/jpeg' => 'a JPEG image',
            'image/png' => 'a PNG image',
            default => 'a WebP image',
        };
    }
}
