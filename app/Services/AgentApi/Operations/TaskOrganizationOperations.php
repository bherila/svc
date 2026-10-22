<?php

namespace App\Services\AgentApi\Operations;

/** Transport metadata for the bounded, versioned internal project organizer. */
final class TaskOrganizationOperations
{
    private const string ROOT = '/workspaces/{workspace}/projects/{project}/task-organization';

    public const array ROUTES = [
        'task_organization.get' => ['task-organization.show', self::ROOT, [200]],
        'task_organization.lists.create' => ['task-organization.lists.store', self::ROOT.'/lists', [201]],
        'task_organization.lists.update' => ['task-organization.lists.update', self::ROOT.'/lists/{list}', [200]],
        'task_organization.lists.archive' => ['task-organization.lists.archive', self::ROOT.'/lists/{list}', [200]],
        'task_organization.lists.restore' => ['task-organization.lists.restore', self::ROOT.'/lists/{list}/restore', [200]],
        'task_organization.lists.reorder' => ['task-organization.lists.reorder', self::ROOT.'/lists/order', [200]],
        'task_organization.tasks.create' => ['task-organization.tasks.store', self::ROOT.'/tasks', [201]],
        'task_organization.tasks.move' => ['task-organization.tasks.move', self::ROOT.'/tasks/{task}/container', [200]],
        'task_organization.tasks.reorder' => ['task-organization.tasks.reorder', self::ROOT.'/tasks/order', [200]],
    ];

    /** @return array<string,array{method:string,scopes:list<string>,summary:string,description:string,parameters:list<string|array<string,mixed>>,response:string,errors:array<int,string>,request?:string}> */
    public static function documentation(): array
    {
        $definitions = [
            'get' => ['get', null, 'Read bounded task organization', 'Read current lists, task containment and opaque graph/container versions. Internal project managers only; Portal and dual Agent identities are refused. Up to 1,000 retained tasks, 100 retained lists and 100 siblings; unsupported sizes fail explicitly. Request container=true with nullable parent_id/list_id for one complete sibling group.'],
            'lists.create' => ['post', 'TaskOrganizationListCreateRequest', 'Create a task list', 'Create an internal task list using the current graph version. Client visibility is false when omitted.'],
            'lists.update' => ['patch', 'TaskOrganizationListUpdateRequest', 'Update a task list', 'Update a list name or client visibility using current list and graph versions. Tasks retain their own sharing choices; publishing a containing list can reveal already-shared tasks.'],
            'lists.archive' => ['delete', 'TaskOrganizationListArchiveRequest', 'Archive a task list', 'After explicit confirmation, archive a list using current list and graph versions. Tasks remain in place and are hidden from clients while archived.'],
            'lists.restore' => ['post', 'TaskOrganizationListRestoreRequest', 'Restore a task list', 'Restore a list using current list and graph versions. Saved list and task sharing choices are preserved.'],
            'lists.reorder' => ['put', 'TaskOrganizationListOrderRequest', 'Reorder task lists', 'Supply every active list ID exactly once with the rendered lists_version as expected_container_version. Archived lists are excluded.'],
            'tasks.create' => ['post', 'TaskOrganizationTaskCreateRequest', 'Create an organized task', 'Create a task in the supplied nullable parent/list container using the current graph version. New tasks are internal by default. Assignment grants no access.'],
            'tasks.move' => ['patch', 'TaskOrganizationTaskMoveRequest', 'Move a task', 'Move one task and its descendants within this project using current task and graph versions. Cycles, excessive depth and incompatible containers are refused. Sharing flags and billing facts remain unchanged.'],
            'tasks.reorder' => ['put', 'TaskOrganizationTaskOrderRequest', 'Reorder sibling tasks', 'Supply every current sibling task ID exactly once with the rendered container version. Omitted, duplicate, foreign and stale IDs are refused.'],
        ];
        $result = [];
        foreach ($definitions as $suffix => [$method, $request, $summary, $description]) {
            $parameters = ['WorkspaceId', 'ProjectId'];
            if (in_array($suffix, ['lists.update', 'lists.archive', 'lists.restore'], true)) {
                $parameters[] = ['name' => 'list', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
            }
            if ($suffix === 'tasks.move') {
                $parameters[] = 'TaskId';
            }
            if ($suffix === 'get') {
                foreach (['container' => ['type' => 'boolean', 'default' => false], 'parent_id' => ['type' => 'string', 'format' => 'uuid'], 'list_id' => ['type' => 'string', 'format' => 'uuid']] as $name => $schema) {
                    $parameters[] = ['name' => $name, 'in' => 'query', 'schema' => $schema];
                }
            } else {
                $parameters[] = 'IdempotencyKey';
            }
            $result['task_organization.'.$suffix] = [
                'method' => strtoupper($method),
                'scopes' => [$suffix === 'get' ? 'tasks:read' : 'tasks:write'],
                'summary' => $summary,
                'description' => $description,
                'parameters' => $parameters,
                'response' => $suffix === 'get' ? 'TaskOrganizationResponse' : 'TaskOrganizationMutationResponse',
                'errors' => [
                    401 => 'Authentication is missing, expired or revoked.',
                    403 => 'The caller lacks scope or current internal project-management authority; Portal and dual Agent identities are refused.',
                    404 => 'The workspace, project or record is unavailable, or task organization is disabled.',
                    409 => 'A supplied opaque version is stale or the idempotency key was used for another request.',
                    422 => 'A field, container, complete order, confirmation or supported size is invalid.',
                    429 => 'Too many requests; retry after Retry-After.',
                ],
            ] + ($request === null ? [] : ['request' => $request]);
        }

        return $result;
    }
}
