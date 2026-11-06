<?php

namespace Tests\Unit\MyWork;

use App\Support\MyWork\TaskBuckets;
use PHPUnit\Framework\TestCase;

/** "This week" grouping. 2026-11-06 is a Friday; its week ends on Sunday 2026-11-08. */
final class TaskBucketsTest extends TestCase
{
    public function test_each_task_lands_in_the_group_its_due_date_names(): void
    {
        $groups = (new TaskBuckets('2026-11-06'))->group([
            $this->task('none', null),
            $this->task('later', '2026-11-09'),
            $this->task('sunday', '2026-11-08'),
            $this->task('saturday', '2026-11-07'),
            $this->task('today', '2026-11-06'),
            $this->task('yesterday', '2026-11-05'),
        ]);

        $this->assertSame(['overdue', 'today', 'this_week', 'later', 'no_date'], array_column($groups, 'key'));
        $this->assertSame(['Overdue', 'Due today', 'Later this week', 'Later', 'No due date'], array_column($groups, 'label'));
        $ids = fn (int $group): array => array_column($groups[$group]['tasks'], 'id');
        $this->assertSame(['yesterday'], $ids(0));
        $this->assertSame(['today'], $ids(1));
        $this->assertSame(['sunday', 'saturday'], $ids(2));
        $this->assertSame(['later'], $ids(3));
        $this->assertSame(['none'], $ids(4));
    }

    public function test_the_week_runs_monday_to_sunday(): void
    {
        // Monday 2026-11-02: the week ends Sunday the 8th, not a Saturday or a day later.
        $groups = (new TaskBuckets('2026-11-02'))->group([$this->task('sun', '2026-11-08'), $this->task('mon', '2026-11-09')]);

        $this->assertSame(['this_week', 'later'], array_column($groups, 'key'));
        $this->assertSame(['sun'], array_column($groups[0]['tasks'], 'id'));
        $this->assertSame(['mon'], array_column($groups[1]['tasks'], 'id'));
    }

    public function test_on_a_sunday_only_that_day_is_left_of_the_week(): void
    {
        $groups = (new TaskBuckets('2026-11-08'))->group([$this->task('mon', '2026-11-09')]);

        $this->assertSame(['later'], array_column($groups, 'key'));
    }

    public function test_the_input_order_is_kept_within_a_group(): void
    {
        $groups = (new TaskBuckets('2026-11-06'))->group([$this->task('b', '2026-11-01'), $this->task('a', '2026-11-02')]);

        $this->assertSame(['b', 'a'], array_column($groups[0]['tasks'], 'id'));
    }

    public function test_an_empty_group_is_left_out_and_no_tasks_is_no_groups(): void
    {
        $this->assertSame([], (new TaskBuckets('2026-11-06'))->group([]));
        $this->assertCount(1, (new TaskBuckets('2026-11-06'))->group([$this->task('a', null)]));
    }

    /** @return array{id: string, due_on: string|null} */
    private function task(string $id, ?string $due): array
    {
        return ['id' => $id, 'due_on' => $due];
    }
}
