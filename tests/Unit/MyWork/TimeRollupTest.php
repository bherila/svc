<?php

namespace Tests\Unit\MyWork;

use App\Support\MyWork\TimeRollup;
use PHPUnit\Framework\TestCase;

/** "My time". 2026-11-06 is a Friday: the week is Nov 2 to 8, the month Nov 1 to 30. */
final class TimeRollupTest extends TestCase
{
    public function test_the_spans_are_this_weeks_and_this_months_calendar_bounds(): void
    {
        $summary = (new TimeRollup('2026-11-06'))->summarize([]);

        $this->assertSame(['start' => '2026-11-02', 'end' => '2026-11-08', 'total_minutes' => 0, 'projects' => []], $summary['week']);
        $this->assertSame(['start' => '2026-11-01', 'end' => '2026-11-30', 'total_minutes' => 0, 'projects' => []], $summary['month']);
    }

    public function test_the_read_reaches_back_to_whichever_span_starts_first(): void
    {
        $this->assertSame('2026-11-01', (new TimeRollup('2026-11-06'))->earliest());
        // The week of Thursday 2026-12-03 starts on Monday 11-30, before the month does.
        $this->assertSame('2026-11-30', (new TimeRollup('2026-12-03'))->earliest());
        $this->assertSame('2026-12-01', (new TimeRollup('2026-12-03'))->summarize([])['month']['start']);
        // Sunday 2026-11-01: the week began Monday 2026-10-26.
        $this->assertSame('2026-10-26', (new TimeRollup('2026-11-01'))->earliest());
    }

    public function test_entries_on_the_edge_days_of_a_span_count_and_those_just_outside_do_not(): void
    {
        $summary = (new TimeRollup('2026-11-06'))->summarize([
            $this->entry('p', 'Alpha', '2026-11-01', 10),
            $this->entry('p', 'Alpha', '2026-11-02', 20),
            $this->entry('p', 'Alpha', '2026-11-08', 40),
            $this->entry('p', 'Alpha', '2026-11-09', 80),
            $this->entry('p', 'Alpha', '2026-11-30', 160),
            $this->entry('p', 'Alpha', '2026-12-01', 320),
            $this->entry('p', 'Alpha', '2026-10-31', 640),
        ]);

        $this->assertSame(60, $summary['week']['total_minutes']);
        $this->assertSame(10 + 20 + 40 + 80 + 160, $summary['month']['total_minutes']);
    }

    public function test_a_projects_minutes_and_states_are_summed_in_review_order(): void
    {
        $summary = (new TimeRollup('2026-11-06'))->summarize([
            $this->entry('p', 'Alpha', '2026-11-03', 60, 'approved'),
            $this->entry('p', 'Alpha', '2026-11-04', 30, 'draft'),
            $this->entry('p', 'Alpha', '2026-11-05', 15, 'approved'),
            $this->entry('p', 'Alpha', '2026-11-05', 5, 'something_new'),
            $this->entry('p', 'Alpha', '2026-11-05', 7, 'invoiced'),
        ]);

        $project = $summary['week']['projects'][0];
        $this->assertSame('p', $project['project_id']);
        $this->assertSame('Alpha', $project['project']);
        $this->assertSame(117, $project['minutes']);
        $this->assertSame([
            ['status' => 'draft', 'minutes' => 30],
            ['status' => 'approved', 'minutes' => 75],
            ['status' => 'invoiced', 'minutes' => 7],
            ['status' => 'something_new', 'minutes' => 5],
        ], $project['states']);
    }

    public function test_every_flow_state_keeps_its_place(): void
    {
        $entries = [];
        foreach (array_reverse(TimeRollup::STATE_ORDER) as $status) {
            $entries[] = $this->entry('p', 'Alpha', '2026-11-03', 1, $status);
        }

        $states = (new TimeRollup('2026-11-06'))->summarize($entries)['week']['projects'][0]['states'];

        $this->assertSame(TimeRollup::STATE_ORDER, array_column($states, 'status'));
    }

    public function test_projects_are_ordered_by_minutes_then_name(): void
    {
        $summary = (new TimeRollup('2026-11-06'))->summarize([
            $this->entry('b', 'Bravo', '2026-11-03', 30),
            $this->entry('a', 'Alpha', '2026-11-03', 30),
            $this->entry('c', 'Charlie', '2026-11-03', 90),
            $this->entry('d', 'Delta', '2026-11-03', 5),
        ]);

        $this->assertSame(['Charlie', 'Alpha', 'Bravo', 'Delta'], array_column($summary['week']['projects'], 'project'));
    }

    /** @return array{project_id: string, project: string, worked_on: string, minutes: int, status: string} */
    private function entry(string $id, string $name, string $day, int $minutes, string $status = 'draft'): array
    {
        return ['project_id' => $id, 'project' => $name, 'worked_on' => $day, 'minutes' => $minutes, 'status' => $status];
    }
}
