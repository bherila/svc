<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Validator;

/** The verified reader floor may change ordinary upload defaults; sharing controls stay unavailable. */
final class AttachmentUploadPolicy
{
    /** The runtime latch is authoritative; cached configuration only checks rollout consistency. */
    public static function defaultVisibility(): bool
    {
        $latched = app(AttachmentPrivacyLatch::class)->enabled();
        abort_unless($latched || ! (bool) config('svc.attachments.private_uploads_enabled', false), 503,
            'Private upload defaults require the durable privacy latch.');

        return ! $latched;
    }

    /** @param array<string, mixed> $payload */
    public static function validateLegacyPayload(array $payload): void
    {
        Validator::make($payload, ['is_visible_to_client' => ['missing']])->validate();
    }
}
