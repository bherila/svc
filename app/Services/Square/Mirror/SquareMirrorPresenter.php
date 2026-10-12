<?php

namespace App\Services\Square\Mirror;

use App\Models\SquareSyncRun;
use DateTimeInterface;

/**
 * Mirror rows as the API and the operator screen show them (#438). Every
 * field is one the sync stored from SquarePresenter's allow-list.
 */
final class SquareMirrorPresenter
{
    /** @return array<string, mixed> */
    public function run(SquareSyncRun $run): array
    {
        return [
            'id' => $run->public_id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'square_version' => $run->api_version,
            'requested_at' => self::instant($run->created_at),
            'started_at' => self::instant($run->started_at),
            'finished_at' => self::instant($run->finished_at),
            'locations_seen' => $run->locations_seen,
            'customers_seen' => $run->customers_seen,
            'customers_changed' => $run->customers_changed,
            'invoices_seen' => $run->invoices_seen,
            'invoices_changed' => $run->invoices_changed,
            'payments_resolved' => $run->payments_resolved,
            'high_water_mark' => self::instant($run->high_water_mark),
            'truncated' => $run->truncated,
            'error_code' => $run->error_code,
            'error' => self::error($run->error_code),
        ];
    }

    /** Why a run failed, for a person; null when it did not. */
    public static function error(?string $code): ?string
    {
        return match ($code) {
            null => null,
            'NOT_CONNECTED' => 'Square is not connected to this workspace.',
            'SERVICE_UNAVAILABLE' => 'Square was unavailable. The next sync will try again.',
            'RATE_LIMITED' => 'Square limited svc\'s requests. The next sync will try again.',
            'UNREADABLE' => 'Square returned a response svc cannot read.',
            'ABANDONED' => 'The sync stopped before it finished.',
            'INTERNAL' => 'The sync failed inside svc.',
            default => "Square refused a request ({$code}). An operator may need to check the connection.",
        };
    }

    public static function instant(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:s\Z');
    }
}
