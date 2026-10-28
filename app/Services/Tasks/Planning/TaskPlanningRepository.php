<?php

namespace App\Services\Tasks\Planning;

use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskContactResponsibility;
use App\Models\ClientTaskDependency;
use App\Models\ClientTaskLabel;
use App\Models\ClientTaskLabelAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Tasks\Organization\TaskOrganizationRecords;
use App\Services\Tasks\TaskMutationOrigin;
use App\Support\Concurrency\Locks;
use Closure;
use Illuminate\Validation\ValidationException;

/** Explicit tenant/project/company persistence for planning; no grant or lifecycle writes. */
final class TaskPlanningRepository
{
    public function label(Workspace $workspace, string $id): ClientTaskLabel
    {
        return ClientTaskLabel::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->tap(Locks::forUpdate())->firstOrFail();
    }

    public function saveLabel(Workspace $workspace, ?ClientTaskLabel $label, TaskPlanningCommand $command, Closure $authorizeCurrent): ClientTaskLabel
    {
        $name = $command->name;
        if ($name !== null) {
            $duplicate = ClientTaskLabel::query()->where('workspace_id', $workspace->id)->where('name_key', $name->key)
                ->when($label !== null, fn ($query) => $query->where('id', '!=', $label->id))->tap(Locks::forShare())->first(['id']);
        }
        $authorizeCurrent();
        if (isset($duplicate)) {
            throw ValidationException::withMessages(['name' => 'This workspace already has a label with that name.']);
        }
        if ($label === null) {
            abort_unless($name !== null && $command->operation === 'labels.create', 404);

            return ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => $name->name, 'name_key' => $name->key]);
        }
        abort_unless($label->workspace_id === $workspace->id, 404);
        if ($command->operation === 'labels.update') {
            abort_if($label->is_archived, 409, 'Restore this label before renaming it.');
        }
        $attributes = match ($command->operation) {
            'labels.update' => ['name' => $name?->name, 'name_key' => $name?->key],
            'labels.archive' => ['is_archived' => true],
            'labels.restore' => ['is_archived' => false],
            default => abort(404),
        };
        $label->forceFill($attributes + ['lock_version' => $label->lock_version + 1])->save();

        return $label;
    }

    /** @param list<string> $ids */
    public function replaceLabels(Workspace $workspace, ClientTask $task, array $ids, Closure $authorizeCurrent): void
    {
        abort_unless($task->workspace_id === $workspace->id, 404);
        $labels = ClientTaskLabel::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)
            ->orderBy('id')->tap(Locks::forShare())->get();
        $current = ClientTaskLabelAssignment::query()->where('workspace_id', $workspace->id)->where('client_task_id', $task->id)
            ->orderBy('id')->limit(21)->tap(Locks::forUpdate())->get();
        $authorizeCurrent();
        if ($labels->count() !== count($ids) || $current->count() > 20) {
            throw ValidationException::withMessages(['label_ids' => 'Choose available labels from this workspace.']);
        }
        $existing = $current->pluck('client_task_label_id')->all();
        foreach ($labels as $label) {
            if ($label->is_archived && ! in_array($label->id, $existing, true)) {
                throw ValidationException::withMessages(['label_ids' => 'Choose active labels from this workspace.']);
            }
        }
        $desired = $labels->modelKeys();
        ClientTaskLabelAssignment::query()->where('workspace_id', $workspace->id)->where('client_task_id', $task->id)->whereNotIn('client_task_label_id', $desired)->delete();
        foreach (array_diff($desired, $existing) as $id) {
            ClientTaskLabelAssignment::query()->create(['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'client_task_label_id' => $id]);
        }
    }

    public function dependencyGraph(Workspace $workspace, TaskOrganizationRecords $records, Closure $authorizeCurrent): TaskDependencyGraph
    {
        abort_unless($records->project->workspace_id === $workspace->id, 404);
        $rows = ClientTaskDependency::query()->where('workspace_id', $workspace->id)->where('client_project_id', $records->project->id)
            ->orderBy('id')->limit(TaskDependencyGraph::MAX_EDGES + 1)->tap(Locks::forUpdate())->get();
        $authorizeCurrent();
        $ids = $records->tasks->pluck('public_id', 'id')->all();
        $edges = [];
        foreach ($rows as $row) {
            $edges[] = new TaskDependencyEdge($ids[$row->client_task_id] ?? 'unavailable', $ids[$row->prerequisite_task_id] ?? 'unavailable');
        }

        return TaskDependencyGraph::fromFacts(array_values($ids), $edges);
    }

    public function replaceDependencies(Workspace $workspace, TaskOrganizationRecords $records, ClientTask $source, TaskDependencyChange $change): void
    {
        abort_unless($records->project->workspace_id === $workspace->id && $source->workspace_id === $workspace->id
            && $source->client_project_id === $records->project->id, 404);
        $tasks = $records->tasks->keyBy('public_id');
        $removed = array_map(fn (string $id): int => $tasks[$id]->id, $change->removedIds);
        ClientTaskDependency::query()->where('workspace_id', $workspace->id)->where('client_project_id', $records->project->id)
            ->where('client_task_id', $source->id)->whereIn('prerequisite_task_id', $removed)->delete();
        foreach ($change->addedIds as $id) {
            ClientTaskDependency::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $records->project->id,
                'client_task_id' => $source->id, 'prerequisite_task_id' => $tasks[$id]->id]);
        }
    }

    /** @param list<string> $ids */
    public function replaceContacts(Workspace $workspace, ClientProject $project, ClientTask $task, array $ids, Closure $authorizeCurrent): void
    {
        abort_unless($project->workspace_id === $workspace->id && $task->workspace_id === $workspace->id && $task->client_project_id === $project->id, 404);
        $current = ClientTaskContactResponsibility::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)
            ->where('client_company_id', $project->client_company_id)->where('client_task_id', $task->id)
            ->orderBy('id')->limit(11)->tap(Locks::forUpdate())->get();
        $contacts = ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $project->client_company_id)
            ->where(fn ($query) => $query->whereIn('public_id', $ids)->orWhereIn('id', $current->pluck('client_contact_id')))
            ->orderBy('id')->tap(Locks::forShare())->get();
        $authorizeCurrent();
        if ($current->count() > 10) {
            throw ValidationException::withMessages(['contact_ids' => 'The task responsibility state is invalid.']);
        }
        $byId = $contacts->keyBy('id');
        $recorded = [];
        foreach ($current as $row) {
            $recorded[] = $byId->get($row->client_contact_id)->public_id ?? 'unavailable';
        }
        $facts = array_values($contacts->map(fn (ClientContact $contact): TaskContactFact => new TaskContactFact($contact->public_id, (string) $contact->client_company_id, $contact->is_archived))->all());
        $selection = (new TaskContactResponsibilityFacts((string) $project->client_company_id, $recorded, $facts))->replace($ids);
        $desired = $contacts->whereIn('public_id', $selection->ids)->modelKeys();
        ClientTaskContactResponsibility::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)
            ->where('client_company_id', $project->client_company_id)->where('client_task_id', $task->id)->whereNotIn('client_contact_id', $desired)->delete();
        foreach ($contacts->whereIn('public_id', $selection->addedIds) as $contact) {
            ClientTaskContactResponsibility::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
                'client_project_id' => $project->id, 'client_task_id' => $task->id, 'client_contact_id' => $contact->id]);
        }
    }

    public function authorizeManager(Workspace $workspace, User $actor, TaskMutationOrigin $origin): void
    {
        $membership = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->tap(Locks::forShare())->first();
        $portal = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->orderBy('id')->tap(Locks::forShare())->get(['id']);
        abort_unless($membership !== null && in_array($membership->role, ['owner', 'admin'], true), 403);
        abort_if($origin === TaskMutationOrigin::Agent && $portal->isNotEmpty(), 403);
    }
}
