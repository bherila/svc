<?php

namespace App\Support\Payments\Inventory;

/** How the workspace that would own a processor's connection came to be chosen. */
enum OwnershipStatus: string
{
    /** The operator named it with --workspace. */
    case Explicit = 'explicit';

    /** Taken from SQUARE_WORKSPACE, and nothing else could plausibly own it. */
    case Configured = 'configured';

    /** Several workspaces plausibly depend on the credentials and none was named. */
    case Ambiguous = 'ambiguous';

    /** Nothing names a workspace at all. */
    case NotChosen = 'not_chosen';

    /** Nothing to decide: no credentials in the environment, or already migrated. */
    case NotApplicable = 'not_applicable';

    /** Whether --apply must be refused until the operator names a workspace. */
    public function needsExplicitChoice(): bool
    {
        return match ($this) {
            self::Ambiguous, self::NotChosen => true,
            self::Explicit, self::Configured, self::NotApplicable => false,
        };
    }
}
