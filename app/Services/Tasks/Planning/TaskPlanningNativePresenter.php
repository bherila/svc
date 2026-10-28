<?php

namespace App\Services\Tasks\Planning;

use App\Http\Requests\TaskPlanningInput;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\TaskReadScope;
use App\Support\AgentApi\Presenters\AgentWorkspacePresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Native DTOs contain finished URLs and scoped first pages, never browser-built paths. */
final class TaskPlanningNativePresenter
{
    public function __construct(private readonly TaskPlanningDetailsReadService $reads,
        private readonly TaskReadScope $scope, private readonly TaskAccess $access, private readonly AgentAccess $workspaceAccess) {}

    /** Off means no extra reads. Ordinary task body/version props remain separate.
     * @return ?array<string,mixed> */
    public function present(User $actor, Workspace $workspace, string $taskId): ?array
    {
        if (config('task_planning_details.enabled') !== true) {
            return null;
        }

        return DB::transaction(function () use ($actor, $workspace, $taskId): array {
            $facts = $this->reads->get($actor, $workspace, TaskMutationOrigin::Internal, $taskId);
            $task = $this->scope->query($actor, $workspace, TaskMutationOrigin::Internal)->where('client_tasks.public_id', $taskId)->with('project')->firstOrFail();
            $manage = $this->access->canManage($actor, $workspace, $task->project);
            $manager = $this->workspaceAccess->isWorkspaceManager($actor, $workspace);
            $labelOptions = $manage ? $this->reads->labelOptions($actor, $workspace, TaskMutationOrigin::Internal, $task->project->public_id) : null;
            $labelOptionsHref = $manage ? $this->href('labels.options', $workspace, 'project', $task->project->public_id) : null;
            $result = ['task_id' => $facts->taskId, 'version' => $facts->version, 'dependency_summary' => $facts->dependencySummary,
                'labels' => ['selected' => $facts->labels, 'options' => $labelOptions === null ? null : $this->page($labelOptions, $labelOptionsHref),
                    'options_href' => $labelOptionsHref, 'replace_href' => $manage ? $this->href('labels.replace', $workspace, 'task', $taskId) : null],
                'dependencies' => $this->dependencies($actor, $workspace, $taskId, $manage),
                'label_catalog_href' => $manager ? $this->href('labels.page', $workspace) : null];
            if ($manager) {
                $contactData = $this->reads->contacts($actor, $workspace, TaskMutationOrigin::Internal, $taskId);
                $contacts = $this->object($contactData['data']);
                $contactHref = $this->href('contacts.options', $workspace, 'task', $taskId);
                $result['contacts'] = ['selected' => $contacts['contacts'], 'version' => $contacts['version'],
                    'options' => $this->page($this->reads->contactOptions($actor, $workspace, TaskMutationOrigin::Internal, $taskId), $contactHref),
                    'options_href' => $contactHref, 'replace_href' => $this->href('contacts.replace', $workspace, 'task', $taskId)];
            }

            return $result;
        });
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed> */
    public function catalog(User $actor, Workspace $workspace, array $query = []): array
    {
        TaskPlanningDetailsGate::assertEnabled();
        $input = TaskPlanningInput::query('labels.list', $query);
        $status = $input['status'] ?? 'active';
        $q = $input['q'] ?? '';
        $limit = $input['limit'] ?? 25;
        $response = $this->reads->labels($actor, $workspace, TaskMutationOrigin::Internal, $status, $q, $limit, $input['cursor'] ?? null);
        $rows = [];
        foreach ($this->rows($response['data']) as $label) {
            $id = $this->identity($label);
            $rows[] = $label + ['update_href' => $label['is_archived'] ? null : $this->href('labels.update', $workspace, 'label', $id),
                'archive_href' => $label['is_archived'] ? null : $this->href('labels.archive', $workspace, 'label', $id),
                'restore_href' => $label['is_archived'] ? $this->href('labels.restore', $workspace, 'label', $id) : null];
        }
        $meta = $this->object($response['meta']);
        $next = $meta['next_cursor'];
        if ($next !== null && ! is_string($next)) {
            throw new LogicException('The planning cursor must be an opaque string.');
        }

        return ['workspace' => AgentWorkspacePresenter::identity($workspace),
            'catalog' => ['labels' => $rows, 'filters' => ['status' => $status, 'q' => $q],
                'search_href' => $this->href('labels.page', $workspace), 'create_href' => $this->href('labels.create', $workspace),
                'next_href' => $next === null ? null : route('tasks.planning.labels.page', ['workspace' => $workspace, 'status' => $status, 'q' => $q, 'limit' => $limit, 'cursor' => $next]),
                'previous_href' => null]];
    }

    /** @return array<string,mixed> */
    private function dependencies(User $actor, Workspace $workspace, string $taskId, bool $manage): array
    {
        $readHref = $this->href('dependencies.get', $workspace, 'task', $taskId);
        $optionsHref = $manage ? $this->href('dependencies.options', $workspace, 'task', $taskId) : null;
        try {
            $response = $this->reads->dependencies($actor, $workspace, TaskMutationOrigin::Internal, $taskId);
            $data = $this->object($response['data']);
            $meta = $this->object($response['meta']);
            $cursor = $meta['next_cursor'];
            if ($cursor !== null && ! is_string($cursor)) {
                throw new LogicException('The planning cursor must be an opaque string.');
            }

            return ['data' => $data, 'read_href' => $readHref,
                'next_href' => $cursor === null ? null : $readHref.'?'.http_build_query(['cursor' => $cursor]),
                'options' => $manage ? $this->page($this->reads->dependencyOptions($actor, $workspace, TaskMutationOrigin::Internal, $taskId), $optionsHref) : null,
                'options_href' => $optionsHref, 'replace_href' => $manage ? $this->href('dependencies.replace', $workspace, 'task', $taskId) : null,
                'unsupported_notice' => null];
        } catch (ValidationException|HttpExceptionInterface $exception) {
            $message = $exception instanceof ValidationException ? ($exception->errors()['planning'][0] ?? null) : $exception->getMessage();
            $supportedRefusal = $exception instanceof ValidationException || $exception->getStatusCode() === 422;
            if (! $supportedRefusal || ! is_string($message) || (! str_contains($message, 'at most') && ! str_contains($message, 'response budget'))) {
                throw $exception;
            }

            return ['data' => null, 'read_href' => $readHref, 'next_href' => null, 'options' => null, 'options_href' => null,
                'replace_href' => null, 'unsupported_notice' => 'This project exceeds the supported dependency size. Labels and contact responsibility remain available.'];
        }
    }

    /** @param array<string,mixed> $response
     * @return array{data:list<array<string,mixed>>,next_href:?string,previous_href:null} */
    private function page(array $response, ?string $href): array
    {
        $meta = $this->object($response['meta']);
        $cursor = $meta['next_cursor'];
        if ($cursor !== null && (! is_string($cursor) || $href === null)) {
            throw new LogicException('Planning continuation needs an authorized finished URL.');
        }

        return ['data' => $this->rows($response['data']), 'next_href' => $cursor === null ? null : $href.'?'.http_build_query(['cursor' => $cursor]), 'previous_href' => null];
    }

    private function href(string $operation, Workspace $workspace, ?string $targetName = null, ?string $target = null): string
    {
        $parameters = ['workspace' => $workspace];
        if ($targetName !== null) {
            $parameters[$targetName] = $target;
        }

        return route(in_array($operation, TaskPlanningInput::WRITES, true)
            ? 'agent-api.v1.task-planning.'.$operation
            : 'tasks.planning.'.$operation, $parameters);
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('Planning readers must return structured data.');
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('Planning pages must return ordered rows.');
        }
        $rows = [];
        foreach ($value as $row) {
            $rows[] = $this->object($row);
        }

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function identity(array $row): string
    {
        $id = $row['id'] ?? null;
        if (! is_string($id)) {
            throw new LogicException('Planning rows must expose a public identity.');
        }

        return $id;
    }
}
