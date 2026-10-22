<?php

namespace App\Services\Files;

/** Local persistent state shared by the selected release and its rollback artifact. */
final readonly class AttachmentPrivacyLatch
{
    public const string MARKER = 'attachment-private-default.enabled';

    /** The optional directory is server-owned injection for isolated tests, never request input. */
    public function __construct(private ?string $directory = null) {}

    public function enabled(): bool
    {
        $directory = $this->directory ?? storage_path('app/private');
        clearstatcache(true, $directory);
        abort_unless(is_dir($directory) && is_readable($directory) && is_executable($directory), 503, 'The durable upload policy cannot be verified.');
        $marker = $directory.'/'.self::MARKER;
        clearstatcache(true, $marker);

        // Any retained entry forces privacy; a broken alias must not restore
        // public defaults. Requests and rollback never create or remove it.
        return file_exists($marker) || is_link($marker);
    }
}
