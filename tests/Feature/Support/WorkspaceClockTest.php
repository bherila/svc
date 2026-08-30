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

        $workspace = new Workspace;
        $workspace->timezone = 'America/Los_Angeles';

        $now = app(WorkspaceClock::class)->now($workspace);

        $this->assertSame('America/Los_Angeles', $now->timezoneName);
        $this->assertSame('2026-08-29 22:30:00', $now->format('Y-m-d H:i:s'));
    }
}
