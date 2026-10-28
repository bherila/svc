<?php

namespace App\Services\AgentApi\Operations;

/** Explicit transport bindings for internal labels, prerequisites and business responsibility. */
final class TaskPlanningOperations
{
    private const string ROOT = '/workspaces/{workspace}';

    public const array ROUTES = [
        'task_planning.get' => ['task-planning.get', self::ROOT.'/tasks/{task}/planning', [200]],
        'task_planning.labels.list' => ['task-planning.labels.list', self::ROOT.'/task-labels', [200]],
        'task_planning.labels.get' => ['task-planning.labels.get', self::ROOT.'/task-labels/{label}', [200]],
        'task_planning.labels.create' => ['task-planning.labels.create', self::ROOT.'/task-labels', [201]],
        'task_planning.labels.update' => ['task-planning.labels.update', self::ROOT.'/task-labels/{label}', [200]],
        'task_planning.labels.archive' => ['task-planning.labels.archive', self::ROOT.'/task-labels/{label}', [200]],
        'task_planning.labels.restore' => ['task-planning.labels.restore', self::ROOT.'/task-labels/{label}/restore', [200]],
        'task_planning.labels.options' => ['task-planning.labels.options', self::ROOT.'/projects/{project}/task-label-options', [200]],
        'task_planning.labels.replace' => ['task-planning.labels.replace', self::ROOT.'/tasks/{task}/labels', [200]],
        'task_planning.dependencies.get' => ['task-planning.dependencies.get', self::ROOT.'/tasks/{task}/dependencies', [200]],
        'task_planning.dependencies.options' => ['task-planning.dependencies.options', self::ROOT.'/tasks/{task}/dependency-options', [200]],
        'task_planning.dependencies.replace' => ['task-planning.dependencies.replace', self::ROOT.'/tasks/{task}/dependencies', [200]],
        'task_planning.contacts.get' => ['task-planning.contacts.get', self::ROOT.'/tasks/{task}/contact-responsibilities', [200]],
        'task_planning.contacts.options' => ['task-planning.contacts.options', self::ROOT.'/tasks/{task}/contact-responsibility-options', [200]],
        'task_planning.contacts.replace' => ['task-planning.contacts.replace', self::ROOT.'/tasks/{task}/contact-responsibilities', [200]],
    ];

    public const array DEFINITIONS = [
        'get' => ['GET', 'Read task planning details', 'TaskPlanningDetailsResponse', null],
        'labels.list' => ['GET', 'List task labels', 'TaskLabelListResponse', null],
        'labels.get' => ['GET', 'Read a task label', 'TaskLabelResponse', null],
        'labels.create' => ['POST', 'Create a task label', 'TaskPlanningMutationResponse', 'TaskLabelCreateRequest'],
        'labels.update' => ['PATCH', 'Rename a task label', 'TaskPlanningMutationResponse', 'TaskLabelUpdateRequest'],
        'labels.archive' => ['DELETE', 'Archive a task label', 'TaskPlanningMutationResponse', 'TaskLabelArchiveRequest'],
        'labels.restore' => ['POST', 'Restore a task label', 'TaskPlanningMutationResponse', 'TaskLabelRestoreRequest'],
        'labels.options' => ['GET', 'Read project label choices', 'TaskLabelListResponse', null],
        'labels.replace' => ['PUT', 'Replace task labels', 'TaskPlanningMutationResponse', 'TaskLabelReplacementRequest'],
        'dependencies.get' => ['GET', 'Read task prerequisites and dependents', 'TaskDependenciesResponse', null],
        'dependencies.options' => ['GET', 'Read prerequisite choices', 'TaskDependencyOptionListResponse', null],
        'dependencies.replace' => ['PUT', 'Replace task prerequisites', 'TaskPlanningMutationResponse', 'TaskDependencyReplacementRequest'],
        'contacts.get' => ['GET', 'Read client-contact responsibilities', 'TaskContactResponsibilitiesResponse', null],
        'contacts.options' => ['GET', 'Read client-contact responsibility choices', 'TaskContactOptionListResponse', null],
        'contacts.replace' => ['PUT', 'Replace client-contact responsibilities', 'TaskPlanningMutationResponse', 'TaskContactResponsibilityReplacementRequest'],
    ];

    /** @return array<string,array{method:string,scopes:list<string>,summary:string,description:string,parameters:list<string|array<string,mixed>>,response:string,errors:array<int,string>,request?:string}> */
    public static function documentation(): array
    {
        $result = [];
        foreach (self::DEFINITIONS as $suffix => [$method, $summary, $response, $request]) {
            $id = 'task_planning.'.$suffix;
            $scopes = [$method === 'GET' ? 'tasks:read' : 'tasks:write'];
            if (str_starts_with($suffix, 'contacts.')) {
                $scopes[] = 'clients:read';
            }
            $parameters = ['WorkspaceId'];
            preg_match_all('/\{(task|label|project)\}/', self::ROUTES[$id][1], $targets);
            foreach ($targets[1] as $name) {
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
            }
            if (in_array($suffix, ['labels.list', 'labels.options', 'dependencies.get', 'dependencies.options', 'contacts.options'], true)) {
                $parameters[] = ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 25]];
                $parameters[] = ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 2048]];
                if ($suffix !== 'dependencies.get') {
                    $parameters[] = ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 100]];
                }
                if ($suffix === 'labels.list') {
                    $parameters[] = ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['active', 'archived', 'all'], 'default' => 'active']];
                }
            }
            if ($request !== null) {
                $parameters[] = 'IdempotencyKey';
            }
            $result[$id] = ['method' => $method, 'scopes' => $scopes, 'summary' => $summary,
                'description' => 'Internal task planning only; Portal and dual Agent identities are refused. Current exact workspace/project/task lineage is required. Catalog and client-contact responsibility require workspace owner/admin; project managers may change labels and prerequisites. Responsibility grants no account access. Reads are bounded and create no graph state. Dependencies support 1,000 tasks, 100 retained lists and 5,000 edges; oversized projects retain label/contact editing. Writes require captured versions and a receipt key, reject cycles, and reauthorize exact replay. Completion remains allowed with unfinished prerequisites or children; no propagation, billing or access change occurs.',
                'parameters' => $parameters, 'response' => $response,
                'errors' => [401 => 'Authentication is missing, expired or revoked.', 403 => 'Scope or current internal authority is insufficient.',
                    404 => 'The scoped record is unavailable or task planning is disabled.', 409 => 'A captured version is stale or the receipt key was reused for different input.',
                    422 => 'Input, confirmation, cycle or supported organization size is invalid.', 429 => 'Too many requests; retry after Retry-After.'],
            ] + ($request === null ? [] : ['request' => $request]);
        }

        return $result;
    }
}
