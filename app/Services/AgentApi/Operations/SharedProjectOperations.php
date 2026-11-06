<?php

namespace App\Services\AgentApi\Operations;

use App\Services\SharedProjects\SharedProjectCollaboration;
use App\Services\SharedProjects\SharedProjectReadService;
use App\Support\AgentApi\AgentApiScopes;

/**
 * Shared project operations (docs/ux-overhaul.md section 7a, slice 9): what a
 * workspace sees of a project it hosts or takes part in, and the two writes a
 * participant's people can make on it. Code-owned schemas, regenerated into the
 * public contract by scripts/generate-shared-project-contract.php.
 *
 * Granting, revoking and leaving are deliberately not operations: handing
 * another business access to a project is a person's act in the browser.
 *
 * The path's `{workspace}` is always the caller's own workspace. The project in
 * `{project}` is found through the host-or-active-participation rule, so
 * a project the caller's workspace neither hosts nor takes part in is 404.
 */
final class SharedProjectOperations
{
    private const string ROOT = '/workspaces/{workspace}/shared-projects';

    public const array ROUTES = [
        'shared_projects.list' => ['shared-projects.index', self::ROOT, [200]],
        'shared_projects.get' => ['shared-projects.show', self::ROOT.'/{project}', [200]],
        'shared_projects.tasks.list' => ['shared-projects.tasks.index', self::ROOT.'/{project}/tasks', [200]],
        'shared_projects.tasks.assign' => ['shared-projects.tasks.assignees.update', self::ROOT.'/{project}/tasks/{task}/assignees', [200]],
        'shared_projects.comments.list' => ['shared-projects.comments.index', self::ROOT.'/{project}/tasks/{task}/comments', [200]],
        'shared_projects.comments.create' => ['shared-projects.comments.store', self::ROOT.'/{project}/tasks/{task}/comments', [201]],
    ];

    /** @var array<string, string> */
    private const array METHODS = [
        'shared_projects.list' => 'GET',
        'shared_projects.get' => 'GET',
        'shared_projects.tasks.list' => 'GET',
        'shared_projects.tasks.assign' => 'PUT',
        'shared_projects.comments.list' => 'GET',
        'shared_projects.comments.create' => 'POST',
    ];

    /** @var array<string, list<string>> */
    private const array SCOPES = [
        'shared_projects.list' => [AgentApiScopes::PROJECTS_READ],
        'shared_projects.get' => [AgentApiScopes::PROJECTS_READ],
        'shared_projects.tasks.list' => [AgentApiScopes::TASKS_READ],
        'shared_projects.tasks.assign' => [AgentApiScopes::TASKS_WRITE],
        'shared_projects.comments.list' => [AgentApiScopes::COLLABORATION_READ, AgentApiScopes::TASKS_READ],
        'shared_projects.comments.create' => [AgentApiScopes::COLLABORATION_WRITE, AgentApiScopes::TASKS_READ],
    ];

    /** Operation id => [MCP handler, title, response component, request component|null]. */
    public const array DEFINITIONS = [
        'shared_projects.list' => ['list', 'List shared projects', 'SharedProjectListResponse', null],
        'shared_projects.get' => ['get', 'Get a shared project', 'SharedProjectResponse', null],
        'shared_projects.tasks.list' => ['tasks', 'List a shared project\'s tasks', 'SharedProjectTaskListResponse', null],
        'shared_projects.tasks.assign' => ['assign', 'Assign a shared task across workspaces', 'SharedProjectAssignmentResponse', 'SharedProjectAssignRequest'],
        'shared_projects.comments.list' => ['comments', 'List a shared task\'s comments', 'SharedProjectCommentListResponse', null],
        'shared_projects.comments.create' => ['commentCreate', 'Comment on a shared task', 'SharedProjectCommentResponse', 'SharedProjectCommentRequest'],
    ];

    /** @return list<string> every component this family adds to the contract */
    public static function components(): array
    {
        $names = [];
        foreach (self::DEFINITIONS as [, , $response, $request]) {
            $names[] = $response;
            if ($request !== null) {
                $names[] = $request;
            }
        }

        return $names;
    }

