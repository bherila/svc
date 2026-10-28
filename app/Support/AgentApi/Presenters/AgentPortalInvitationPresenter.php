<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientPortalInvitation;

/** Common invitation facts intentionally exclude the bearer acceptance link. */
final class AgentPortalInvitationPresenter
{
    /** @return array<string,mixed> */
    public static function summary(ClientPortalInvitation $invitation, bool $zuluTimestamps = false): array
    {
        return ['id' => $invitation->public_id, 'email' => $invitation->email,
            'scope' => $invitation->scope()->value, 'status' => $invitation->currentStatus()->value,
            'expires_at' => $zuluTimestamps ? $invitation->expires_at?->utc()->toIso8601ZuluString() : $invitation->expires_at?->toIso8601String(),
            'email_sent' => $invitation->email_sent];
    }
}
