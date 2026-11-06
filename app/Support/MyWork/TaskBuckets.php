<?php

declare(strict_types=1);

namespace App\Support\MyWork;

use Carbon\CarbonImmutable;

/**
 * "This week" on the My work page (docs/ux-overhaul.md section 5): the tasks
 * assigned to a person, grouped by when they fall due. Pure and database-free.
 *
 * The groups, in the order they are read:
 *
 * - `overdue`: due before today.
 * - `today`: due today.
 * - `this_week`: due after today and on or before the Sunday that ends the
 *   week (Monday to Sunday) that holds today.
 * - `later`: due after that Sunday.
 * - `no_date`: no due date.
 *
 * Within a group the input order is kept, so the caller decides the tiebreak.
 * A group with no task is left out rather than printed empty.
 */
final readonly class TaskBuckets
{
    /** @var array<string, string> key => label, in reading order */
    public const array LABELS = [
        'overdue' => 'Overdue',
        'today' => 'Due today',
        'this_week' => 'Later this week',
        'later' => 'Later',
        'no_date' => 'No due date',
    ];

    public function __construct(private string $today) {}

    /**
     * @template T of array{due_on: string|null}
     *
     * @param  list<T>  $tasks
     * @return list<array{key: string, label: string, tasks: list<T>}>
     */
    public function group(array $tasks): array
    {
        $weekEnd = CarbonImmutable::parse($this->today)->endOfWeek()->toDateString();
        $groups = array_fill_keys(array_keys(self::LABELS), []);

        foreach ($tasks as $task) {
            $due = $task['due_on'];
            $key = match (true) {
                $due === null => 'no_date',
                $due < $this->today => 'overdue',
                $due === $this->today => 'today',
                $due <= $weekEnd => 'this_week',
                default => 'later',
            };
            $groups[$key][] = $task;
        }

        $out = [];
        foreach (self::LABELS as $key => $label) {
            if ($groups[$key] !== []) {
                $out[] = ['key' => $key, 'label' => $label, 'tasks' => $groups[$key]];
            }
        }

        return $out;
    }
}