    /** @return array<string, array<string, mixed>> */
    public static function documentation(): array
    {
        $shared = 'The path workspace is the caller\'s own. The project is the host workspace\'s, and the caller sees it only as the host, or as a workspace with an accepted, unrevoked participation that has not left; any other workspace is told 404, and so is a pending, declined, revoked or expired invitation. Nothing about money crosses workspaces: no rate, time entry, invoice, payment, agreement, billing contact, internal note, file or other project of the host or of another participant is ever returned. A participant sees only tasks the host already shows its client, with the host\'s workspace name and the assignees\' workspace names. A participant who is not an owner or admin of their workspace sees only the tasks assigned to them.';
        $errors = [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks the scope, or the caller\'s role in the workspace does not allow the operation.',
            404 => 'The workspace, project or task is not visible to the caller, or the caller\'s workspace does not take part in the project.',
            429 => 'Too many requests (retry after Retry-After).',
        ];
        $project = ['name' => 'project', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $task = ['name' => 'task', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $page = [
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => SharedProjectReadService::PAGE, 'default' => SharedProjectReadService::PAGE]],
            ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 2048]],
        ];
        $writeErrors = array_replace($errors, [
            404 => $errors[404].' Agent writes also answer 404 while AGENT_API_COLLABORATION_WRITES_ENABLED is off.',
            409 => 'The Idempotency-Key was already used with a different request, or the first request with it is still being processed.',
            422 => 'The Idempotency-Key header is missing, or a field is invalid (`errors` names it).',
        ]);

        $descriptions = [
            'shared_projects.list' => 'The shared projects the workspace hosts (with a participant or a pending invitation) and the ones it takes part in. Bounded to 100 of each.',
            'shared_projects.get' => 'One shared project: its name, status and the viewer\'s standing. The host also gets the client name, description, participating workspaces and open invitations; a participant gets the host workspace\'s name and its own linked local project. Only a viewer who may assign gets assignee_options: the people of the host and of each participant who can be handed a task.',
            'shared_projects.tasks.list' => 'The project\'s tasks the caller may see, 50 per page, ordered by creation; follow meta.next_cursor until null. Each carries its assignees with the workspace each stands in; user and workspace ids are returned only to a viewer who may assign.',
            'shared_projects.tasks.assign' => 'Replace who a task is assigned to, across the host and its participants, for a host workspace owner or admin or a project owner or manager. People already assigned stay; anyone new must be offered in assignee_options. Assigning gives access to nothing: a participant sees the task only as the rules above allow. A person can hold a task once. Requires AGENT_API_COLLABORATION_WRITES_ENABLED.',
            'shared_projects.comments.list' => 'The comments of a task the caller may see, 50 per page. The host sees all of them and which are shared; a participant sees only comments in the shared conversation: ones a participant wrote, and ones the host published to it. The host\'s internal comments are never returned to a participant.',
            'shared_projects.comments.create' => 'Add a comment to a task the caller may see. It joins the shared conversation, so the host and every participant who can see the task read it; it is never shown to the host\'s client. A host viewer with no write role on the project, and a participant who cannot see the task, cannot comment. Requires AGENT_API_COLLABORATION_WRITES_ENABLED.',
        ];

        $operations = [];
        foreach (self::DEFINITIONS as $id => [, $title, $response, $request]) {
            $write = $request !== null;
            $parameters = ['WorkspaceId'];
            if ($id !== 'shared_projects.list') {
                $parameters[] = $project;
            }
            if (str_contains(self::ROUTES[$id][1], '{task}')) {
                $parameters[] = $task;
            }
            if (in_array($id, ['shared_projects.tasks.list', 'shared_projects.comments.list'], true)) {
                array_push($parameters, ...$page);
            }
            if ($write) {
                $parameters[] = 'IdempotencyKey';
            }
            $operations[$id] = [
                'method' => self::METHODS[$id],
                'scopes' => self::SCOPES[$id],
                'summary' => $title,
                'description' => $descriptions[$id].' '.$shared,
                'parameters' => $parameters,
                'response' => $response,
                'errors' => $write ? $writeErrors : $errors,
            ] + ($write ? ['request' => $request] : []);
        }

        return $operations;
    }

    /** @return array<string, mixed> the JSON schema of a component this family adds */
    public static function schema(string $component): array
    {
        $text = ['type' => 'string'];
        $nullableText = ['type' => ['string', 'null']];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $url = ['type' => 'string'];
        $time = ['type' => 'string', 'format' => 'date-time'];
        $date = ['type' => ['string', 'null'], 'format' => 'date'];
        $cursor = self::object(['next_cursor' => $nullableText]);
        $assignee = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'workspace_name'],
            'properties' => ['name' => $text, 'workspace_name' => $text, 'user_id' => $uuid, 'workspace_id' => $uuid],
        ];
        $task = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'title', 'description', 'status', 'shared_with_participants', 'starts_on', 'due_on', 'completed_at', 'web_url', 'assignees', 'can_comment'],
            'properties' => [
                'id' => $uuid, 'title' => $text, 'description' => $nullableText, 'status' => $text, 'shared_with_participants' => ['type' => 'boolean'],
                'starts_on' => $date, 'due_on' => $date, 'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'web_url' => $url, 'assignees' => ['type' => 'array', 'items' => $assignee], 'can_comment' => ['type' => 'boolean'],
            ],
        ];
        $comment = self::object([
            'id' => $uuid, 'body' => $text, 'author_name' => $text, 'author_workspace_name' => $text,
            'shared_with_participants' => ['type' => 'boolean'], 'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ]);
        $listed = static fn (array $item): array => self::object([
            'data' => ['type' => 'array', 'maxItems' => SharedProjectReadService::PAGE, 'items' => $item],
            'meta' => $cursor,
        ]);
        $option = self::object(['user_id' => $uuid, 'name' => $text, 'workspace_id' => $uuid, 'workspace_name' => $text]);

        return [
            'SharedProjectListResponse' => self::object(['data' => self::object([
                'hosted' => ['type' => 'array', 'maxItems' => SharedProjectReadService::OPTIONS, 'items' => self::object(['id' => $uuid, 'name' => $text, 'client_name' => $text, 'web_url' => $url])],
                'participating' => ['type' => 'array', 'maxItems' => SharedProjectReadService::OPTIONS, 'items' => self::object(['id' => $uuid, 'name' => $text, 'host_workspace_name' => $text, 'web_url' => $url])],
            ])]),
            'SharedProjectResponse' => self::object(['data' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'name', 'status', 'side', 'host_workspace_name', 'viewer', 'web_url'],
                'properties' => [
                    'id' => $uuid, 'name' => $text, 'status' => $text,
                    'side' => ['type' => 'string', 'enum' => ['host', 'participant']],
                    'host_workspace_name' => $text,
                    'viewer' => self::object(['sees_whole_project' => ['type' => 'boolean'], 'can_assign' => ['type' => 'boolean'], 'can_manage_participants' => ['type' => 'boolean'], 'can_act_as_participant' => ['type' => 'boolean']]),
                    'web_url' => $url,
                    'client_name' => $nullableText, 'description' => $nullableText,
                    'participants' => ['type' => 'array', 'maxItems' => SharedProjectReadService::PARTICIPANTS, 'items' => self::object(['id' => $uuid, 'workspace_name' => $text, 'accepted_at' => $time])],
                    'invitations' => ['type' => 'array', 'maxItems' => SharedProjectReadService::PARTICIPANTS, 'items' => self::object(['id' => $uuid, 'email' => $text, 'expires_at' => $time])],
                    'participation' => self::object(['accepted_at' => $time]),
                    'local_project' => ['type' => ['object', 'null'], 'additionalProperties' => false, 'required' => ['id', 'name'], 'properties' => ['id' => $uuid, 'name' => $text]],
                    'assignee_options' => ['type' => 'array', 'items' => $option],
                ],
            ]]),
            'SharedProjectTaskListResponse' => $listed($task),
            'SharedProjectCommentListResponse' => $listed($comment),
            'SharedProjectCommentResponse' => self::object(['data' => $comment]),
            'SharedProjectAssignmentResponse' => self::object(['data' => self::object(['task_id' => $uuid, 'assignees' => ['type' => 'array', 'items' => $assignee]])]),
            'SharedProjectCommentRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['body'],
                'properties' => ['body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50000]],
            ],
            'SharedProjectAssignRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['assignees'],
                'properties' => ['assignees' => ['type' => 'array', 'maxItems' => SharedProjectCollaboration::ASSIGNEES, 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['user_id', 'workspace_id'],
                    'properties' => ['user_id' => $uuid, 'workspace_id' => $uuid],
                ]]],
            ],
        ][$component] ?? throw new \InvalidArgumentException("Unknown shared project component {$component}.");
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed> */
    private static function object(array $fields): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($fields), 'properties' => $fields];
    }
}
