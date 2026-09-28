<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\LinkedTimeSpan;
use Tests\TestCase;

final class LinkedTimeSpanTest extends TestCase
{
    public function test_it_totals_the_time_a_line_links_and_the_days_it_spans(): void
    {
        $span = LinkedTimeSpan::of([
            ['minutes' => 45, 'worked_on' => '2026-09-15', 'is_deferred' => false],
            ['minutes' => 120, 'worked_on' => '2026-06-02', 'is_deferred' => true],
            ['minutes' => 30, 'worked_on' => '2026-09-20', 'is_deferred' => true],
        ]);

        $this->assertSame(195, $span->minutes);
        $this->assertSame(3, $span->entries);
        $this->assertSame('2026-06-02', $span->firstWorkedOn);
        $this->assertSame('2026-09-20', $span->lastWorkedOn);
        $this->assertSame(150, $span->deferredMinutes);
        $this->assertSame('linked 195 min in 3 entries worked 2026-06-02..2026-09-20 (150 min deferred)', $span->describe());
    }

    public function test_one_entry_and_no_entries_read_naturally(): void
    {
        $this->assertSame(
            'linked 45 min in 1 entry worked 2026-09-15..2026-09-15',
            LinkedTimeSpan::of([['minutes' => 45, 'worked_on' => '2026-09-15', 'is_deferred' => false]])->describe(),
        );
        $this->assertSame('linked 0 min in 0 entries', LinkedTimeSpan::of([])->describe());
    }
}
