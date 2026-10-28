<?php

namespace App\Services\AgentApi\Operations;

use App\Support\AgentApi\ReadView;
use App\Support\Finance\ProcessorMovementKind;
use App\Support\Square\SquareIdentifiers;

/**
 * What the REST contract says about each agent operation beyond its schemas
 * (#408 item 3): method, scopes, prose, parameters, the request and response
 * schemas it names in the document. SVC's own extensions (`x-svc-flags`,
 * `x-mcp-tool`) are generated from the declaration ({@see AgentOperationCatalog}).
 *
 * {@see AgentOperationCatalog} turns each entry into the operation's REST
 * binding, and the registry generates `/api/openapi.json` from it. Schemas
 * stay in `public/openapi/svc-agent-v1.json`'s components, which is also the
 * checked copy of the generated document.
 */
final class AgentRestDocumentation
{
    /**
     * The refusals each portal access and invitation operation answers with,
     * by status (#385). Each body is the shared `Error` schema; a 422 for a
     * field carries its messages under `errors`.
     *
     * @var array<string, array<int, string>>
     */
    private const array PORTAL_ERRORS = [
        'access.list' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin, or the token lacks clients:read.',
            404 => 'The workspace or client is not visible to the caller.',
            422 => 'limit is out of range, or the cursor is malformed or belongs to another client.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'access.grant' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again under the access lock, also on a replay), or the token lacks the operation\'s scopes.',
            404 => 'The workspace or client is not visible to the caller, or an agent write cutover is switched off.',
            409 => 'The Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, confirm is not true, or a field is invalid (`errors` names it). Also when nobody signs in with the address (`email`, or `invite` while invitations are enabled), the person already has portal access, or a project does not belong to the client.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'access.update' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again under the access lock, also on a replay), or the token lacks the operation\'s scopes.',
            404 => 'The workspace, client or contact is not visible to the caller, or an agent write cutover is switched off.',
            409 => 'expected_version is no longer current (read the contact again), or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, confirm is not true, or a field is invalid (`errors` names it). Also when a project does not belong to the client.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'access.revoke' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again under the access lock, also on a replay), or the token lacks the operation\'s scopes.',
            404 => 'The workspace, client or contact is not visible to the caller, or an agent write cutover is switched off.',
            409 => 'expected_version is no longer current (read the contact again), or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, confirm is not true, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'invitations.list' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin, or the token lacks clients:read.',
            404 => 'The workspace or client is not visible to the caller, or portal invitations are disabled.',
            422 => 'limit is out of range, or the cursor is malformed or belongs to another client.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'invitations.create' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again under the access lock, also on a replay), or the token lacks the operation\'s scopes. Also when the identity provider will not let this account send invitations.',
            404 => 'The workspace or client is not visible to the caller, an agent write cutover is switched off, or portal invitations are disabled.',
            409 => 'The Idempotency-Key was already used with a different request, or another invitation to this address is being sent (retry after Retry-After).',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, confirm is not true, or a field is invalid (`errors` names it). Also when the address already signs in here, a local account holds it unlinked, a project does not belong to the client, or the identity provider refuses the address.',
            429 => 'Too many requests, or the identity provider is limiting invitations (retry after Retry-After).',
            503 => 'The identity provider is unavailable or misconfigured; nothing was invited.',
        ],
        'invitations.resend' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again under the access lock, also on a replay), or the token lacks the operation\'s scopes. Also when the identity provider will not let this account send invitations.',
            404 => 'The workspace, client or invitation is not visible to the caller, an agent write cutover is switched off, or portal invitations are disabled.',
            409 => 'The Idempotency-Key was already used with a different request; the invitation was already accepted or withdrawn, or the identity provider no longer has it; or another change to this address is in progress (retry after Retry-After).',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, or confirm is not true.',
            429 => 'Too many requests, or the identity provider is limiting invitations (retry after Retry-After).',
            503 => 'The identity provider is unavailable or misconfigured; the invitation was not sent again.',
        ],
        'invitations.revoke' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again under the access lock, also on a replay), or the token lacks the operation\'s scopes. Also when the identity provider will not let this account withdraw invitations.',
            404 => 'The workspace, client or invitation is not visible to the caller, an agent write cutover is switched off, or portal invitations are disabled.',
            409 => 'The Idempotency-Key was already used with a different request; the invitation was already accepted or withdrawn, or the identity provider no longer has it; or another change to this address is in progress (retry after Retry-After).',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, or confirm is not true.',
            429 => 'Too many requests, or the identity provider is limiting invitations (retry after Retry-After).',
            503 => 'The identity provider withdrawal did not finish. Access is already withdrawn: repeat the same request with the same Idempotency-Key to finish it.',
        ],
    ];

    /**
     * The refusals of the aggregate billing audits (#459), by status. Each
     * body is the shared `Error` schema.
     *
     * @var array<string, array<int, string>>
     */
    private const array BILLING_AUDIT_ERRORS = [
        'audit' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks billing:read.',
            404 => 'The workspace is not visible to the caller, or the caller is not a workspace owner or admin.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'preflight' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks billing:read.',
            404 => 'The workspace is not visible to the caller, or the caller is not a workspace owner or admin.',
            429 => 'Too many requests, or the workspace already ran 5 preflights in the last ten minutes (retry after Retry-After; the message says when).',
        ],
    ];

    /**
     * The refusals of the writes MCP reaches over REST (#459), by status.
     * Each body is the shared `Error` schema; a 422 for a field carries its
     * messages under `errors`.
     *
     * @var array<string, array<int, string>>
     */
    private const array ROUTED_WRITE_ERRORS = [
        'tasks.create' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks tasks:write, or the caller may not manage tasks on the project (checked again on a replay).',
            404 => 'The workspace or project is not visible to the caller, or AGENT_API_WRITES_ENABLED is off.',
            409 => 'The Idempotency-Key was already used with a different request, or the first request with it is still being processed.',
            422 => 'The Idempotency-Key header is missing, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'tasks.update' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks tasks:write, or the caller may not manage tasks on the project (checked again on a replay).',
            404 => 'The workspace or task is not visible to the caller, or AGENT_API_WRITES_ENABLED is off.',
            409 => 'expected_version is no longer current (read the task again), or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'time_entries.log' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks time:write; the caller may not log time on a project; a billing rate or approve: true was sent without time:approve; or approval was asked for while AGENT_API_WRITES_ENABLED is off or without the approver role on every project. Each is checked again on a replay.',
            404 => 'The workspace, a project or a task is not visible to the caller, or AGENT_API_TIME_ENTRY_WRITES_ENABLED is off.',
            409 => 'The Idempotency-Key was already used with a different request (approve is part of the request), or the first request with it is still being processed.',
            422 => 'The Idempotency-Key header is missing, view is not read or is sent as a field, an entry has an unknown field, or a field is invalid (`errors` names it): a date outside the time sheet, or a billing rate from someone who could not approve the entry.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'time_entries.update' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks time:write, or the entry is someone else\'s and the caller is not a workspace owner or admin (checked again on a replay).',
            404 => 'The workspace, entry or task is not visible to the caller, or AGENT_API_TIME_ENTRY_WRITES_ENABLED is off.',
            409 => 'expected_version is no longer current (read the entry again), the entry is no longer editable, or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'time_entries.delete' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks time:write, or the entry is someone else\'s and the caller is not a workspace owner or admin (checked again on a replay).',
            404 => 'The workspace or entry is not visible to the caller, or AGENT_API_TIME_ENTRY_WRITES_ENABLED is off.',
            409 => 'expected_version is no longer current (read the entry again), the entry is approved without a draft invoice or allocated to an issued, paid or void one, or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, or expected_version is missing or malformed.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'expenses.log' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again on a replay), or the token lacks expenses:write.',
            404 => 'The workspace, a client or a project is not visible to the caller, a project is not the client\'s, or an expense write cutover is off.',
            409 => 'The Idempotency-Key was already used with a different request, or the first request with it is still being processed.',
            422 => 'The Idempotency-Key header is missing, an entry has an unknown field, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'expenses.update' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again on a replay), or the token lacks expenses:write.',
            404 => 'The workspace, expense or project is not visible to the caller, the project is not the expense client\'s, or an expense write cutover is off.',
            409 => 'expected_version is no longer current (read the expense again), the expense is no longer a draft, or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'expenses.delete' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin (checked again on a replay), or the token lacks expenses:write.',
            404 => 'The workspace or expense is not visible to the caller, or an expense write cutover is off.',
            409 => 'expected_version is no longer current (read the expense again), the expense is no longer a draft, or the Idempotency-Key was already used with a different request.',
            422 => 'The Idempotency-Key header is missing, or expected_version is missing or malformed.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
        'invoices.issue' => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin, or the token lacks billing:deliver; with a payment, also without payments:record or while AGENT_API_PAYMENT_WRITES_ENABLED is off. Each is checked again on a replay.',
            404 => 'The workspace or invoice is not visible to the caller, or an invoice write cutover is off.',
            409 => 'expected_version is no longer current (read the invoice again), or the Idempotency-Key was already used with a different request (payment is part of the request).',
            422 => 'The Idempotency-Key header is missing, view is not read or is sent as a field, confirm is not true, or a field is invalid (`errors` names it). Also when the invoice cannot be issued (void, or a draft that fails its checks), when a payment is sent for an invoice that is no longer a draft, or when the payment is in another currency, exceeds the balance or predates the issue date. Without a payment, an invoice already issued, partially paid or paid is returned as it is, not refused.',
            429 => 'Too many requests (retry after Retry-After).',
        ],
    ];

    /**
     * Why the opening-rollover audit is deprecated and where its removal is
     * tracked (#460). The same text marks the REST operation, the MCP tool and
     * `svc:billing:audit-opening-rollover`, so every door says the same thing.
     */
    public const OPENING_ROLLOVER_DEPRECATION = 'This audit sized the opening-rollover repair (#134), which is done. It will be removed after its deprecation window; see https://github.com/bherila/svc/issues/500. Nothing replaces it.';

    /**
     * @return array<string, array{method: string, scopes: list<string>, description: string|false, parameters: list<string|array<string, mixed>>, request?: string, request_types?: list<string>, response?: string, response_description?: string, binary?: array{0: string, 1: string}, errors?: array<int, string>, deprecation?: string}>
     */
    private static function collaborationOperations(): array
    {
        $definitions = [
            'topics.list' => ['GET', null, 'CollaborationTopicListResponse'],
            'topics.get' => ['GET', null, 'CollaborationTopicResponse'],
            'topics.create' => ['POST', 'CollaborationTopicCreateRequest', 'CollaborationTopicResponse'],
            'topics.update' => ['PATCH', 'CollaborationTopicUpdateRequest', 'CollaborationTopicResponse'],
            'topics.delete' => ['DELETE', 'CollaborationDeleteRequest', 'CollaborationTopicResponse'],
            'comments.list' => ['GET', null, 'CollaborationCommentListResponse'],
            'comments.create' => ['POST', 'CollaborationCommentCreateRequest', 'CollaborationCommentResponse'],
            'comments.update' => ['PATCH', 'CollaborationCommentUpdateRequest', 'CollaborationCommentResponse'],
            'comments.delete' => ['DELETE', 'CollaborationDeleteRequest', 'CollaborationCommentResponse'],
        ];
        $operations = [];
        foreach ($definitions as $name => [$method, $request, $response]) {
            $parameters = ['WorkspaceId', self::uuidPath('client')];
            if (in_array($name, ['topics.get', 'topics.update', 'topics.delete'], true)) {
                $parameters[] = self::uuidPath('topic');
            }
            if (in_array($name, ['comments.list', 'comments.create'], true)) {
                $parameters[] = ['name' => 'parentType', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['task', 'topic']]];
                $parameters[] = self::uuidPath('parentPublicId');
            }
            if (in_array($name, ['comments.update', 'comments.delete'], true)) {
                $parameters[] = self::uuidPath('comment');
            }
            if ($method !== 'GET') {
                $parameters[] = 'IdempotencyKey';
            }
            if ($name === 'topics.list') {
                $parameters = [...$parameters, self::query('kind', ['type' => 'string', 'enum' => ['note', 'discussion']]),
                    self::query('project_id', ['type' => 'string', 'format' => 'uuid']), self::query('page', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000])];
            }
            if ($name === 'comments.list') {
                $parameters = [...$parameters, self::query('limit', ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 1]),
                    self::query('cursor', ['type' => 'string', 'maxLength' => 4096])];
            }
            $scopes = $method === 'GET' ? ['collaboration:read'] : ($name === 'topics.create' ? ['collaboration:write'] : ['collaboration:write', 'collaboration:read']);
            $operations['collaboration.'.$name] = ['method' => $method, 'scopes' => $scopes, 'parameters' => $parameters,
                'description' => ($name === 'comments.list' ? 'Comment lists use ascending opaque cursor pagination; limit is a maximum row count and serialized byte bounds may shorten a page. Follow meta.next_cursor or next_href until null; previous_href is always null. REST, MCP and CLI share this contract; native thread pages retain focused comment links. ' : '').'Native collaboration uses current tenant and parent authorization. Portal and dual identities are read-only and receive only disclosed ancestors. Staff authors edit their own text; managers publish and moderate. Writes require the global and collaboration cutovers; versioned writes also require collaboration:read. All writes use idempotency receipts and current locked authority, including on replay. Deletion creates a tombstone and requires confirm true. No imported history, grants, billing amounts or email are changed.',
                'response' => $response, 'response_description' => 'Authorized native collaboration result',
                'errors' => [401 => 'Authentication is required.', 403 => 'Scope or current role does not permit this operation.', 404 => 'A parent or tenant is not visible, or the agent write cutover is off.',
                    409 => 'Expected version is stale or the idempotency key was reused with different input.', 422 => 'An input field or idempotency key is invalid.', 429 => 'Too many requests.']];
            if ($request !== null) {
                $operations['collaboration.'.$name]['request'] = $request;
            }
        }

        return $operations;
    }

    /**
     * @return array<string, array{method: string, scopes: list<string>, description: string|false, parameters: list<string|array<string, mixed>>, request?: string, request_types?: list<string>, response?: string, response_description?: string, binary?: array{0: string, 1: string}, errors?: array<int, string>, deprecation?: string}>
     */
    public static function operations(): array
    {
        return [
            ...TaskOrganizationOperations::documentation(),
            ...TaskPlanningOperations::documentation(),
            ...SquareCoveredPaymentOperations::documentation(),
            ...AttachmentSharingOperations::documentation(),
            ...self::collaborationOperations(),
            'agreements.activate' => [
                'method' => 'POST',
                'scopes' => ['clients:write', 'billing:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'agreements_activateRequest',
                'response' => 'AgreementResponse',
                'response_description' => 'Authorized result',
            ],
            'agreements.create' => [
                'method' => 'POST',
                'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'agreements_createRequest',
                'response' => 'AgreementResponse',
                'response_description' => 'Authorized result',
            ],
            'agreements.get' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('agreement')],
                'response' => 'AgreementResponse',
                'response_description' => 'Authorized result',
            ],
            'agreements.list' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('status', ['type' => 'string', 'enum' => ['draft', 'active', 'terminated', 'expired', 'paused']]),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'AgreementListResponse',
                'response_description' => 'Authorized result',
            ],
            'agreements.square_series.clear' => [
                'method' => 'DELETE',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Clear an agreement\'s billed-in-Square marker so SVC collects its invoices again. Requires the agreement\'s current expected_version and confirm: true; the change is recorded in the client history.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'AgreementSquareSeriesClearRequest',
                'response' => 'AgreementResponse',
                'response_description' => 'Agreement no longer billed in Square',
            ],
            'agreements.square_series.set' => [
                'method' => 'PUT',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Mark an agreement as billed in Square by its recurring series key (the six-digit prefix of the series\' NNNNNN-R-NNNN invoice numbers) in one Square location: Square numbers invoices uniquely per location only, so the same prefix in another location is another series. Without location_id the location is the one SVC\'s Square mirror holds the series in; a series it holds in no location or in more than one is refused with 422 until location_id names it. SVC still generates and issues the agreement\'s invoices, but never emails, charges or offers a payment link for them. Requires the agreement\'s current expected_version and confirm: true. A series already marking another agreement in that location (or in every location, from before markers named one) is refused with 409. Nothing is sent to Square.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'AgreementSquareSeriesRequest',
                'response' => 'AgreementResponse',
                'response_description' => 'Agreement billed in Square',
            ],
            'agreements.terminate' => [
                'method' => 'POST',
                'scopes' => ['clients:write', 'billing:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'agreements_terminateRequest',
                'response' => 'AgreementResponse',
                'response_description' => 'Authorized result',
            ],
            'agreements.update' => [
                'method' => 'PATCH',
                'scopes' => ['clients:write', 'billing:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'agreements_updateRequest',
                'response' => 'AgreementResponse',
                'response_description' => 'Authorized result',
            ],
            'attachments.delete' => [
                'method' => 'DELETE',
                'scopes' => ['files:write', 'files:read'],
                'description' => 'Manager-only deletion, requiring current attachment version and confirm true. Same key replays without deleting twice. Both workflow and file write flags required.',
                'parameters' => ['WorkspaceId', self::uuidPath('attachment'), 'IdempotencyKey'],
                'request' => 'AttachmentDeleteRequest',
                'response' => 'AttachmentResponse',
                'response_description' => 'Manager-only deletion, requiring current attachment version and confirm true. Same key replays without deleting twice. Both workflow and file write flags required.',
            ],
            'attachments.download' => [
                'method' => 'GET',
                'scopes' => ['files:read'],
                'description' => 'Stream the private file using the signed URL, same bearer credential and current manager permission.',
                'parameters' => [
                    'WorkspaceId',
                    self::uuidPath('attachment'),
                    [
                        'name' => 'expires',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'signature',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'binary' => [
                    '*/*',
                    'Stream the private file using the signed URL, same bearer credential and current manager permission.',
                ],
            ],
            'attachments.download_url' => [
                'method' => 'GET',
                'scopes' => ['files:read'],
                'description' => 'Prepare a ten-minute signed content URL. Same bearer credential, files:read and current manager permission remain required.',
                'parameters' => ['WorkspaceId', self::uuidPath('attachment')],
                'response' => 'AttachmentDownloadUrlResponse',
                'response_description' => 'Prepare a ten-minute signed content URL. Same bearer credential, files:read and current manager permission remain required.',
            ],
            'attachments.get' => [
                'method' => 'GET',
                'scopes' => ['files:read'],
                'description' => 'Manager-only metadata, excluding storage and uploader identifiers. Dual identities use exact Portal grants, current parent ancestry and file visibility.',
                'parameters' => ['WorkspaceId', self::uuidPath('attachment')],
                'response' => 'AttachmentResponse',
                'response_description' => 'Manager-only metadata, excluding storage and uploader identifiers. Dual identities use exact Portal grants, current parent ancestry and file visibility.',
            ],
            'attachments.list' => [
                'method' => 'GET',
                'scopes' => ['files:read'],
                'description' => 'Manager-only generic attachment listing, bounded to 100 authorized newest files per page, ordered by descending ID. Follow meta.next_cursor; serialized byte limits may shorten a page. Dual identities use exact Portal grants and file visibility. Expense receipts use separate routes.',
                'parameters' => [
                    'WorkspaceId',
                    [
                        'name' => 'recordType',
                        'in' => 'path',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'enum' => ['company', 'project', 'task', 'proposal', 'agreement', 'invoice', 'topic', 'comment'],
                        ],
                    ],
                    self::uuidPath('recordPublicId'),
                    self::filePageLimit(),
                    self::filePageCursor(),
                ],
                'response' => 'AttachmentListResponse',
                'response_description' => 'Manager-only generic attachment listing, bounded to 100 authorized newest files per page, ordered by descending ID. Follow meta.next_cursor; serialized byte limits may shorten a page. Dual identities use exact Portal grants and file visibility. Expense receipts use separate routes.',
            ],
            'attachments.upload' => [
                'method' => 'POST',
                'scopes' => ['files:write'],
                'description' => 'Staff-manager-only multipart upload, at most 50 MiB. Dual Portal identities are denied. The verified persistent privacy latch chooses ordinary upload defaults; the initial reader deployment retains inheritance. Visibility fields and topic/comment parents remain rejected before staging. Locked parent version checked; actor/client/key receipt binds parent/version/filename/media/length/SHA256, with replay and audit. Uploading adds an immutable file and leaves parent business facts unchanged.',
                'parameters' => [
                    'WorkspaceId',
                    [
                        'name' => 'recordType',
                        'in' => 'path',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'enum' => ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'],
                        ],
                    ],
                    self::uuidPath('recordPublicId'),
                    'IdempotencyKey',
                    [
                        'name' => 'expires',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'signature',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'request' => 'AttachmentUploadRequest',
                'request_types' => ['multipart/form-data'],
                'response' => 'AttachmentResponse',
                'response_description' => 'Staff-manager-only multipart upload, at most 50 MiB. Dual Portal identities are denied. The verified persistent privacy latch chooses ordinary upload defaults; the initial reader deployment retains inheritance. Visibility fields and topic/comment parents remain rejected before staging. Locked parent version checked; actor/client/key receipt binds parent/version/filename/media/length/SHA256, with replay and audit. Uploading adds an immutable file and leaves parent business facts unchanged.',
                'errors' => [503 => 'The durable upload policy cannot be verified. No bytes or recovery rows are staged; ordinary reads remain available.'],
            ],
            'attachments.upload_url' => [
                'method' => 'POST',
                'scopes' => ['files:write'],
                'description' => 'Authorizes a later upload; preparing a URL does not reserve idempotency or persist bytes. Both file and workflow write flags and manager permission required. Upload file plus expected_version with same bearer credential and Idempotency-Key to returned URL.',
                'parameters' => [
                    'WorkspaceId',
                    [
                        'name' => 'recordType',
                        'in' => 'path',
                        'required' => true,
                        'schema' => [
                            'type' => 'string',
                            'enum' => ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'],
                        ],
                    ],
                    self::uuidPath('recordPublicId'),
                ],
                'response' => 'AttachmentUploadUrlResponse',
                'response_description' => 'Authorizes a later upload; preparing a URL does not reserve idempotency or persist bytes. Both file and workflow write flags and manager permission required. Upload file plus expected_version with same bearer credential and Idempotency-Key to returned URL.',
                'errors' => [503 => 'The durable upload policy cannot be verified. No bytes or recovery rows are staged; ordinary reads remain available.'],
            ],
            'billing_audit.stale_and_missing' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. A stale draft is a draft whose due date or scheduled issue date is before today in the workspace\'s calendar; a missing period is the started current period of an active recurring agreement with no non-void invoice. Where Square serves the workspace, square_payment_mismatches lists invoices paid outside Square while Square is still open, and square_billing_audit flags billed series periods with no linked svc invoice, Square payments svc has not recorded, unlinked Square customers with an open balance, and series whose cadence, currency or amount drifted from the agreement, from svc\'s mirror as of its last sync. agreement_handovers reports up to 100 issued cadence invoices whose stored coverage overlaps a successor, including potentially prorated invoices requiring review. This read only flags them: nothing issues, sends or generates an invoice, and nothing is written to Square.',
                'parameters' => ['WorkspaceId'],
                'response' => 'StaleAndMissingInvoiceAuditResponse',
                'response_description' => 'Authorized result',
            ],
            'billing.audit_missing_billed_overage' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Aggregate counts of the workspace\'s charged invoices missing billed-overage data, as svc:billing:audit-missing-billed-overage reports for every workspace. Counts only; no invoice is identified.',
                'parameters' => ['WorkspaceId'],
                'response' => 'MissingBilledOverageAuditResponse',
                'response_description' => 'Aggregate counts for the workspace',
                'errors' => self::BILLING_AUDIT_ERRORS['audit'],
            ],
            'billing.audit_opening_rollover' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Aggregate counts of the workspace\'s agreements whose opening rollover changes their capacity ledger, and the capacity at stake in minutes, as svc:billing:audit-opening-rollover reports for every workspace. Counts only; no agreement is identified.',
                'parameters' => ['WorkspaceId'],
                'response' => 'OpeningRolloverAuditResponse',
                'response_description' => 'Aggregate counts for the workspace',
                'errors' => self::BILLING_AUDIT_ERRORS['audit'],
                'deprecation' => self::OPENING_ROLLOVER_DEPRECATION,
            ],
            'billing.audit_undated_collectible_invoices' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Aggregate counts and per-currency balances, in minor units, of the workspace\'s collectible invoices without a due date, as svc:billing:audit-undated-collectible-invoices reports for every workspace. Counts only; no invoice is identified.',
                'parameters' => ['WorkspaceId'],
                'response' => 'UndatedCollectibleInvoiceAuditResponse',
                'response_description' => 'Aggregate counts for the workspace',
                'errors' => self::BILLING_AUDIT_ERRORS['audit'],
            ],
            'billing.audit_unlinked_proposal_agreements' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Count the workspace\'s proposals whose agreement the duplicate guard in acceptance cannot see, as svc:engagement:audit-unlinked-proposal-agreements does for every workspace. Counts only; nothing is repaired or guessed.',
                'parameters' => ['WorkspaceId'],
                'response' => 'UnlinkedProposalAgreementAuditResponse',
                'response_description' => 'Aggregate counts for the workspace',
                'errors' => self::BILLING_AUDIT_ERRORS['audit'],
            ],
            'billing.audit_unplaceable_invoices' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Aggregate counts of the workspace\'s invoices whose billing period or cycle cannot be placed safely, and the overage hours at stake, as svc:billing:audit-unplaceable-invoices reports for every workspace. Counts only; no invoice is identified.',
                'parameters' => ['WorkspaceId'],
                'response' => 'UnplaceableInvoiceAuditResponse',
                'response_description' => 'Aggregate counts for the workspace',
                'errors' => self::BILLING_AUDIT_ERRORS['audit'],
            ],
            'billing.preflight_schedule_generation' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Classify every period each of the workspace\'s active billing schedules is due to bill through today, without writing, and count how many schedules would halt and why, as svc:billing:preflight-schedule-generation does for every workspace. Counts only, including agreement_handover_overlap schedule defects. It examines at most 60 periods per schedule (the command examines 240), and complete is false when a backlog was longer, so the result is inconclusive rather than clean. A workspace gets 5 per ten minutes, then 429 with Retry-After.',
                'parameters' => ['WorkspaceId'],
                'response' => 'ScheduleGenerationPreflightResponse',
                'response_description' => 'Aggregate counts for the workspace',
                'errors' => self::BILLING_AUDIT_ERRORS['preflight'],
            ],
            'deferred_backlog.preview' => [
                'method' => 'GET', 'scopes' => ['billing:read'],
                'description' => 'Manager-only FIFO whole-entry deferred settlement quote. Supply exactly one of hours or amount (gross minor units before discount). round_up optionally includes the next entry. Contract rates are unchanged; any discount requires a reason. Entry-backed deferred work only; carried ledger balances remain for termination. No writes.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'),
                    ['name' => 'hours', 'in' => 'query', 'schema' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1000000]],
                    ['name' => 'amount', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647]],
                    ['name' => 'round_up', 'in' => 'query', 'schema' => ['type' => 'boolean', 'default' => false]],
                    ['name' => 'discount_amount', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647]],
                    ['name' => 'discount_reason', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 1000]],
                ],
                'response' => 'DeferredBuyDownPreviewResponse', 'response_description' => 'Selection and backlog version',
            ],
            'invoices.create_deferred_buydown' => [
                'method' => 'POST', 'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Manager-only deferred buy-down draft. Reserves whole entries using the same target and pricing as preview. Requires expected_backlog_version and Idempotency-Key; a changed shelf is 409. A discount is a negative adjustment with a reason. No automatic issue or delivery; ordinary issue/send confirmations apply. Void or discard releases the entries.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'DeferredBuyDownCreateRequest', 'response' => 'InvoiceMutationResponse', 'response_description' => 'Reserved draft',
            ],
            'billing_schedules.create' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Create a schedule without issuing. Refuses a first period overlapping a successor agreement with 422; no automatic proration. expected_version is the parent agreement revision. Workspace managers only.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'BillingScheduleCreateRequest',
                'response' => 'BillingScheduleMutationResponse',
                'response_description' => 'Result',
            ],
            'billing_schedules.generate' => [
                'method' => 'POST',
                'scopes' => ['billing:deliver', 'billing:read'],
                'description' => 'Generate and issue due invoices through today using the schedule revision. Explicit confirmation required. Normal automatic client delivery settings apply. Atomic preflight and idempotent replay. Refuses successor agreement coverage overlaps with 422 before writing any invoice.',
                'parameters' => ['WorkspaceId', self::uuidPath('schedule'), 'IdempotencyKey'],
                'request' => 'BillingScheduleGenerateRequest',
                'response' => 'BillingScheduleGenerateResponse',
                'response_description' => 'Result',
            ],
            'billing_schedules.get' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Get one workspace-scoped billing schedule as a manager.',
                'parameters' => ['WorkspaceId', self::uuidPath('schedule')],
                'response' => 'BillingScheduleResponse',
                'response_description' => 'Result',
            ],
            'billing_schedules.list' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Manager-only bounded billing schedules with opaque current revisions.',
                'parameters' => [
                    'WorkspaceId',
                    self::query('is_active', ['type' => ['boolean', 'null']]),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'BillingScheduleListResponse',
                'response_description' => 'Result',
            ],
            'capacity_ledger.get' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Manager-only trailing window of the computed agreement capacity ledger, and the agreement\'s deferred backlog: the outstanding deferred work the termination invoice would collect today, its amount at the hourly rate as that invoice would price it, and its monthly trend over the same months.',
                'parameters' => [
                    'WorkspaceId',
                    self::uuidPath('agreement'),
                    self::query('months', ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 12]),
                ],
                'response' => 'CapacityLedgerResponse',
                'response_description' => 'Result',
            ],
            'clients.archive' => [
                'method' => 'POST',
                'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Archival requires confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'clients_archiveRequest',
                'response' => 'ClientCompanyResponse',
                'response_description' => 'Authorized result',
            ],
            'clients.create' => [
                'method' => 'POST',
                'scopes' => ['clients:write'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'clients_createRequest',
                'response' => 'ClientCompanyResponse',
                'response_description' => 'Authorized result',
            ],
            'clients.get' => [
                'method' => 'GET',
                'scopes' => ['clients:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('client')],
                'response' => 'ClientCompanyResponse',
                'response_description' => 'Authorized result',
            ],
            'clients.list' => [
                'method' => 'GET',
                'scopes' => ['clients:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('status', ['type' => 'string', 'enum' => ['active', 'archived']]),
                    'Limit',
                    'Cursor',
                    'UpdatedSince',
                    'Watermark',
                ],
                'response' => 'ClientCompanyListResponse',
                'response_description' => 'Authorized result',
            ],
            'clients.contacts.list' => [
                'method' => 'GET', 'scopes' => ['clients:read'],
                'description' => 'Workspace managers only. Business contacts never grant portal access or send invitations. All records, including archived contacts, are returned unless status is specified.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'Limit', 'Cursor', self::query('status', ['type' => 'string', 'enum' => ['active', 'archived']])],
                'response' => 'ClientBusinessContactListResponse', 'response_description' => 'Authorized business contact result',
            ],
            'clients.contacts.get' => [
                'method' => 'GET', 'scopes' => ['clients:read'],
                'description' => 'Workspace managers only. Business contacts never grant portal access or send invitations. ',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('contact')],
                'response' => 'ClientBusinessContactResponse', 'response_description' => 'Authorized business contact result',
            ],
            'clients.contacts.create' => [
                'method' => 'POST', 'scopes' => ['clients:write'],
                'description' => 'Workspace managers only. Business contacts never grant portal access or send invitations. ',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'clients_contacts_createRequest',
                'response' => 'ClientBusinessContactResponse', 'response_description' => 'Authorized business contact result',
            ],
            'clients.contacts.update' => [
                'method' => 'PATCH', 'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Business contacts never grant portal access or send invitations. Omitted fields remain unchanged; explicit null clears nullable fields. Current expected_version is required.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('contact'), 'IdempotencyKey'],
                'request' => 'clients_contacts_updateRequest',
                'response' => 'ClientBusinessContactResponse', 'response_description' => 'Authorized business contact result',
            ],
            'clients.contacts.archive' => [
                'method' => 'POST', 'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Business contacts never grant portal access or send invitations. Archive retains the record and requires explicit confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('contact'), 'IdempotencyKey'],
                'request' => 'clients_contacts_archiveRequest',
                'response' => 'ClientBusinessContactResponse', 'response_description' => 'Authorized business contact result',
            ],
            'clients.contacts.restore' => [
                'method' => 'POST', 'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Business contacts never grant portal access or send invitations. ',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('contact'), 'IdempotencyKey'],
                'request' => 'clients_contacts_restoreRequest',
                'response' => 'ClientBusinessContactResponse', 'response_description' => 'Authorized business contact result',
            ],
            'clients.portal_access.list' => [
                'method' => 'GET',
                'scopes' => ['clients:read'],
                'description' => 'List portal contacts for a client as a workspace owner/admin, including scope, selected projects and finished mutation URLs.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'Limit', 'Cursor'],
                'response' => 'PortalContactListResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['access.list'],
            ],
            'clients.portal_access.grant' => [
                'method' => 'POST',
                'scopes' => ['clients:write'],
                'description' => 'Grant portal access to an existing account found by exact email (ignoring case) and bound to the configured identity provider. When nobody signs in with the address, the 422 names `email`, or `invite` while portal invitations are enabled: create a portal invitation for the address instead. Requires explicit confirmation and an idempotency key.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'clients_portal_access_grantRequest',
                'response' => 'PortalContactResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['access.grant'],
            ],
            'clients.portal_access.update' => [
                'method' => 'PATCH',
                'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Change a portal contact to the whole client or selected projects. Requires its current expected_version, explicit confirmation and an idempotency key.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('user'), 'IdempotencyKey'],
                'request' => 'clients_portal_access_updateRequest',
                'response' => 'PortalContactResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['access.update'],
            ],
            'clients.portal_access.revoke' => [
                'method' => 'DELETE',
                'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Revoke a contact\'s portal access and project limits. Requires its current expected_version, explicit confirmation and an idempotency key.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('user'), 'IdempotencyKey'],
                'request' => 'clients_portal_access_revokeRequest',
                'response' => 'PortalAccessRevokedResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['access.revoke'],
            ],
            'clients.portal_invitations.list' => [
                'method' => 'GET',
                'scopes' => ['clients:read'],
                'description' => 'List a client\'s open and not-applied portal invitations and unfinished provider withdrawals as a workspace owner/admin: address, scope, projects, status, expiry and available retry URLs. Never includes the acceptance link. Requires SVC_PORTAL_INVITATIONS_ENABLED.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'Limit', 'Cursor'],
                'response' => 'PortalInvitationListResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['invitations.list'],
            ],
            'clients.portal_invitations.create' => [
                'method' => 'POST',
                'scopes' => ['clients:write'],
                'description' => 'Invite an address nobody signs in with yet to a client\'s portal. The identity provider emails an admission link to this application only; the access is given when that person first signs in with the address, if the inviter is still an owner or admin and the projects still belong to the client. Refused for an address that already signs in. Never returns the link. Requires explicit confirmation and an idempotency key.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'clients_portal_invitations_createRequest',
                'response' => 'PortalInvitationResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['invitations.create'],
            ],
            'clients.portal_invitations.resend' => [
                'method' => 'POST',
                'scopes' => ['clients:write'],
                'description' => 'Send a pending or expired portal invitation again with a new link and expiry; the previous link stops working. Never returns the link. Requires explicit confirmation and an idempotency key.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('invitation'), 'IdempotencyKey'],
                'request' => 'clients_portal_invitations_resendRequest',
                'response' => 'PortalInvitationResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['invitations.resend'],
            ],
            'clients.portal_invitations.revoke' => [
                'method' => 'DELETE',
                'scopes' => ['clients:write'],
                'description' => 'Withdraw portal access at commit, then finish the identity provider withdrawal within the request, or dismiss an invitation that was not applied. An unfinished provider withdrawal remains visible for retry; replaying its idempotency key finishes it without restoring access. Requires explicit confirmation and an idempotency key.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), self::uuidPath('invitation'), 'IdempotencyKey'],
                'request' => 'clients_portal_invitations_revokeRequest',
                'response' => 'PortalInvitationResponse',
                'response_description' => 'Authorized result',
                'errors' => self::PORTAL_ERRORS['invitations.revoke'],
            ],
            'clients.restore' => [
                'method' => 'POST',
                'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'clients_restoreRequest',
                'response' => 'ClientCompanyResponse',
                'response_description' => 'Authorized result',
            ],
            'clients.update' => [
                'method' => 'PATCH',
                'scopes' => ['clients:write', 'clients:read'],
                'description' => 'Workspace managers only. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_CLIENT_WRITES_ENABLED. Versioned writes also require the corresponding read scope. Enabling automatic delivery requires a delay and a valid billing email or client portal recipient. Existing records require their current opaque expected_version; agreement creation uses the parent client version. Activation and termination require confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'clients_updateRequest',
                'response' => 'ClientCompanyResponse',
                'response_description' => 'Authorized result',
            ],
            'companies.payment_reminders.get' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Whether the client\'s automatic payment reminder cadence is on, since when, its steps, and the client version companies.payment_reminders.set requires.',
                'parameters' => ['WorkspaceId', self::uuidPath('client')],
                'response' => 'CompanyPaymentRemindersResponse',
                'response_description' => 'Authorized result',
            ],
            'companies.payment_reminders.set' => [
                'method' => 'PUT',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Turn the client\'s automatic payment reminder cadence on or off: 3 days before an invoice\'s due date, on it, then 7 and 14 days after, between 09:00 and 17:00 in the workspace\'s time zone. It is off until turned on. Turning it on never sends a step that fell due before; turning it off stops every step not yet sent. An invoice Square collects is reminded about only with Square\'s own payment page, and not on the cadence while Square charges a card on file or sends its own reminders for it. Requires the client\'s current expected_version; the change is recorded in the client history.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'CompanyPaymentRemindersRequest',
                'response' => 'CompanyPaymentRemindersResponse',
                'response_description' => 'Cadence updated',
            ],
            'companies.square_customer.clear' => [
                'method' => 'DELETE',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Clear a client\'s confirmed Square customer. The mapping is kept as history. Requires the client\'s current version from companies.square_customer.get and confirm: true.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'CompanySquareCustomerClearRequest',
                'response' => 'CompanySquareStateResponse',
                'response_description' => 'Square customer cleared',
            ],
            'companies.square_customer.get' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. The client\'s confirmed Square customer, if any, with the client version that companies.square_customer.set and .clear require.',
                'parameters' => ['WorkspaceId', self::uuidPath('client')],
                'response' => 'CompanySquareStateResponse',
                'response_description' => 'Authorized result',
            ],
            'companies.square_customer.set' => [
                'method' => 'PUT',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Confirm a client\'s Square customer id, replacing any earlier mapping (kept as history). One client per Square customer: a customer already mapped to another client is refused with 409. Requires the client\'s current version from companies.square_customer.get. Square ids are checked for shape only; nothing is sent to Square.',
                'parameters' => ['WorkspaceId', self::uuidPath('client'), 'IdempotencyKey'],
                'request' => 'CompanySquareCustomerRequest',
                'response' => 'CompanySquareStateResponse',
                'response_description' => 'Square customer confirmed',
            ],
            'oauth.token.revoke' => [
                'method' => 'DELETE',
                'scopes' => [],
                'description' => 'Revokes only the OAuth access token making this request and its tracked refresh-token family, including rotated access tokens. Other connections of the same person or client, other people, and pending authorization codes from other grants are unaffected. Any supported OAuth access token can call this endpoint with no additional scopes, for either the REST or MCP resource; personal OAuth tokens can revoke themselves too. SVC does not issue client-credentials tokens. No token id or request body is accepted. Repeating the request returns 204 or 401 once authentication sees the revocation. This is a client credential lifecycle endpoint and is deliberately not an MCP tool.',
                'parameters' => [],
                'response_description' => 'Calling token family revoked',
                'errors' => [
                    401 => 'The OAuth bearer is missing, invalid, expired or already revoked.',
                    429 => 'Too many requests (retry after Retry-After).',
                ],
            ],
            'connections.disconnect' => [
                'method' => 'DELETE',
                'scopes' => [],
                'description' => 'Disconnects the calling application from the calling person\'s account, as a finance system does when the person turns its connection off. Every credential the person holds for the calling OAuth client is revoked in one transaction - pending authorization codes and refresh tokens before access tokens - so nothing issued to that client can act or mint a replacement, including a refresh already in flight. Other applications\' connections, and other people\'s connections to the same application, are unaffected. A personal API token disconnects only itself. Any REST credential may call it; an MCP connection uses connections.revoke.',
                'parameters' => [],
                'response_description' => 'Disconnected',
                'errors' => [
                    401 => 'The token is missing, expired or revoked, or is not a REST credential.',
                    429 => 'Too many requests (retry after Retry-After).',
                ],
            ],
            'connections.revoke' => [
                'method' => 'DELETE',
                'scopes' => ['mcp:use'],
                'description' => 'Disconnects the calling connection: token is the id of the access token making the request. Its refresh tokens are revoked before the access token itself, in one transaction, so the connection cannot mint a replacement; the token stops working on the next request. Any other token id, including another of the same person\'s connections, is a 404 and revokes nothing. For two minutes afterwards, a refresh of this connection that was already in flight is revoked as it is issued, so it cannot keep the connection; the person\'s other connections, including to the same client, are unaffected. Every connection a person holds is revoked by an operator with svc:auth:revoke-connections.',
                'parameters' => ['TokenId'],
                'response_description' => 'Connection revoked',
            ],
            'context.get' => [
                'method' => 'GET',
                'scopes' => ['identity:read'],
                'description' => 'Returns role capabilities intersected with the current token scopes and write-cutover state. Per-project capability IDs are included only with projects:read.',
                'parameters' => [],
                'response' => 'ContextResponse',
                'response_description' => 'Authorized context',
            ],
            'expense_schedules.create' => [
                'method' => 'POST',
                'scopes' => ['clients:read', 'expenses:write'],
                'description' => 'Manager-only creation. expected_version names the client company and is checked while that parent is locked. Both expense write flags required.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'ExpenseScheduleCreateRequest',
                'response' => 'ExpenseScheduleResponse',
                'response_description' => 'Successful operation',
            ],
            'expense_schedules.generate' => [
                'method' => 'POST',
                'scopes' => ['expenses:read', 'expenses:write'],
                'description' => 'Explicit confirmation and current schedule version required. Materializes at most 24 due draft expenses in workspace calendar. Never approves, issues or sends an invoice. Replay preserves the original generated_count. Both expense write flags required.',
                'parameters' => ['WorkspaceId', self::uuidPath('schedule'), 'IdempotencyKey'],
                'request' => 'ExpenseScheduleGenerateRequest',
                'response' => 'ExpenseScheduleGenerateResponse',
                'response_description' => 'Successful operation',
            ],
            'expense_schedules.list' => [
                'method' => 'GET',
                'scopes' => ['expenses:read'],
                'description' => 'Manager-only bounded listing; cursor is bound to workspace and company filter.',
                'parameters' => [
                    'WorkspaceId',
                    self::query('company_id', ['type' => 'string', 'format' => 'uuid']),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'ExpenseScheduleListResponse',
                'response_description' => 'Successful operation',
            ],
            'expense_schedules.update' => [
                'method' => 'PATCH',
                'scopes' => ['expenses:read', 'expenses:write'],
                'description' => 'Manager-only full replacement of editable facts. Omitted/null project_id clears attribution. Calendar anchor, cadence and occurrence cursor are immutable. Current schedule version required under lock; both expense write flags required.',
                'parameters' => ['WorkspaceId', self::uuidPath('schedule'), 'IdempotencyKey'],
                'request' => 'ExpenseScheduleUpdateRequest',
                'response' => 'ExpenseScheduleResponse',
                'response_description' => 'Successful operation',
            ],
            'expenses.approve' => [
                'method' => 'POST',
                'scopes' => ['expenses:read', 'expenses:write'],
                'description' => 'Manager-only lifecycle change. Version checked under expense lock; both expense write flags required. Invoiced and unknown statuses are refused.',
                'parameters' => ['WorkspaceId', 'ExpenseId', 'IdempotencyKey'],
                'request' => 'ExpenseApprovalRequest',
                'response' => 'ExpenseResponse',
                'response_description' => 'Successful operation',
            ],
            'expenses.delete' => [
                'method' => 'DELETE',
                'scopes' => ['expenses:write'],
                'description' => 'Soft-delete draft expenses only, using the current version. Approved, invoiced and unknown statuses are refused. Workspace managers only; both write flags required.',
                'parameters' => ['WorkspaceId', 'ExpenseId', 'IdempotencyKey'],
                'request' => 'ExpectedVersionRequest',
                'response' => 'DeletionResponse',
                'response_description' => 'Draft expense soft-deleted',
                'errors' => self::ROUTED_WRITE_ERRORS['expenses.delete'],
            ],
            'expenses.list' => [
                'method' => 'GET',
                'scopes' => ['expenses:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('company_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('project_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('status', ['type' => 'string', 'enum' => ['draft', 'approved', 'invoiced']]),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'ExpenseListResponse',
                'response_description' => 'Authorized expenses',
            ],
            'expenses.log' => [
                'method' => 'POST',
                'scopes' => ['expenses:write'],
                'description' => 'Workspace managers only. Atomic batch of at most 20 expenses; amounts are minor units. Requires both AGENT_API_WRITES_ENABLED and AGENT_API_EXPENSE_WRITES_ENABLED. No approval or receipt attachment.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'ExpenseLogRequest',
                'response' => 'ExpenseBatchResponse',
                'response_description' => 'Draft expenses recorded or replayed',
                'errors' => self::ROUTED_WRITE_ERRORS['expenses.log'],
            ],
            'expenses.receipts.content' => [
                'method' => 'GET',
                'scopes' => ['expenses:read'],
                'description' => 'Stream receipt bytes only with a valid ten-minute signed URL and current manager bearer credential.',
                'parameters' => [
                    'WorkspaceId',
                    'ExpenseId',
                    self::uuidPath('receipt'),
                    [
                        'name' => 'expires',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'signature',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'binary' => ['*/*', 'Receipt bytes'],
            ],
            'expenses.receipts.download' => [
                'method' => 'GET',
                'scopes' => ['expenses:read'],
                'description' => 'Manager-only ten-minute download URL for an available receipt on this expense. Signed URL still requires expenses:read and current manager access.',
                'parameters' => ['WorkspaceId', 'ExpenseId', self::uuidPath('receipt')],
                'response' => 'ExpenseReceiptDownloadResponse',
                'response_description' => 'Successful operation',
            ],
            'expenses.receipts.list' => [
                'method' => 'GET',
                'scopes' => ['expenses:read'],
                'description' => 'Staff-manager-only available receipts, ordered by descending ID, default and maximum 100 per page. Follow meta.next_cursor; serialized byte limits may shorten a page. Includes current expense_version. Dual Portal identities are denied.',
                'parameters' => ['WorkspaceId', 'ExpenseId', self::filePageLimit(), self::filePageCursor()],
                'response' => 'ExpenseReceiptListResponse',
                'response_description' => 'Successful operation',
            ],
            'expenses.receipts.upload' => [
                'method' => 'POST',
                'scopes' => ['expenses:read', 'expenses:write'],
                'description' => 'Staff-manager-only multipart upload, at most 50 MiB. Dual Portal identities are denied. The verified persistent privacy latch chooses ordinary upload defaults; the initial reader deployment retains inheritance. Visibility fields and topic/comment parents remain rejected before staging. File digest, filename and expense version bind idempotent retries. Version is checked on locked expense and advances on upload. Prepared URLs expire after ten minutes; direct authenticated REST uploads need no preparation. Both expense write flags required.',
                'parameters' => ['WorkspaceId', 'ExpenseId', 'IdempotencyKey'],
                'request' => 'ExpenseReceiptUploadRequest',
                'request_types' => ['multipart/form-data'],
                'response' => 'ExpenseReceiptUploadResponse',
                'response_description' => 'Successful operation',
                'errors' => [503 => 'The durable upload policy cannot be verified. No bytes or recovery rows are staged; ordinary reads remain available.'],
            ],
            'expenses.receipts.upload_url' => [
                'method' => 'GET',
                'scopes' => ['expenses:read', 'expenses:write'],
                'description' => 'Prepare a ten-minute signed multipart upload URL, without mutating expense or receipt. The URL requires the same authorized bearer credential, Idempotency-Key header, expected_version and file form fields. Both expense write flags required.',
                'parameters' => ['WorkspaceId', 'ExpenseId'],
                'response' => 'ExpenseReceiptUploadUrlResponse',
                'response_description' => 'Successful operation',
                'errors' => [503 => 'The durable upload policy cannot be verified. No bytes or recovery rows are staged; ordinary reads remain available.'],
            ],
            'expenses.unapprove' => [
                'method' => 'POST',
                'scopes' => ['expenses:read', 'expenses:write'],
                'description' => 'Manager-only lifecycle change. Version checked under expense lock; both expense write flags required. Invoiced and unknown statuses are refused.',
                'parameters' => ['WorkspaceId', 'ExpenseId', 'IdempotencyKey'],
                'request' => 'ExpenseApprovalRequest',
                'response' => 'ExpenseResponse',
                'response_description' => 'Successful operation',
            ],
            'expenses.update' => [
                'method' => 'PATCH',
                'scopes' => ['expenses:write'],
                'description' => 'Replace all draft expense facts using its current version. Omitted project_id preserves attribution; explicit null clears it. Workspace managers only; both expense and workflow write flags required.',
                'parameters' => ['WorkspaceId', 'ExpenseId', 'IdempotencyKey'],
                'request' => 'ExpenseUpdateRequest',
                'response' => 'ExpenseResponse',
                'response_description' => 'Draft expense updated',
                'errors' => self::ROUTED_WRITE_ERRORS['expenses.update'],
            ],
            ...self::finance(),
            'invoices.add_time' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('invoice'), 'IdempotencyKey'],
                'request' => 'InvoiceAddTimeRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Authorized result',
            ],
            'invoices.correct' => [
                'method' => 'POST',
                'scopes' => ['billing:deliver'],
                'description' => 'Make an audited in-place correction to an unpaid issued invoice that has not been emailed to the client: its due date and/or line wording, and money only on operator-authored lines. Omitted lines are kept as they are; supplied lines must name every existing line. The document revision advances and any automatic client delivery is held for explicit release. Requires explicit user confirmation and a reason kept in the client history. Paid, partially paid, sent and void invoices are refused; void and reissue those instead.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceCorrectionRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Invoice corrected',
            ],
            'invoices.create_draft' => [
                'method' => 'POST',
                'scopes' => ['billing:write'],
                'description' => 'Create an ad-hoc draft invoice for a client from explicit manual lines and/or explicit approved time, with the fields of the invoice screen\'s form: invoice_number (default: the workspace\'s next number; a number already used is refused), issue_date (default: the day it is issued), a service period (either end may be given alone), is_visible_to_client (default false; issuing makes it visible), currency, due and scheduled issue dates and notes. Nothing is issued or sent.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'InvoiceCreateRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Invoice draft created',
            ],
            'invoices.discard_draft' => [
                'method' => 'POST',
                'scopes' => ['billing:write'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceDiscardRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Draft discarded and linked time released',
            ],
            'invoices.download_pdf' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('invoice')],
                'binary' => ['application/pdf', 'Authorized result'],
            ],
            'invoices.generate_period' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Manager only. period_start is a started retainer cadence cycle; service dates reconcile the preceding work cycle. Calls the existing cadence engine, the same path invoices.preview_period rolls back. A non-void invoice for this period is refused, while identical idempotent retries return the created draft. Where the deployment enables it, an hourly job creates the current period\'s draft for each agreement without a billing schedule that has something to bill; otherwise a started period without one is waiting for this call, and billing_audit.stale_and_missing lists those periods. Requires confirmation and the agreement version.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), 'IdempotencyKey'],
                'request' => 'InvoiceGeneratePeriodRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Authorized result',
            ],
            'invoices.corrections.get' => [
                'method' => 'GET', 'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Read complete before/after evidence for one correction, scoped to its workspace and invoice.',
                'parameters' => ['WorkspaceId', 'InvoiceId', self::uuidPath('correction')],
                'response' => 'InvoiceCorrectionResponse', 'response_description' => 'Correction evidence',
            ],
            'invoices.get' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'InvoiceId'],
                'response' => 'InvoiceDetailResponse',
                'response_description' => 'Authorized invoice with browser payment URL',
            ],
            'invoices.hold_delivery' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('invoice'), 'IdempotencyKey'],
                'request' => 'InvoiceDeliveryHoldRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Authorized result',
            ],
            'invoices.issue' => [
                'method' => 'POST',
                'scopes' => ['billing:deliver'],
                'description' => 'Issue a draft invoice as a workspace owner or admin, using its current version and confirm: true. Issuing never emails the client itself; automatic invoice email, when the client has it, is scheduled after its delay. An optional payment records money already received elsewhere in the same transaction and receipt (payments:record and AGENT_API_PAYMENT_WRITES_ENABLED); it never initiates a charge, and the scheduled delivery is cancelled. Requires AGENT_API_WRITES_ENABLED and AGENT_API_INVOICE_WRITES_ENABLED.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey', self::readView('the issued invoice exactly as invoices.get presents it', 'billing:read')],
                'request' => 'InvoiceIssueRequest',
                'response' => 'InvoiceIssueResponse',
                'response_description' => 'Invoice issued, and paid when a payment was supplied',
                'errors' => self::ROUTED_WRITE_ERRORS['invoices.issue'],
            ],
            'invoices.list' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('status', ['$ref' => '#/components/schemas/InvoiceStatus']),
                    'Limit',
                    'Cursor',
                    self::query('company_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('invoice_kind', ['$ref' => '#/components/schemas/InvoiceKind']),
                    self::query('issue_date_from', ['type' => 'string', 'format' => 'date']),
                    self::query('issue_date_to', ['type' => 'string', 'format' => 'date']),
                    self::query('due_date_from', ['type' => 'string', 'format' => 'date']),
                    self::query('due_date_to', ['type' => 'string', 'format' => 'date']),
                    [
                        'name' => 'service_period_overlaps',
                        'in' => 'query',
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['from', 'to'],
                            'properties' => [
                                'from' => ['type' => 'string', 'format' => 'date'],
                                'to' => ['type' => 'string', 'format' => 'date'],
                            ],
                        ],
                        'style' => 'deepObject',
                        'explode' => true,
                    ],
                    self::query('collectible', ['type' => 'boolean']),
                    [
                        'name' => 'overdue',
                        'in' => 'query',
                        'description' => 'Refused (422) together with updated_since or watermark, like every filter but company_id and invoice_kind: an invoice becomes overdue as the date passes, without changing, so a change feed cannot filter on it.',
                        'schema' => ['type' => 'boolean'],
                    ],
                    'UpdatedSince',
                    'Watermark',
                ],
                'response' => 'InvoiceListResponse',
                'response_description' => 'Authorized invoices',
            ],
            'invoices.preview_period' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Runs the checks and cadence engine of invoices.generate_period for one started retainer cycle inside a transaction that is always rolled back, and returns the draft it would write and any other invoice of the client the same generation would create, rewrite or remove. Writes nothing and takes no idempotency key. Refuses what generation refuses, with the same status, including 409 for a period that already has a non-void invoice. agreement_version is the expected_version a following invoices.generate_period call must present. Where the deployment enables it, an hourly job creates the current period\'s draft for each agreement without a billing schedule that has something to bill; billing_audit.stale_and_missing lists started periods with no non-void invoice.',
                'parameters' => ['WorkspaceId', self::uuidPath('agreement'), ['name' => 'period_start', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']]],
                'response' => 'InvoicePeriodPreviewResponse',
                'response_description' => 'The draft generation would write; nothing is written',
            ],
            'invoices.pdf' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Five-minute signed URL. Download still requires current API or browser authentication and current invoice visibility.',
                'parameters' => ['WorkspaceId', self::uuidPath('invoice')],
                'response' => 'InvoicePdfLinkResponse',
                'response_description' => 'Authorized result',
            ],
            'invoices.release_delivery' => [
                'method' => 'POST',
                'scopes' => ['billing:deliver', 'billing:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('invoice'), 'IdempotencyKey'],
                'request' => 'InvoiceDeliveryReleaseRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Authorized result',
            ],
            'invoices.send' => [
                'method' => 'POST',
                'scopes' => ['billing:deliver'],
                'description' => 'Email an issued invoice to explicit recipients, only with confirm: true and the current expected_version, with the invoice screen\'s compose fields: an optional subject (default: the invoice\'s own), an optional covering message, and bcc_self to blind-copy the signed-in user\'s own address - no other blind copy can be named. The delivery is registered with this request\'s receipt and sent once it commits. The additive delivery field reports the exact registered delivery, or null for a legacy receipt with no recorded delivery identity. A completed mutation is not proof of email acceptance: pending/sending are unresolved, sent means accepted by the mailer, and failed means preparation failed before handoff. Retry an uncertain response with the same key and unchanged body; repeating that key never sends again. Only a proven failed delivery permits an explicit new attempt with a new key and current invoice version. Never automatically resend a null, pending or sending outcome.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSendRequest',
                'response' => 'InvoiceSendResponse',
                'response_description' => 'Invoice and recorded delivery outcome',
            ],
            'invoices.reminders.list' => [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => 'Workspace managers only. Whether a payment reminder may be sent for the invoice now, to whom and with which pay link, why not when it may not, where the client\'s automatic cadence stands for it, and the latest 50 reminders, newest first, with who or what sent each and its result.',
                'parameters' => ['WorkspaceId', 'InvoiceId'],
                'response' => 'InvoiceRemindersResponse',
                'response_description' => 'Authorized result',
            ],
            'invoices.send_reminder' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Email the client a payment reminder for an issued or partially paid invoice with a balance, now, from SVC\'s usual invoice sender to the client\'s billing address and portal users. An invoice Square collects carries Square\'s own payment page and is refused with 422 when there is none usable (not linked, not mirrored, not open in Square, or paid partly outside Square); SVC never sends its own payment link for it. Any other invoice links to the client portal when the client could open it there, and carries the invoice PDF. Another reminder leaving for the same invoice is a 409. Requires the invoice\'s current expected_version and confirm: true. The reminder is registered with this request\'s receipt and sent once it commits; the response is the logged reminder with its result (sent, failed or cancelled). Repeating the same idempotency key returns the same reminder and sends nothing more.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceReminderSendRequest',
                'response' => 'InvoiceReminderResponse',
                'response_description' => 'Reminder sent or attempted',
            ],
            'invoices.square_link' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Link an SVC invoice to its Square invoice, as confirmed by a person: never automatically. Each side has at most one active link: a Square invoice already linked anywhere is refused with 409, and so is an invoice that is already linked unless replace: true re-points it (the replaced link is kept as history). Requires the invoice\'s current expected_version and confirm: true. When currency is given it must equal the invoice\'s. While an invoice is linked, or its agreement is billed in Square, SVC never emails it to the client, charges it or offers a payment link for it; issuing it and recording payments stay open. Square ids are checked for shape only; nothing is sent to Square.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareLinkRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'Invoice linked to Square',
            ],
            'invoices.square_cover' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. When one SVC invoice covers what Square split into several invoices, record another Square invoice as covered by this one (#487), as confirmed by a person. The invoice must already be linked to its primary Square invoice (invoices.square_link) and must not be void; a Square invoice linked or covered anywhere is refused with 409. When currency is given it must equal the invoice\'s. A coverage is not a link: SVC never automatically collects or allocates payments through it; covered payments require a separate explicit review and recording. It tells the billing audit the Square invoice is accounted for, so it is not reported as an unmirrored series period. Requires the invoice\'s current expected_version and confirm: true. Square ids are checked for shape only; nothing is sent to Square.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareCoverRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'Square invoice recorded as covered',
            ],
            'invoices.square_uncover' => [
                'method' => 'DELETE',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Stop recording a Square invoice as covered by this invoice. The record is kept as history and the Square invoice may be covered or linked again. Requires the invoice\'s current expected_version and confirm: true; a Square invoice this invoice does not cover is refused with 409.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareUncoverRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'Square invoice no longer covered',
            ],
            'invoices.square_unlink' => [
                'method' => 'DELETE',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Unlink an invoice from its Square invoice. The link is kept as history and either side may be linked again. Requires the invoice\'s current expected_version and confirm: true.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareUnlinkRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'Invoice unlinked from Square',
            ],
            'invoices.update_details' => [
                'method' => 'PATCH',
                'scopes' => ['billing:write'],
                'description' => 'Change the due date, scheduled issue date and/or notes of any draft invoice, generated or ad hoc, using its current version. Lines, linked time and the hours statement are unchanged, and a generated draft keeps the change when it is regenerated. A due date may not precede the draft\'s issue date. The scheduled issue date is a plan that billing_audit.stale_and_missing reads; nothing issues or sends on it. Issued invoices are corrected with invoices.correct instead.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceDetailsUpdateRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Draft invoice details updated',
            ],
            'invoices.update_draft' => [
                'method' => 'PATCH',
                'scopes' => ['billing:write'],
                'description' => 'Replace the explicit time selection and manual lines on a draft invoice.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceUpdateRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Draft invoice updated',
            ],
            'invoices.void' => [
                'method' => 'POST',
                'scopes' => ['billing:deliver'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceVoidRequest',
                'response' => 'InvoiceMutationResponse',
                'response_description' => 'Invoice voided',
            ],
            'operations.summary' => [
                'method' => 'GET',
                'scopes' => ['identity:read'],
                'description' => 'Always returns workspace_id; project, time, and invoice sections are omitted unless the token has their corresponding read scope. With billing:read, a workspace manager also receives deferred_backlog: the agreements with deferred work outstanding and what termination would charge for it.',
                'parameters' => ['WorkspaceId'],
                'response' => 'SummaryResponse',
                'response_description' => 'Role-sensitive summary',
            ],
            'payments.correct' => [
                'method' => 'PATCH',
                'scopes' => ['payments:record', 'payments:read'],
                'description' => 'Owner/admin correction of a received payment\'s descriptive fields: method, reference, notes and received_on, at least one of which must be given. Requires confirm: true, the version from payments.list (hence payments:read as well as payments:record) and a reason, which is kept in the client history. Omitted fields are unchanged; an explicit null reference or note clears it. A stale version is refused with 409 and a correction that changes nothing records nothing. Amount, currency, status, refunds, the invoice and processor fields cannot be changed. Never initiates a charge or a refund. Requires both outer workflow and payment write flags.',
                'parameters' => ['WorkspaceId', 'PaymentId', 'IdempotencyKey'],
                'request' => 'ReceivedPaymentCorrectionRequest',
                'response' => 'ReceivedPaymentResponse',
                'response_description' => 'Corrected payment',
            ],
            'payments.list' => [
                'method' => 'GET',
                'scopes' => ['payments:read'],
                'description' => 'List received payments for an explicit invoice_id or company_id (at least one required). Invoice visibility applies; private processor and reconciliation data is excluded.',
                'parameters' => [
                    'WorkspaceId',
                    'Limit',
                    'Cursor',
                    self::query('invoice_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('company_id', ['type' => 'string', 'format' => 'uuid']),
                ],
                'response' => 'ReceivedPaymentListResponse',
                'response_description' => 'Authorized received payments',
            ],
            'payments.record' => [
                'method' => 'POST',
                'scopes' => ['payments:record', 'billing:read'],
                'description' => 'Owner/admin bookkeeping for money already received, with the same fields as the invoice screen\'s payment form. Never initiates a charge. Explicit invoice, minor-unit amount, currency and method required. received_on is the day the money arrived and defaults to today on the workspace calendar; reference, notes and the finance system\'s transaction id are optional; status is succeeded (the default) or pending for money not yet cleared, which payments.set_status later settles. An optional finance_reconciliation (external_system_slug, external_transaction_uuid, allocated_amount) also writes the payment\'s finance allocation in the same transaction and receipt, as finance.reconciliations.upsert would; it needs finance:reconcile and a succeeded payment. Read invoices.get with billing:read for the current invoice expected_version; confirm: true is required. A succeeded payment over the balance is refused, and so is any payment while another on the invoice is pending. OAuth/MCP callers also refuse invoices Square collects (invoices.get reports pending_payments and collection_owner). The trusted first-party website session may explicitly record money already received on a Square-collected invoice as operator bookkeeping; this server-selected policy is not a request field. A retry with the same Idempotency-Key and body returns the original payment without recording again. Requires both outer workflow and payment write flags.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'ReceivedPaymentRecordRequest',
                'response' => 'ReceivedPaymentBatchResponse',
                'response_description' => 'Authorized received payments',
                'errors' => [
                    401 => 'Not signed in, or the token is missing, expired or revoked.',
                    403 => 'The caller is not a workspace owner or admin, or the token lacks payments:record or billing:read; with finance_reconciliation, also without finance:reconcile. Each is checked again on a replay.',
                    404 => 'The workspace or invoice does not exist, or an agent write cutover is switched off.',
                    409 => 'expected_version is no longer current (read the invoice again), the invoice changed while the request waited for it, or the Idempotency-Key was already used with a different request or is still being processed.',
                    422 => 'The Idempotency-Key header is missing, the body has an unknown field (finance_reconciliation is a closed object too), confirm is not true, or a field is invalid (`errors` names it). Also, checked under the invoice lock: the invoice is a draft or void, a succeeded payment exceeds the balance, another payment on the invoice may still be in flight - pending, or a failed Stripe payment that can be retried (pending_payments on invoices.get), or Square collects the invoice (collection_owner square); and with finance_reconciliation, a pending payment or an allocated_amount over the payment amount.',
                    429 => 'Too many requests (retry after Retry-After).',
                ],
            ],
            'payments.refund' => [
                'method' => 'POST',
                'scopes' => ['payments:record', 'payments:read'],
                'description' => 'Owner/admin record of money already returned to the client outside SVC, against a succeeded payment that was recorded by hand. amount is what was returned now, in minor units, at most what is left unrefunded; refunding the whole amount makes the payment refunded, otherwise it stays succeeded with its refunded_amount raised. The invoice\'s paid amount, balance and status are recomputed, so a paid invoice reopens by the amount; correct or void the invoice separately if the client no longer owes it. Requires confirm: true, the version from payments.list and a reason, which is kept in the client history. A stale version is refused with 409. Refused with 422: a payment recorded by a processor (Stripe, Square), which reports its own refunds; a pending, failed, canceled, disputed or fully refunded payment; more than is left; or a refund that would exceed the payment\'s active finance reconciliation allocations. SVC never sends money. Requires both outer workflow and payment write flags.',
                'parameters' => ['WorkspaceId', 'PaymentId', 'IdempotencyKey'],
                'request' => 'ReceivedPaymentRefundRequest',
                'response' => 'ReceivedPaymentResponse',
                'response_description' => 'Payment with the refund recorded',
            ],
            'payments.reverse_refund' => [
                'method' => 'POST',
                'scopes' => ['payments:record', 'payments:read'],
                'description' => 'Owner/admin correction of a refund that was recorded in error against a payment recorded by hand - the money was never returned to the client. Records a reversing entry linked to the refund, which stays in the payment\'s refunds unchanged: the refund is never edited or deleted. amount reverses part of the refund, in minor units; omitted, the whole refund is reversed. A refund is reversed at most once and never by more than it was, and a reversal is not itself reversed - if the money was returned after all, record the refund again with payments.refund. The payment\'s refunded_amount falls by the amount, a fully refunded payment becomes succeeded again, and the invoice\'s paid amount, balance and status are recomputed, so an invoice can be paid again. Requires confirm: true, the payment version and the refund id from payments.list, and a reason, which is kept in the client history with both entries\' ids. A stale version is refused with 409. Refused with 422: a payment recorded by a processor (Stripe, Square), whose refunds follow its own events; a refund already reversed, a reversal, or an id that is not a refund of this payment; more than the refund or than the payment\'s refunded amount; a void invoice; or successful payments that would exceed the invoice total. Never initiates a charge and never moves money. Requires both outer workflow and payment write flags.',
                'parameters' => [
                    'WorkspaceId',
                    'PaymentId',
                    ['name' => 'refund', 'in' => 'path', 'required' => true, 'description' => 'The refund to reverse: a refunds[].id of kind refund from payments.list.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    'IdempotencyKey',
                ],
                'request' => 'ReceivedPaymentRefundReversalRequest',
                'response' => 'ReceivedPaymentResponse',
                'response_description' => 'Payment with the reversal recorded',
            ],
            'payments.set_status' => [
                'method' => 'POST',
                'scopes' => ['payments:record', 'payments:read'],
                'description' => 'Owner/admin change of the status of a payment that was recorded by hand: succeeded when money recorded as pending cleared; failed when it bounced or was reversed; canceled when it was recorded in error and is withdrawn. Allowed from pending to any of the three, and from succeeded with nothing refunded to failed or canceled. failed, canceled, refunded and disputed are final - money that arrives later is recorded as a new payment - and a partly refunded payment is refunded in full instead. Setting the status it already has changes nothing. The invoice\'s paid amount, balance and status are recomputed, so withdrawing a succeeded payment reopens the balance; a succeeded payment cannot exceed the invoice total and cannot succeed on a void invoice. Requires confirm: true, the version from payments.list and a reason, which is kept in the client history. A stale version is refused with 409. Refused with 422: a payment recorded by a processor (Stripe, Square), which reports its own status; a transition outside these; or leaving succeeded while the payment holds active finance reconciliation allocations. Never initiates a charge or a refund. Requires both outer workflow and payment write flags.',
                'parameters' => ['WorkspaceId', 'PaymentId', 'IdempotencyKey'],
                'request' => 'ReceivedPaymentStatusRequest',
                'response' => 'ReceivedPaymentResponse',
                'response_description' => 'Payment with its new status',
            ],
            'projects.archive' => [
                'method' => 'POST',
                'scopes' => ['projects:write', 'projects:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'ProjectId', 'IdempotencyKey'],
                'request' => 'ProjectConfirmRequest',
                'response' => 'ProjectResponse',
                'response_description' => 'Project operation result',
            ],
            'projects.create' => [
                'method' => 'POST',
                'scopes' => ['projects:write', 'clients:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'ProjectCreateRequest',
                'response' => 'ProjectResponse',
                'response_description' => 'Project operation result',
            ],
            'projects.get' => [
                'method' => 'GET',
                'scopes' => ['projects:read'],
                'description' => 'Task data is embedded only when the token also has tasks:read. Task pages are bounded by row count and serialized bytes including the base project. Follow tasks_next_href or pass tasks_cursor to continue; a short page does not imply completion.',
                'parameters' => ['WorkspaceId', 'ProjectId', self::query('tasks_cursor', ['type' => 'string', 'maxLength' => 2048])],
                'response' => 'ProjectDetailResponse',
                'response_description' => 'Authorized project detail',
            ],
            'projects.list' => [
                'method' => 'GET',
                'scopes' => ['projects:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    'Limit',
                    'Cursor',
                    self::query('status', ['type' => 'string', 'enum' => ['active', 'archived', 'completed']]),
                    self::query('query', ['type' => 'string', 'maxLength' => 200]),
                ],
                'response' => 'ProjectListResponse',
                'response_description' => 'Authorized projects',
            ],
            'projects.members.list' => [
                'method' => 'GET',
                'scopes' => ['projects:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'ProjectId', 'Limit', 'Cursor'],
                'response' => 'ProjectMembersResponse',
                'response_description' => 'Project operation result',
            ],
            'projects.members.update' => [
                'method' => 'PUT',
                'scopes' => ['projects:write', 'projects:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'ProjectId', 'IdempotencyKey'],
                'request' => 'ProjectMemberUpdateRequest',
                'response' => 'ProjectResponse',
                'response_description' => 'Project operation result',
            ],
            'projects.update' => [
                'method' => 'PATCH',
                'scopes' => ['projects:write', 'projects:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'ProjectId', 'IdempotencyKey'],
                'request' => 'ProjectUpdateRequest',
                'response' => 'ProjectResponse',
                'response_description' => 'Project operation result',
            ],
            'proposals.accept' => [
                'method' => 'POST',
                'scopes' => ['proposals:accept', 'proposals:read'],
                'description' => 'Explicit confirmation, signer identity and current proposal version required. Authorized company portal recipient within project grants, or workspace manager recording offline acceptance. Signs and activates the resulting agreement. Both workflow and proposal write cutovers required.',
                'parameters' => ['WorkspaceId', self::uuidPath('proposal'), 'IdempotencyKey'],
                'request' => 'ProposalAcceptRequest',
                'response' => 'ProposalResponse',
                'response_description' => 'Authorized proposal result',
            ],
            'proposals.create' => [
                'method' => 'POST',
                'scopes' => ['proposals:write', 'clients:read'],
                'description' => 'Workspace managers only. Lock and check the client company version before creating a draft. Both workflow and proposal write cutovers required.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'ProposalCreateRequest',
                'response' => 'ProposalResponse',
                'response_description' => 'Authorized proposal result',
            ],
            'proposals.get' => [
                'method' => 'GET',
                'scopes' => ['proposals:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', self::uuidPath('proposal')],
                'response' => 'ProposalResponse',
                'response_description' => 'Authorized proposal result',
            ],
            'proposals.list' => [
                'method' => 'GET',
                'scopes' => ['proposals:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('company_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('status', ['type' => 'string', 'enum' => ['draft', 'sent', 'accepted', 'declined', 'expired']]),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'ProposalListResponse',
                'response_description' => 'Authorized proposal result',
            ],
            'proposals.send' => [
                'method' => 'POST',
                'scopes' => ['proposals:write', 'proposals:read'],
                'description' => 'Explicit confirmation and current proposal version required. Manager only; marks sent and publishes to client portal, without email. Both workflow and proposal write cutovers required.',
                'parameters' => ['WorkspaceId', self::uuidPath('proposal'), 'IdempotencyKey'],
                'request' => 'ProposalSendRequest',
                'response' => 'ProposalResponse',
                'response_description' => 'Authorized proposal result',
            ],
            'search' => [
                'method' => 'GET',
                'scopes' => ['identity:read'],
                'description' => 'Search authorized clients, projects, invoices, tasks, native notes, discussions and comments; each kind additionally requires its read scope. Collaboration kinds require collaboration:read and exact audience authorization. Bounded to five results per kind.',
                'parameters' => [
                    'WorkspaceId',
                    [
                        'name' => 'q',
                        'in' => 'query',
                        'required' => true,
                        'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    ],
                ],
                'response' => 'SearchResponse',
                'response_description' => 'Search authorized clients, projects, invoices, tasks, native notes, discussions and comments; each kind additionally requires its read scope. Collaboration kinds require collaboration:read and exact audience authorization. Bounded to five results per kind.',
            ],
            'tasks.create' => [
                'method' => 'POST',
                'scopes' => ['tasks:write'],
                'description' => 'Create a task in a project whose tasks the caller may manage. Hidden from clients unless is_visible_to_client is true. Requires AGENT_API_WRITES_ENABLED.',
                'parameters' => ['WorkspaceId', 'ProjectId', 'IdempotencyKey'],
                'request' => 'TaskCreateRequest',
                'response' => 'TaskResponse',
                'response_description' => 'Task created',
                'errors' => self::ROUTED_WRITE_ERRORS['tasks.create'],
            ],
            'tasks.get' => [
                'method' => 'GET',
                'scopes' => ['tasks:read'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'TaskId'],
                'response' => 'TaskResponse',
                'response_description' => 'Authorized task',
            ],
            'tasks.list' => [
                'method' => 'GET',
                'scopes' => ['tasks:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('project_id', ['type' => 'string', 'format' => 'uuid']),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'TaskListResponse',
                'response_description' => 'Authorized tasks',
            ],
            'tasks.update' => [
                'method' => 'PATCH',
                'scopes' => ['tasks:write'],
                'description' => 'Update a task the caller may manage, using its current version. Omitted properties remain unchanged; explicit null clears nullable properties. Completing stamps completed_at once; reopening clears it. Requires AGENT_API_WRITES_ENABLED.',
                'parameters' => ['WorkspaceId', 'TaskId', 'IdempotencyKey'],
                'request' => 'TaskUpdateRequest',
                'response' => 'TaskResponse',
                'response_description' => 'Task updated',
                'errors' => self::ROUTED_WRITE_ERRORS['tasks.update'],
            ],
            'time_entries.approve' => [
                'method' => 'POST',
                'scopes' => ['time:approve'],
                'description' => false,
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'TimeApprovalRequest',
                'response' => 'ApprovalResponse',
                'response_description' => 'Time entries approved',
            ],
            'time_entries.delete' => [
                'method' => 'DELETE',
                'scopes' => ['time:write'],
                'description' => 'Soft-delete draft time, or approved time on a regenerable draft invoice (the invoice is regenerated in the same transaction), using its current version: the caller\'s own, or anyone\'s as a workspace owner or admin. Time on an issued, paid or void invoice is refused. Requires AGENT_API_TIME_ENTRY_WRITES_ENABLED.',
                'parameters' => ['WorkspaceId', 'TimeEntryId', 'IdempotencyKey'],
                'request' => 'ExpectedVersionRequest',
                'response' => 'DeletionResponse',
                'response_description' => 'Draft time soft-deleted',
                'errors' => self::ROUTED_WRITE_ERRORS['time_entries.delete'],
            ],
            'time_entries.list' => [
                'method' => 'GET',
                'scopes' => ['time:read'],
                'description' => false,
                'parameters' => [
                    'WorkspaceId',
                    self::query('project_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('status', ['$ref' => '#/components/schemas/TimeStatus']),
                    self::query('allocation_state', ['type' => 'string', 'enum' => ['unallocated', 'reserved', 'consumed']]),
                    self::query('is_billable', ['type' => 'boolean']),
                    [
                        'name' => 'compact',
                        'in' => 'query',
                        'description' => 'When true, both description fields are null; identifiers, allocation, minutes and permitted financial facts are unchanged.',
                        'schema' => ['type' => 'boolean', 'default' => false],
                    ],
                    [
                        'name' => 'unallocated',
                        'in' => 'query',
                        'description' => 'When true, selects exactly the approved, priced, billable, non-deferred unallocated work counted by operations.summary. allocation_state: unallocated with is_billable: true selects the same work; allocation_state alone classifies all rows, including drafts and deferred work.',
                        'schema' => ['type' => 'boolean', 'default' => false],
                    ],
                    self::query('from', ['type' => 'string', 'format' => 'date']),
                    self::query('to', ['type' => 'string', 'format' => 'date']),
                    'Limit',
                    'Cursor',
                ],
                'response' => 'TimeEntryListResponse',
                'response_description' => 'Authorized time entries',
            ],
            'time_entries.log' => [
                'method' => 'POST',
                'scopes' => ['time:write'],
                'description' => 'Atomically log up to 20 completed time entries on projects the caller may log time on. client_visible_description without is_visible_to_client makes the entry client-visible; send false to stage the client text privately. A billing rate requires time:approve and the approver role. approve: true approves the entries in the same transaction and receipt; it requires time:approve, the approver role on every project and AGENT_API_WRITES_ENABLED. Requires AGENT_API_TIME_ENTRY_WRITES_ENABLED.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey', self::readView('the written time entries exactly as time_entries.list presents them', 'time:read')],
                'request' => 'TimeLogRequest',
                'response' => 'TimeEntryBatchResponse',
                'response_description' => 'Time entries logged or replayed',
                'errors' => self::ROUTED_WRITE_ERRORS['time_entries.log'],
            ],
            'time_entries.unapprove' => [
                'method' => 'POST',
                'scopes' => ['time:approve', 'time:read'],
                'description' => 'Return approved time to draft as a workspace owner/admin. Draft-invoice allocations are released and the invoice regenerated atomically; issued/paid/void allocations are refused.',
                'parameters' => ['WorkspaceId', 'TimeEntryId', 'IdempotencyKey'],
                'request' => 'ExpectedVersionRequest',
                'response' => 'TimeEntryResponse',
                'response_description' => 'Time approval withdrawn or replayed',
            ],
            'time_entries.update' => [
                'method' => 'PATCH',
                'scopes' => ['time:write'],
                'description' => 'Update draft time, or approved time on a regenerable draft invoice, using its current version: the caller\'s own, or anyone\'s as a workspace owner or admin. Omitted properties remain unchanged; explicit null clears nullable properties. Requires AGENT_API_TIME_ENTRY_WRITES_ENABLED.',
                'parameters' => ['WorkspaceId', 'TimeEntryId', 'IdempotencyKey'],
                'request' => 'TimeUpdateRequest',
                'response' => 'TimeEntryResponse',
                'response_description' => 'Draft time updated',
                'errors' => self::ROUTED_WRITE_ERRORS['time_entries.update'],
            ],
            'workspaces.create' => [
                'method' => 'POST',
                'scopes' => ['workspaces:create'],
                'description' => 'Create a workspace owned by the actor. Fresh creation has no prior expected_version. Atomic actor/client/key reservation; replay rechecks current membership. Requires outer and workspace write flags.',
                'parameters' => ['IdempotencyKey'],
                'request' => 'WorkspaceCreateRequest',
                'response' => 'WorkspaceCreateResponse',
                'response_description' => 'Create a workspace owned by the actor. Fresh creation has no prior expected_version. Atomic actor/client/key reservation; replay rechecks current membership. Requires outer and workspace write flags.',
            ],
            ...self::square(),
            ...self::squareMirror(),
        ];
    }

    /**
     * The read-only Square pass-through (#438). Square is live, outside svc and
     * never changed by it; these reads exist so an agent, which cannot reach
     * Square itself, can find Square records to link to svc's own.
     *
     * @return array<string, array{method: string, scopes: list<string>, description: string, parameters: list<string|array<string, mixed>>, response: string, response_description: string}>
     */
    private static function square(): array
    {
        $common = ' Live, read-only Square data fetched on each call for finding Square records to link to svc records; svc never creates, changes or pays anything in Square, and linking is a separate svc operation. Workspace managers only, and only in the workspace Square is connected to; unavailable while Square is not configured. Square ids and cursors are opaque.';
        $id = static fn (string $name): array => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 192, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9:_-]*$']];
        $limit = static fn (int $max): array => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => 25]];
        $location = static fn (bool $required): array => ['name' => 'location_id', 'in' => 'query', 'required' => $required, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 192, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9:_-]*$']];
        $sortOrder = self::query('sort_order', ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc']);
        $operations = [
            'square.customers.get' => [
                'Get one Square customer by its Square id.',
                ['WorkspaceId', $id('customer')],
                'SquareCustomerResponse',
            ],
            'square.customers.list' => [
                'List Square customers in Square\'s default order, up to 100 per page, with cursor pagination.',
                ['WorkspaceId', $limit(100), 'Cursor'],
                'SquareCustomerListResponse',
            ],
            'square.customers.search' => [
                'Search Square customers by email address or phone number (at least one), matched fuzzily by default or exactly with match=exact. Square has no name search: to find a customer by name, page through square.customers.list or read the recipient on their Square invoices.',
                [
                    'WorkspaceId',
                    self::query('email', ['type' => 'string', 'minLength' => 1, 'maxLength' => 254]),
                    self::query('phone', ['type' => 'string', 'minLength' => 1, 'maxLength' => 32]),
                    self::query('match', ['type' => 'string', 'enum' => ['exact', 'fuzzy'], 'default' => 'fuzzy']),
                    $limit(100),
                    'Cursor',
                ],
                'SquareCustomerListResponse',
            ],
            'square.invoices.get' => [
                'Get one Square invoice by its Square id, with series_key and series_occurrence derived from its number.',
                ['WorkspaceId', ['name' => 'invoice', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => SquareIdentifiers::INVOICE_ID_MAX_LENGTH, 'pattern' => SquareIdentifiers::INVOICE_ID_SCHEMA_PATTERN]]],
                'SquareInvoiceResponse',
            ],
            'square.invoices.list' => [
                'List one Square location\'s invoices, up to 50 per page, with cursor pagination. series_key is the six-digit prefix every invoice of a recurring NNNNNN-R-NNNN series shares, and series_occurrence its suffix; both are null for a one-off invoice. An invoice a Square subscription generated also carries subscription_id. Amounts are in minor units.',
                ['WorkspaceId', $location(true), $limit(50), 'Cursor'],
                'SquareInvoiceListResponse',
            ],
            'square.invoices.search' => [
                'Search one Square location\'s invoices, optionally for one Square customer, by invoice date (newest first by default), up to 50 per page, with cursor pagination. Each invoice carries series_key and series_occurrence as in square.invoices.list.',
                ['WorkspaceId', $location(true), self::query('customer_id', ['type' => 'string', 'minLength' => 1, 'maxLength' => 192, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9:_-]*$']), $sortOrder, $limit(50), 'Cursor'],
                'SquareInvoiceListResponse',
            ],
            'square.locations.list' => [
                'List the connected Square account\'s locations; Square invoice reads take a location_id from here.',
                ['WorkspaceId'],
                'SquareLocationListResponse',
            ],
            'square.orders.get' => [
                'Get one Square order - the order_id on a Square invoice - with its line items, totals and tenders.',
                ['WorkspaceId', $id('order')],
                'SquareOrderResponse',
            ],
            'square.payments.get' => [
                'Get one Square payment by its Square id. Card details are limited to brand, type and last four digits.',
                ['WorkspaceId', $id('payment')],
                'SquarePaymentResponse',
            ],
            'square.payments.list' => [
                'List Square payments created within an RFC 3339 time range (Square defaults to the past year), optionally at one location (Square defaults to the main location), up to 50 per page, with cursor pagination. Card details are limited to brand, type and last four digits.',
                [
                    'WorkspaceId',
                    self::query('begin_time', ['type' => 'string', 'format' => 'date-time']),
                    self::query('end_time', ['type' => 'string', 'format' => 'date-time']),
                    $location(false),
                    $sortOrder,
                    $limit(50),
                    'Cursor',
                ],
                'SquarePaymentListResponse',
            ],
        ];
        $documented = [];
        foreach ($operations as $operationId => [$description, $parameters, $response]) {
            $documented[$operationId] = [
                'method' => 'GET',
                'scopes' => ['billing:read'],
                'description' => $description.$common,
                'parameters' => $parameters,
                'response' => $response,
                'response_description' => 'Result',
            ];
        }

        return $documented;
    }

    /**
     * svc's read-only mirror of Square (#438): refreshed by a scheduled sync
     * every 15 minutes or on request, and read without reaching Square.
     *
     * @return array<string, array{method: string, scopes: list<string>, description: string, parameters: list<string|array<string, mixed>>, request?: string, response: string, response_description: string}>
     */
    private static function squareMirror(): array
    {
        $common = ' Reads svc\'s mirror of the connected Square account, refreshed every 15 minutes and by square.sync; it never reaches Square, and meta.last_synced_at says how fresh it is. Workspace managers only, and only in the workspace Square is connected to; unavailable while Square is not configured. Amounts are in minor units.';
        $id = ['type' => 'string', 'minLength' => 1, 'maxLength' => 192, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9:_-]*$'];
        $seriesKey = ['type' => 'string', 'pattern' => '^[0-9]{6}$'];

        return [
            'square.mirror.invoices.list' => self::mirrorRead($common,
                'List mirrored Square invoices, filtered by location, status, Square customer, series_key and due-date range, up to 100 per page with cursor pagination. Each carries its series_key and occurrence, status, total, paid and next amounts, due date, Square\'s own reminder schedule, public_url (Square\'s view-and-pay page) and, once paid, its Square payment ids.',
                [
                    'WorkspaceId',
                    self::query('location_id', $id),
                    self::query('status', ['type' => 'string', 'enum' => ['DRAFT', 'UNPAID', 'SCHEDULED', 'PARTIALLY_PAID', 'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'CANCELED', 'FAILED', 'PAYMENT_PENDING']]),
                    self::query('customer_id', $id),
                    self::query('series_key', $seriesKey),
                    self::query('due_from', ['type' => 'string', 'format' => 'date']),
                    self::query('due_to', ['type' => 'string', 'format' => 'date']),
                    ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    'Cursor',
                ],
                'SquareMirrorInvoiceListResponse',
            ),
            'square.series.get' => self::mirrorRead($common,
                'Get one recurring Square series by its six-digit series_key, with every mirrored member by occurrence.',
                ['WorkspaceId', ['name' => 'series', 'in' => 'path', 'required' => true, 'schema' => $seriesKey]],
                'SquareSeriesResponse',
            ),
            'square.series.list' => self::mirrorRead($common,
                'List recurring Square series (invoices numbered NNNNNN-R-NNNN share the six-digit series_key), optionally for one location or Square customer, up to 50 per page with cursor pagination. Each series reports its customer, cadence read from the spacing of its due dates (weekly, monthly, quarterly, semi_annual, annual, irregular or unknown), latest amount and whether amounts vary, missing occurrence numbers, next due date, open amount and status counts.',
                [
                    'WorkspaceId',
                    self::query('location_id', $id),
                    self::query('customer_id', $id),
                    ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25]],
                    'Cursor',
                ],
                'SquareSeriesListResponse',
            ),
            'invoices.square_candidates' => self::mirrorRead($common,
                'Rank possible counterparts for this invoice with explainable scores capped at 100. Suggest at 70 or more, never auto-link. Candidates require a confirmed customer or an explicitly marked agreement series. Canceled invoices are excluded unless requested; other currencies and invoices linked elsewhere are excluded. Reports ambiguous top scores and a truncated pool.',
                ['WorkspaceId', 'InvoiceId', self::query('include_canceled', ['type' => 'boolean']), ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10]]],
                'SquareCandidateListResponse',
            ),
            'square.customers.unlinked' => self::mirrorRead($common,
                'List mirrored Square customers with invoices and no confirmed client mapping, with open balances and series keys. Optionally select customers with or without open invoices. Returns at most 500 customers and explicitly reports truncation.',
                ['WorkspaceId', self::query('with_open_balance', ['type' => 'boolean']), ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100]]],
                'SquareUnlinkedCustomerListResponse',
            ),
            'square.import_customer' => [
                'method' => 'POST',
                'scopes' => ['billing:write'],
                'description' => 'Workspace managers only. Explicitly import one mirrored Square customer as a client and confirm its mapping. Requires confirm: true and an idempotency key. Optionally mirror one of its series as a DRAFT agreement marked billed in Square; the series must have a supported cadence, one currency and an amount. Already mapped customers or assigned series return 409. Reads only the mirror and writes only svc; nothing is created, changed or paid in Square. No bulk or automatic import. A retry returns the same imported records.',
                'parameters' => ['WorkspaceId', ['name' => 'customer', 'in' => 'path', 'required' => true, 'schema' => $id], 'IdempotencyKey'],
                'request' => 'SquareCustomerImportRequest',
                'response' => 'SquareCustomerImportResponse',
                'response_description' => 'Imported client and optional draft agreement',
            ],
            'invoices.square_reconcile' => [
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'description' => 'Workspace managers only. Reconcile one linked invoice with Square now, rather than waiting for a webhook or the sweep every 15 minutes: the same read-only refetch of the Square invoice, its order and payments, then completed payments recorded through the received-payment service with provider square and the Square payment id, and refunds applied as corrections. Repeating it is safe: a Square payment is recorded once and refunds are cumulative. Square is read before the receipt is written, so a failed read or a refused payment leaves no receipt and the same key may be retried; a completed key returns the invoice as it now stands. Each call reads Square up to 102 times, so a workspace gets 20 per ten minutes, then 429 with Retry-After. An invoice with no active Square link is refused with 422, as are Square facts the ledger refuses (a draft or void invoice, a currency mismatch, an over-balance payment). Nothing is created, changed or paid in Square. Returns the invoice\'s Square state; read invoices.get for its balance.',
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareReconcileRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'The invoice after reconciliation',
            ],
            'square.sync' => [
                'method' => 'POST',
                'scopes' => ['billing:write'],
                'description' => 'Workspace managers only. Refresh svc\'s read-only mirror of the connected Square account now, rather than waiting for the scheduled sync every 15 minutes: locations, customers, every invoice with its series_key, status, amounts, due date, Square reminder schedule and public_url, and payment ids for paid invoices. Reads Square only; nothing is created, changed or paid in Square, and no svc invoice changes. Returns the run, which ends completed or failed with a reason; a few manual syncs are allowed per ten minutes. Repeating the same idempotency key returns the same run.',
                'parameters' => ['WorkspaceId', 'IdempotencyKey'],
                'request' => 'SquareSyncRequest',
                'response' => 'SquareSyncRunResponse',
                'response_description' => 'The sync run, completed or failed',
            ],
        ];
    }

    /**
     * The finance reconciliation operations (#457), which the Sanctum finance
     * token (`svc:auth:issue-token` with `finance.read` / `finance.reconcile`)
     * also calls, with the contract it has always had; see docs/finance-api.md.
     *
     * @return array<string, array{method: string, scopes: list<string>, description: string, parameters: list<string|array<string, mixed>>, request?: string, response: string, response_description: string, errors: array<int, string>}>
     */
    private static function finance(): array
    {
        $token = ' The Sanctum finance token issued by svc:auth:issue-token is accepted too, its finance.read and finance.reconcile abilities standing for these scopes.';
        $allocation = [
            'WorkspaceId',
            'PaymentId',
            ['name' => 'external_system', 'in' => 'path', 'required' => true, 'description' => 'The external finance system, as a lowercase slug.', 'schema' => ['type' => 'string', 'maxLength' => 80, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$']],
            ['name' => 'transaction', 'in' => 'path', 'required' => true, 'description' => 'The external finance system\'s transaction UUID.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
        ];
        $writeErrors = static fn (string $notFound, string $invalid): array => [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The caller is not a workspace owner or admin, or the token lacks finance:reconcile (the finance token, finance.reconcile).',
            404 => $notFound,
            422 => $invalid,
            429 => 'Too many requests (retry after Retry-After).',
        ];

        return [
            'finance.invoice_payments.list' => [
                'method' => 'GET',
                'scopes' => ['finance:read'],
                'description' => 'Received payments with what each is worth net of refunds, how much of that its active finance reconciliation allocations cover, and every allocation, active first; newest received first. Succeeded payments unless status is given; status=any returns every status the enum names, so a full refund (refunded) and a partial one (still succeeded) are both seen; a row with an unrecognised status is never returned. 50 a page unless per_page is (at most 100), numbered by page. For incremental sync, name updated_since, watermark or cursor instead of page, with status=any (any other status, received_from and received_to are refused there: a payment can change out of them): payments then come newest change first (updated_at, then id) inside a snapshot watermark that the first page of a pass fixes (now, or an earlier time given) and every page returns in meta; follow meta.next_cursor until it is null, then start the next pass with updated_since set to that watermark (inclusive; starting a few seconds earlier is harmless). Timestamps only find candidates, and consecutive passes overlap: deduplicate by (id, version), where version also moves when one of the payment\'s allocations changes. The embedded invoice and client are read-time snapshots, not change-tracked: a change to the invoice or client does not move the payment, so follow invoices.list and clients.list for them. Payments are never deleted. A processor payment also carries what the processor reports about settling it: its own payment identifier, its charge time (not received_on, which for Stripe is the day svc heard of it), its fee, and the payout it settled in with that payout\'s arrival date; finance.payouts.list lists the payouts. These move the payment\'s version when the processor reports them. Workspace owners/admins; with the finance token, any workspace member. Internal ids and payment notes are never returned.'.$token,
                'parameters' => [
                    'WorkspaceId',
                    self::query('status', ['type' => 'string', 'enum' => ['pending', 'succeeded', 'failed', 'refunded', 'disputed', 'canceled', 'any'], 'default' => 'succeeded']),
                    self::query('invoice', ['type' => 'string', 'format' => 'uuid']),
                    self::query('received_from', ['type' => 'string', 'format' => 'date']),
                    self::query('received_to', ['type' => 'string', 'format' => 'date']),
                    self::query('per_page', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]),
                    self::query('page', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                    'UpdatedSince',
                    'Watermark',
                    'Cursor',
                ],
                'response' => 'FinanceInvoicePaymentListResponse',
                'response_description' => 'A page of received payments with their allocations',
                'errors' => [
                    401 => 'Not signed in, or the token is missing, expired or revoked.',
                    403 => 'The caller may not read this workspace\'s payments, or the token lacks finance:read (the finance token, finance.read).',
                    404 => 'The workspace does not exist or the caller is not in it.',
                    422 => 'A filter is invalid (`errors` names it): an unknown status, a malformed invoice id or date, received_to before received_from, per_page out of range, a watermark in the future, or page on a change-feed request. Also a cursor from another workspace, query or watermark.',
                    429 => 'Too many requests (retry after Retry-After).',
                ],
            ],
            'finance.payouts.list' => [
                'method' => 'GET',
                'scopes' => ['finance:read'],
                'description' => 'The payment-processor payouts (Stripe, Square) that settled this workspace\'s payments: each payout\'s signed amount in minor units (negative when the payout took money from the bank account), currency, arrival date, type (automatic, manual or instant) and status (pending, in_transit, paid, failed or canceled); newest arrival first. Its member payments are the payments whose provider_payout_identifier names it, in finance.invoice_payments.list. Matching is net-only: a payout\'s amount is what reached the bank, and refunds, disputes or fees that land in a different payout, and manual or instant Stripe payouts (whose members Stripe does not list), are left for the finance system to treat as unexplained. A payout reported failed or canceled after it was reported paid stays so. 50 a page unless per_page is (at most 100), numbered by page. For incremental sync, name updated_since, watermark or cursor instead of page: the change feed works as on finance.invoice_payments.list (newest change first inside a snapshot watermark; deduplicate by (id, version)) and takes only the provider filter, since a payout\'s status and arrival date can change (status, arrival_from and arrival_to are refused there). Payouts are never deleted. Workspace owners/admins; with the finance token, any workspace member.'.$token,
                'parameters' => [
                    'WorkspaceId',
                    self::query('provider', ['type' => 'string', 'enum' => ['stripe', 'square']]),
                    self::query('status', ['type' => 'string', 'enum' => ['pending', 'in_transit', 'paid', 'failed', 'canceled']]),
                    self::query('arrival_from', ['type' => 'string', 'format' => 'date']),
                    self::query('arrival_to', ['type' => 'string', 'format' => 'date']),
                    self::query('per_page', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]),
                    self::query('page', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                    'UpdatedSince',
                    'Watermark',
                    'Cursor',
                ],
                'response' => 'FinancePayoutListResponse',
                'response_description' => 'A page of processor payouts',
                'errors' => [
                    401 => 'Not signed in, or the token is missing, expired or revoked.',
                    403 => 'The caller may not read this workspace\'s payouts, or the token lacks finance:read (the finance token, finance.read).',
                    404 => 'The workspace does not exist or the caller is not in it.',
                    422 => 'A filter is invalid (`errors` names it): an unknown provider or status, a malformed date, arrival_to before arrival_from, per_page out of range, a watermark in the future, page or a filter other than provider on a change-feed request. Also a cursor from another workspace, query or watermark.',
                    429 => 'Too many requests (retry after Retry-After).',
                ],
            ],
            'finance.processor_movements.list' => [
                'method' => 'GET',
                'scopes' => ['finance:read'],
                'description' => 'Read signed refunds, refund reversals, dispute debits, dispute credits, fees and fee reversals bound to their original payment. Money is signed integer minor units; net_minor equals principal_minor minus fee_minor. Occurrence facts retain the UTC processor timestamp and captured workspace local date/timezone. No processor account keys or raw source objects are exposed. Always a bounded change feed ordered by newest change and id, inside a snapshot watermark; follow meta.next_cursor until null, then use the watermark as inclusive updated_since and deduplicate by (id, version). Only immutable provider, kind, payment_id and occurrence-date filters are accepted; unknown filters, page and mutable review filters are refused. Workspace owners/admins; with the finance token, any workspace member.'.$token,
                'parameters' => [
                    'WorkspaceId',
                    self::query('provider', ['type' => 'string', 'enum' => ['stripe', 'square']]),
                    self::query('kind', ['type' => 'string', 'enum' => ProcessorMovementKind::all()]),
                    self::query('payment_id', ['type' => 'string', 'format' => 'uuid']),
                    self::query('occurred_from', ['type' => 'string', 'format' => 'date']),
                    self::query('occurred_to', ['type' => 'string', 'format' => 'date']),
                    self::query('per_page', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]),
                    'UpdatedSince', 'Watermark', 'Cursor',
                ],
                'response' => 'FinanceProcessorMovementListResponse',
                'response_description' => 'A cursor page of signed processor movements',
                'errors' => [
                    401 => 'Not signed in, or the token is missing, expired or revoked.',
                    403 => 'The caller may not read this workspace or lacks finance:read (the finance token, finance.read).',
                    404 => 'The workspace does not exist or the caller is not in it.',
                    422 => 'A filter is unsupported or invalid, or the cursor does not match the workspace, immutable filters or watermark.',
                    429 => 'Too many requests (retry after Retry-After).',
                ],
            ],
            'finance.reconciliations.acknowledge' => [
                'method' => 'PUT',
                'scopes' => ['finance:reconcile'],
                'description' => 'Explicitly affirm that the allocation remains correct after its processor refund or dispute review. Requires confirm: true, the current payment version from finance.invoice_payments.list, and the exact review reason and timestamp shown on the allocation. Clears only that current review; allocated money and active state are retained even above payment net or on a disputed/refunded payment. Does not resolve a dispute, restore a payment, issue credit or move money. The payment version advances so synchronizers see the cleared flag. Conditional and idempotent by effect: no Idempotency-Key or replay receipt; repeating the original request, any stale payment version, a changed review, or an already-cleared flag returns 409 and requires a fresh read. Owner/admin only.'.$token,
                'parameters' => $allocation,
                'request' => 'FinanceReconciliationAcknowledgementRequest',
                'response' => 'FinanceReconciliationResponse',
                'response_description' => 'The unchanged allocation with its current review cleared',
                'errors' => $writeErrors('The workspace, payment or allocation is not visible in this workspace.', 'Confirmation is not boolean true, review proof is missing or invalid, or an unknown body field is supplied.') + [
                    409 => 'The payment revision or review proof changed, or the review is already clear; refresh before acknowledging.',
                ],
            ],
            'finance.reconciliations.deactivate' => [
                'method' => 'DELETE',
                'scopes' => ['finance:reconcile'],
                'description' => 'Stop the allocation the path names counting toward its payment. It is kept, with its history, rather than deleted, the payment\'s version advances, and setting it again with finance.reconciliations.upsert and is_active true reactivates it. Idempotent by its path, so it takes no Idempotency-Key. Workspace owners/admins only.'.$token,
                'parameters' => $allocation,
                'response' => 'FinanceReconciliationResponse',
                'response_description' => 'The allocation, no longer active',
                'errors' => $writeErrors(
                    'The workspace does not exist or the caller is not in it; the payment or allocation does not exist in this workspace; or the system or transaction is not a slug and a UUID.',
                    'external_system is longer than 80 characters (`errors` names external_system_slug).',
                ),
            ],
            'finance.reconciliations.upsert' => [
                'method' => 'PUT',
                'scopes' => ['finance:reconcile'],
                'description' => 'Create or update the finance reconciliation allocation the path names: the share of a received payment, in integer minor units of its currency, that one transaction in an external finance system covers. One transaction may cover several payments and one payment several transactions, but a payment\'s active allocations never exceed what it is worth net of refunds, and only a succeeded payment can be reconciled. Answers 201 when it created the allocation and 200 when it updated it. is_active is required through OAuth and MCP (the finance token may omit it, keeping the allocation\'s state), so a delayed retry never reactivates an allocation withdrawn since: send the state wanted now. Any change advances the payment\'s version. Idempotent by its path, so it takes no Idempotency-Key. Nothing moves money. Workspace owners/admins only.'.$token,
                'parameters' => $allocation,
                'request' => 'FinanceReconciliationRequest',
                'response' => 'FinanceReconciliationResponse',
                'response_description' => 'The allocation, updated (201 when this created it)',
                'errors' => $writeErrors(
                    'The workspace does not exist or the caller is not in it; the payment does not exist in this workspace; or the system or transaction is not a slug and a UUID.',
                    'A field is invalid (`errors` names it, or external_system_slug for the path), or the payment is not succeeded, its currency differs, or the active allocations would exceed its amount net of refunds.',
                ),
            ],
        ];
    }

    /**
     * @param  list<string|array<string, mixed>>  $parameters
     * @return array{method: string, scopes: list<string>, description: string, parameters: list<string|array<string, mixed>>, response: string, response_description: string}
     */
    private static function mirrorRead(string $common, string $description, array $parameters, string $response): array
    {
        return [
            'method' => 'GET',
            'scopes' => ['billing:read'],
            'description' => $description.$common,
            'parameters' => $parameters,
            'response' => $response,
            'response_description' => 'Result',
        ];
    }

    /**
     * Reusable parameters, under `components/parameters`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function parameters(): array
    {
        return [
            'ExpenseId' => [
                'name' => 'expense',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'PaymentId' => [
                'name' => 'payment',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'WorkspaceId' => [
                'name' => 'workspace',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'ProjectId' => [
                'name' => 'project',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'TaskId' => [
                'name' => 'task',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'TimeEntryId' => [
                'name' => 'entry',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'InvoiceId' => [
                'name' => 'invoice',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ],
            'UpdatedSince' => [
                'name' => 'updated_since',
                'in' => 'query',
                'description' => 'Only records changed at or after this time (inclusive): the previous pass\'s watermark. Naming updated_since or watermark switches a listing that otherwise pages to change order - newest change first inside a snapshot watermark returned in meta - and a cursor from one order is refused in the other. Consecutive passes overlap by design (see watermark), so deduplicate by (id, version). A change feed takes only filters on what a record never changes - invoices.list: company_id and invoice_kind; clients.list: none; finance.invoice_payments.list: invoice, with status=any; finance.payouts.list: provider - and refuses any other with a 422 naming it, since a record that changes out of a filter would never be delivered again.',
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'Watermark' => [
                'name' => 'watermark',
                'in' => 'query',
                'description' => 'The pass\'s snapshot upper bound. Fixed by its first page - 120 seconds before the request when omitted, and never later than that - and returned by every page; with a cursor, omit it or send the same one. The lag outlasts any write\'s transaction, so a record stamped before a pass started but committed after it read is in the next pass; records in that overlap arrive twice.',
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'TokenId' => [
                'name' => 'token',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            ],
            'Limit' => [
                'name' => 'limit',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            ],
            'Cursor' => [
                'name' => 'cursor',
                'in' => 'query',
                'schema' => ['type' => 'string', 'maxLength' => 2048],
            ],
            'IdempotencyKey' => [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            ],
        ];
    }

    /**
     * `?view=read` on a write ({@see ReadView}).
     *
     * @return array<string, mixed>
     */
    private static function readView(string $answer, string $scope): array
    {
        return [
            'name' => ReadView::PARAMETER,
            'in' => 'query',
            'description' => "Send `read` to receive {$answer}, when the token also holds {$scope}; without that scope the response keeps the write shape. Any other value is refused before anything is written, and the view is not part of the request an Idempotency-Key binds.",
            'schema' => ['type' => 'string', 'enum' => [ReadView::READ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function filePageLimit(): array
    {
        return ['name' => 'limit', 'in' => 'query', 'required' => false,
            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100]];
    }

    /** @return array<string, mixed> */
    private static function filePageCursor(): array
    {
        return ['name' => 'cursor', 'in' => 'query', 'required' => false,
            'schema' => ['type' => 'string', 'maxLength' => 4096]];
    }

    /** @return array<string, mixed> */
    private static function uuidPath(string $name): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function query(string $name, array $schema): array
    {
        return ['name' => $name, 'in' => 'query', 'schema' => $schema];
    }
}
