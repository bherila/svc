<?php

namespace App\Services\Files;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Writer availability never changes reader authority or legacy upload defaults. */
final class AttachmentSharingGate
{
    public static function available(): bool
    {
        if (config('svc.attachments.sharing_writes_enabled') !== true) {
            return false;
        }
        try {
            return app(AttachmentPrivacyLatch::class)->enabled();
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 503) {
                throw $exception;
            }

            return false;
        }
    }

    public static function assertEnabled(): void
    {
        abort_unless(config('svc.attachments.sharing_writes_enabled') === true, 404);
        abort_unless(app(AttachmentPrivacyLatch::class)->enabled(), 503, 'Attachment sharing requires the durable privacy latch.');
    }
}
