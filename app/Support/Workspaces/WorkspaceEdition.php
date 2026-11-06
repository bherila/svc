<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

/**
 * How much of SVC a workspace has turned on (docs/ux-overhaul.md section 7e).
 *
 * `full` is every workspace that exists today. `light` is a partner's minimum:
 * time on shared projects, submission for review and the approved timesheet,
 * with agreements, invoicing and revenue off until they choose the full
 * workspace. It shapes what the navigation offers; it is not a permission, and
 * every endpoint still authorizes as it did.
 *
 * A stored value this code does not know reads as light: showing less is the
 * failure that costs nothing.
 */
enum WorkspaceEdition: string
{
    case Full = 'full';
    case Light = 'light';

    public static function of(mixed $stored): self
    {
        if ($stored === null) {
            return self::Full;
        }

        return self::tryFrom(is_string($stored) ? $stored : '') ?? self::Light;
    }

    /** Does the workspace offer agreements, invoices, revenue and finance? */
    public function offersBilling(): bool
    {
        return match ($this) {
            self::Full => true,
            self::Light => false,
        };
    }

    /** Is this the partner's minimum? */
    public function isLight(): bool
    {
        return match ($this) {
            self::Full => false,
            self::Light => true,
        };
    }
}
