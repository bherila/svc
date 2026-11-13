<?php

namespace Tests\Feature\Support;

use App\Models\Workspace;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class WorkspaceClockTest extends TestCase
{
    public function test_it_reads_now_in_the_workspace_timezone(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-30 05:30:00 UTC'));
        try {
            $workspace = new Workspace;
            $workspace->timezone = 'America/Los_Angeles';

            $now = app(WorkspaceClock::class)->now($workspace);

            $this->assertSame('America/Los_Angeles', $now->timezoneName);
            $this->assertSame('2026-08-29 22:30:00', $now->format('Y-m-d H:i:s'));
            $this->assertSame('2026-08-29 00:00:00', app(WorkspaceClock::class)->today($workspace)->format('Y-m-d H:i:s'));
        } finally {
            Date::setTestNow();
        }
    }

    public function test_an_instant_is_the_same_moment_in_utc_whatever_the_application_zone(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-11-16 01:00:00', 'Pacific/Auckland'));
        config(['app.timezone' => 'Pacific/Auckland']);
        try {
            $instant = app(WorkspaceClock::class)->instant();

            $this->assertSame('UTC', $instant->timezoneName);
            $this->assertSame('2026-11-15 12:00:00', $instant->format('Y-m-d H:i:s'));
        } finally {
            Date::setTestNow();
        }
    }
}
