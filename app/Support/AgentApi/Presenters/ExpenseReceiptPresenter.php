<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientAttachment;
use App\Support\AgentApi\AgentApiVersion;

/** Canonical receipt metadata after the adapter authorizes its tenant and parent. */
final class ExpenseReceiptPresenter
{
    /** @return array<string,mixed> */
    public static function for(ClientAttachment $receipt): array
    {
        return ['id' => $receipt->public_id, 'filename' => $receipt->original_filename, 'media_type' => $receipt->media_type,
            'bytes' => $receipt->bytes, 'sha256' => $receipt->sha256, 'uploaded_at' => $receipt->available_at?->toISOString(),
            'version' => AgentApiVersion::for($receipt), 'lifecycle_state' => $receipt->lifecycle_state];
    }
}
