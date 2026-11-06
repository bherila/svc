<?php

declare(strict_types=1);

namespace App\Support\MyWork;

use Carbon\CarbonImmutable;

/**
 * "My time" on the My work page (docs/ux-overhaul.md section 5): what a person
 * has logged this week and this month, by project, with where each entry is in
 * review. Pure and database-free, and deliberately handed nothing that prices
 * work: entries arrive as a project, a day, whole minutes and a state, so no
 * rate or amount can pass through it.
 *
 * - **This week** is Monday to Sunday around today.
 * - **This month** is the calendar month holding today.
 * - Both end at the end of their span, not at today: an entry dated later this
 *   week counts in it.
 * - A project's rows are ordered by minutes, largest first, then by name; its
 *   states are ordered as the review flow runs.
 */
final readonly class TimeRollup
{
    /** @var list<string> the review flow, then anything else in the order met */
    public const array STATE_ORDER = ['draft', 'submitted', 'reviewed', 'approved', 'disputed', 'invoiced'];

    public function __construct(private string $today) {}

    /** The first day either span reaches back to, so one read can serve both. */
    public function earliest(): string
    {
        $today = CarbonImmutable::parse($this->today);

        return min($today->startOfWeek()->toDateString(), $today->startOfMonth()->toDateString());
    }

    /**
     * @param  list<array{project_id: string, project: string, worked_on: string, minutes: int, status: string}>  $entries
     * @return array{week: array<string, mixed>, month: array<string, mixed>}
     */
    public function summarize(array $entries): array
    {
        $today = CarbonImmutable::parse($this->today);

        return [
            'week' => $this->span($entries, $today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()),
            'month' => $this->span($entries, $today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()),
        ];
    }

    /**
     * @param  list<array{project_id: string, project: string, worked_on: string, minutes: int, status: string}>  $entries
     * @return array{start: string, end: string, total_minutes: int, projects: list<array{project_id: string, project: string, minutes: int, states: list<array{status: string, minutes: int}>}>}
     */
    private function span(array $entries, string $start, string $end): array
    {
        $projects = [];
        $total = 0;

        foreach ($entries as $entry) {
            if ($entry['worked_on'] < $start || $entry['worked_on'] > $end) {
                continue;
            }
            $id = $entry['project_id'];
            $projects[$id] ??= ['project_id' => $id, 'project' => $entry['project'], 'minutes' => 0, 'states' => []];
            $projects[$id]['minutes'] += $entry['minutes'];
            $projects[$id]['states'][$entry['status']] = ($projects[$id]['states'][$entry['status']] ?? 0) + $entry['minutes'];
            $total += $entry['minutes'];
        }

        $rows = array_values(array_map(function (array $project): array {
            $states = [];
            foreach ($project['states'] as $status => $minutes) {
                $states[] = ['status' => (string) $status, 'minutes' => $minutes];
            }
            usort($states, fn (array $a, array $b): int => $this->rank($a['status']) <=> $this->rank($b['status']));

            return [...$project, 'states' => $states];
        }, $projects));
        usort($rows, static fn (array $a, array $b): int => [$b['minutes'], $a['project']] <=> [$a['minutes'], $b['project']]);

        return ['start' => $start, 'end' => $end, 'total_minutes' => $total, 'projects' => $rows];
    }

    /** A state's place in the review flow; an unknown one sorts after every known one. */
    private function rank(string $status): int
    {
        $index = array_search($status, self::STATE_ORDER, true);

        return $index === false ? count(self::STATE_ORDER) : $index;
    }
}
