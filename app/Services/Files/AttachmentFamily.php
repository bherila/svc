<?php

namespace App\Services\Files;

/**
 * The families an uploaded file can belong to, judged by what its bytes are (the sniffed media
 * type) and, for the executable families, by what its name claims. This list is the whole upload
 * policy vocabulary: {@see AttachmentUploadKind} says which families a kind of upload accepts, and
 * {@see AttachmentTypePolicy} classifies a file.
 */
enum AttachmentFamily: string
{
    case Pdf = 'pdf';
    case Image = 'image';
    case Office = 'office';
    case Text = 'text';
    case Archive = 'archive';
    case Media = 'media';
    /** Anything not listed and not active: opaque binary, JSON and the like. Stored and downloaded as bytes. */
    case Other = 'other';
    /** What a browser or an operating system would run or render as a page: refused for every upload. */
    case Active = 'active';

    /** A short noun for an error message. */
    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'a PDF',
            self::Image => 'an image',
            self::Office => 'an office document',
            self::Text => 'a text file',
            self::Archive => 'an archive',
            self::Media => 'an audio or video file',
            self::Other => 'a file of another type',
            self::Active => 'a web page, script, SVG image, XML file or program',
        };
    }

    /** Media types that are active content whatever the file is called. */
    private const array ACTIVE_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'application/xslt+xml',
        'text/javascript', 'application/javascript', 'application/x-javascript', 'application/ecmascript',
        'text/x-php', 'application/x-httpd-php', 'application/x-php',
        'text/x-shellscript', 'application/x-sh', 'application/x-csh', 'text/x-perl', 'text/x-python', 'text/x-script.python', 'text/x-ruby',
        'text/x-msdos-batch', 'application/x-bat', 'application/x-msdos-program', 'application/hta',
        'application/x-dosexec', 'application/x-msdownload', 'application/vnd.microsoft.portable-executable', 'application/x-executable',
        'application/x-pie-executable', 'application/x-sharedlib', 'application/x-elf', 'application/x-mach-binary', 'application/x-msi',
        'application/java-archive', 'application/x-java-applet', 'application/x-java-archive', 'application/vnd.android.package-archive',
        'application/x-shockwave-flash', 'application/x-apple-diskimage', 'application/x-ms-shortcut',
    ];

    /** File name extensions that are active content whatever the bytes sniff as (plain text named .js is still a script). */
    private const array ACTIVE_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
        'js', 'mjs', 'cjs', 'jsx', 'jse',
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'pht', 'phar',
        'py', 'pl', 'rb', 'sh', 'bash', 'zsh', 'ksh', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh', 'hta',
        'asp', 'aspx', 'jsp', 'cgi',
        'exe', 'dll', 'com', 'scr', 'msi', 'msp', 'cpl', 'lnk', 'reg', 'jar', 'class', 'apk', 'app', 'dmg', 'deb', 'rpm', 'bin', 'so', 'swf', 'chm',
    ];

    private const array OFFICE_TYPES = [
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/rtf', 'text/rtf',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.presentation',
        'application/vnd.apple.pages', 'application/vnd.apple.numbers', 'application/vnd.apple.keynote',
    ];

    private const array ARCHIVE_TYPES = [
        'application/zip', 'application/gzip', 'application/x-gzip', 'application/x-7z-compressed', 'application/x-tar',
        'application/vnd.rar', 'application/x-rar-compressed', 'application/x-bzip2', 'application/zstd', 'application/x-xz',
    ];

    private const array TEXT_TYPES = ['text/plain', 'text/csv', 'text/tab-separated-values', 'text/markdown', 'text/calendar', 'text/vcard'];

    /** The media type alone: no parameters, no padding, lower case; '' when there is none. */
    public static function baseType(?string $mediaType): string
    {
        return strtolower(trim(explode(';', (string) $mediaType)[0]));
    }

    /** Classify by the sniffed media type, then by the name's last extension (which can only make it Active). */
    public static function classify(?string $mediaType, string $extension): self
    {
        $type = self::baseType($mediaType);
        $extension = strtolower(ltrim($extension, '.'));

        if (in_array($type, self::ACTIVE_TYPES, true) || in_array($extension, self::ACTIVE_EXTENSIONS, true)) {
            return self::Active;
        }

        return match (true) {
            $type === 'application/pdf' => self::Pdf,
            str_starts_with($type, 'image/') => self::Image,
            str_starts_with($type, 'application/vnd.openxml') || str_starts_with($type, 'application/vnd.openxmlformats-officedocument')
                || in_array($type, self::OFFICE_TYPES, true) => self::Office,
            in_array($type, self::TEXT_TYPES, true) => self::Text,
            in_array($type, self::ARCHIVE_TYPES, true) => self::Archive,
            str_starts_with($type, 'audio/') || str_starts_with($type, 'video/') => self::Media,
            default => self::Other,
        };
    }
}
