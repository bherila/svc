<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Validator;

/** R1 has readers only. No configuration switch can enable incompatible writers. */
final class AttachmentUploadPolicy
{
    /** @param array<string, mixed> $payload */
    public static function validateLegacyPayload(array $payload): void
    {
        Validator::make($payload, ['is_visible_to_client' => ['missing']])->validate();
    }
}
