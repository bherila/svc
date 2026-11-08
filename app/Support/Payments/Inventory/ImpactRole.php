<?php

namespace App\Support\Payments\Inventory;

/** What the migration means for one workspace that depends on the environment credentials. */
enum ImpactRole: string
{
    /** Receives the connection; keeps working. */
    case WillOwn = 'will_own';

    /** Stops being served by the environment credentials. */
    case LosesAccess = 'loses_access';

    /** Owner not decided yet; loses access unless it is the one chosen. */
    case Candidate = 'candidate';

    public function label(): string
    {
        return match ($this) {
            self::WillOwn => 'Will own the connection',
            self::LosesAccess => 'Loses access',
            self::Candidate => 'Loses access unless chosen',
        };
    }
}
