<?php

namespace App\Support;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The single boundary for reading the current time in domain code.
 *
 * `now()` and `today()` are the workspace's wall clock, for calendar
 * arithmetic: which day it is there, where a period starts. An event's
 * instant is stored with `instant()`, in UTC. A database driver formats a
 * date in its own zone and drops the offset, and every datetime column is
 * read back as UTC, so a workspace-local value stored as is reads back off by
 * the workspace's offset.
 */
final class WorkspaceClock
{
    /** The current instant, in UTC: the value to store in a datetime column or compare with one. */
    public function instant(): CarbonImmutable
    {
        return Date::getFacadeRoot()->now('UTC')->toImmutable();
    }

    public function now(Workspace|string|null $workspace = null): CarbonImmutable
    {
        $timezone = $workspace instanceof Workspace
            ? $workspace->timezone
            : ($workspace ?? config('app.timezone', 'UTC'));

        return Date::getFacadeRoot()->now($timezone)->toImmutable();
    }

    public function today(Workspace|string|null $workspace = null): CarbonImmutable
    {
        return $this->now($workspace)->startOfDay();
    }
}
