<?php

namespace App\Support\Finance;

/**
 * What recording one payout report did to the workspace's row for it.
 *
 * - `Recorded`: the row was created, or something a reader sees changed.
 * - `Unchanged`: the report is current - as new as the stored one, or newer -
 *   but reports nothing different; only its report time may have moved.
 * - `Stale`: an older report than the one stored. It changed nothing, and
 *   nothing it says - the payout's arrival date on its payments included - may
 *   be applied anywhere.
 */
enum PayoutRecordOutcome
{
    case Recorded;
    case Unchanged;
    case Stale;

    /** Whether a reader of the payout sees a change. */
    public function changed(): bool
    {
        return match ($this) {
            self::Recorded => true,
            self::Unchanged, self::Stale => false,
        };
    }

    /** Whether the report is current, so what it says about the payout's payments may be applied. */
    public function current(): bool
    {
        return match ($this) {
            self::Recorded, self::Unchanged => true,
            self::Stale => false,
        };
    }
}
