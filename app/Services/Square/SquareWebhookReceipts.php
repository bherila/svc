<?php

namespace App\Services\Square;

use App\Models\Workspace;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable event claims hold no database transaction across the adapter reads. */
final class SquareWebhookReceipts
{
    public function __construct(private readonly WorkspaceClock $clock) {}

    /** null means completed; false means another request is still processing. */
    public function claim(Workspace $workspace, string $eventId, string $type): string|false|null
    {
        $hash = hash('sha256', $eventId);
        $now = $this->clock->now()->utc();
        DB::table('square_webhook_receipts')->insertOrIgnore(['workspace_id' => $workspace->id, 'event_hash' => $hash, 'event_type' => $type, 'received_at' => $now]);
        $query = DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->where('event_hash', $hash);
        if ((clone $query)->whereNotNull('completed_at')->exists()) {
            return null;
        }
        $token = (string) Str::uuid();
        $claimed = (clone $query)->whereNull('completed_at')
            ->where(fn ($lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now))
            ->update(['claim_token' => $token, 'lease_expires_at' => $now->addMinutes(5)]);

        return $claimed === 1 ? $token : false;
    }

    public function finish(Workspace $workspace, string $eventId, string $claim, bool $succeeded): void
    {
        DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->where('event_hash', hash('sha256', $eventId))->where('claim_token', $claim)
            ->update(['claim_token' => null, 'lease_expires_at' => null, 'completed_at' => $succeeded ? $this->clock->now()->utc() : null]);
    }

    /** Thirty days exceeds the delivery retry window; an active lease is retained. */
    public function prune(Workspace $workspace): int
    {
        $now = $this->clock->now()->utc();
        $expired = DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->where('received_at', '<', $now->subDays(30))
            ->where(fn ($lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now));
        $ids = (clone $expired)->orderBy('id')->limit(1000)->pluck('id')->all();

        // Recheck lease expiry in the delete: a request may reclaim after selection.
        return $ids === [] ? 0 : $expired->whereIn('id', $ids)->delete();
    }
}
