<?php

namespace App\Support;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The single boundary for reading the current time in domain code.
 */
final class WorkspaceClock
{
    public function now(Workspace|string|null $workspace = null): CarbonImmutable
    {
        $timezone = $workspace instanceof Workspace
            ? $workspace->timezone
            : ($workspace ?? config('app.timezone', 'UTC'));

        return Date::getFacadeRoot()->now($timezone)->toImmutable();
    }
}
