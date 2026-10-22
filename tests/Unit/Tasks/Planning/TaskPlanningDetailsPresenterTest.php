<?php

namespace Tests\Unit\Tasks\Planning;

use App\Services\Tasks\Planning\TaskPlanningDetailsPresenter;
use App\Services\Tasks\Planning\TaskPlanningDetailsSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskPlanningDetailsSnapshot::class)]
#[CoversClass(TaskPlanningDetailsPresenter::class)]
final class TaskPlanningDetailsPresenterTest extends TestCase
{
    public function test_public_projection_retains_complete_value_facts_and_has_no_contact_or_owner_fields(): void
    {
        $labels = [['id' => 'synthetic-label', 'name' => 'Équipe 🚀', 'is_archived' => true, 'version' => 'label-revision']];
        $summary = ['blocked' => true, 'incomplete_prerequisite_count' => 2, 'dependent_count' => 3];
        $snapshot = new TaskPlanningDetailsSnapshot('synthetic-task', 'task-revision', $labels, $summary);
        $presenter = new TaskPlanningDetailsPresenter;
        $this->assertSame(['labels' => $labels, 'dependency_summary' => $summary], $presenter->fields($snapshot));
        $this->assertSame(['data' => ['task_id' => 'synthetic-task', 'version' => 'task-revision', 'labels' => $labels, 'dependency_summary' => $summary]], $presenter->response($snapshot));
        $copy = $presenter->fields($snapshot);
        $copy['labels'][0]['name'] = 'Changed consumer copy';
        $this->assertSame('Équipe 🚀', $snapshot->labels[0]['name']);
        $this->assertSame(['labels', 'dependency_summary'], array_keys($presenter->fields($snapshot)));
    }
}
