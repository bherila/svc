<?php

namespace App\Services\AgentApi\Operations;

/** Scoped collaboration files share the same domain actions across transports. */
final class AttachmentSharingOperations
{
    private const string ROOT = '/workspaces/{workspace}/clients/{client}/collaboration/attachments/{recordType}/{recordPublicId}';

    public const array ROUTES = [
        'attachments.visibility.get' => ['attachments.visibility.show', '/workspaces/{workspace}/attachments/{attachment}/visibility', [200]],
        'attachments.visibility.update' => ['attachments.visibility.update', '/workspaces/{workspace}/attachments/{attachment}/visibility', [200]],
        'collaboration.attachments.list' => ['collaboration.attachments.index', self::ROOT, [200]],
        'collaboration.attachments.download_url' => ['collaboration.attachments.download-url', self::ROOT.'/{attachment}/download-url', [200]],
        'collaboration.attachments.download' => ['collaboration.attachments.content', self::ROOT.'/{attachment}/content', [200]],
        'collaboration.attachments.upload_url' => ['collaboration.attachments.upload-url', self::ROOT.'/upload-url', [200]],
        'collaboration.attachments.upload' => ['collaboration.attachments.upload', self::ROOT.'/upload', [200]],
        'collaboration.attachments.visibility.update' => ['collaboration.attachments.visibility.update', self::ROOT.'/{attachment}/visibility', [200]],
        'collaboration.attachments.delete' => ['collaboration.attachments.destroy', self::ROOT.'/{attachment}', [200]],
    ];

    /** @return array<string,array{method:string,scopes:list<string>,summary:string,description:string,parameters:list<string|array<string,mixed>>,errors:array<int,string>,response?:string,request?:string,request_types?:list<string>,binary?:array{0:string,1:string}}> */
    public static function documentation(): array
    {
        $definitions = [
            'attachments.visibility.get' => ['GET', 'Read attachment sharing', 'AttachmentVisibilityResponse', null],
            'attachments.visibility.update' => ['PATCH', 'Change attachment sharing', 'AttachmentVisibilityResponse', 'AttachmentVisibilityRequest'],
            'collaboration.attachments.list' => ['GET', 'List collaboration files', 'CollaborationAttachmentListResponse', null],
            'collaboration.attachments.download_url' => ['POST', 'Prepare a collaboration file download', 'AttachmentDownloadUrlResponse', null],
            'collaboration.attachments.download' => ['GET', 'Download a collaboration file', null, null],
            'collaboration.attachments.upload_url' => ['POST', 'Prepare a collaboration file upload', 'CollaborationAttachmentUploadUrlResponse', null],
            'collaboration.attachments.upload' => ['POST', 'Upload a collaboration file', 'CollaborationAttachmentResponse', 'CollaborationAttachmentUploadRequest'],
            'collaboration.attachments.visibility.update' => ['PATCH', 'Change collaboration file sharing', 'AttachmentVisibilityResponse', 'AttachmentVisibilityRequest'],
            'collaboration.attachments.delete' => ['DELETE', 'Delete a collaboration file', 'CollaborationAttachmentResponse', 'CollaborationAttachmentDeleteRequest'],
        ];
        $result = [];
        foreach ($definitions as $id => [$method, $summary, $response, $request]) {
            $collaboration = str_starts_with($id, 'collaboration.');
            $write = self::writes($id);
            $scopes = [$write ? 'files:write' : 'files:read'];
            if (str_ends_with($id, 'visibility.update')) {
                $scopes[] = 'files:read';
            }
            if ($collaboration) {
                $scopes[] = $write ? 'collaboration:write' : 'collaboration:read';
            }
            $parameters = ['WorkspaceId'];
            if ($collaboration) {
                $parameters[] = ['name' => 'client', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
                $parameters[] = ['name' => 'recordType', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['topic', 'comment']]];
                $parameters[] = ['name' => 'recordPublicId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
            }
            if (str_contains(self::ROUTES[$id][1], '{attachment}')) {
                $parameters[] = ['name' => 'attachment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
            }
            if ($id === 'collaboration.attachments.list') {
                $parameters[] = ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100]];
                $parameters[] = ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 4096]];
            }
            if ($write && ! str_ends_with($id, 'upload_url')) {
                $parameters[] = 'IdempotencyKey';
            }
            if (in_array($id, ['collaboration.attachments.download', 'collaboration.attachments.upload'], true)) {
                $parameters[] = ['name' => 'expires', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']];
                $parameters[] = ['name' => 'signature', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
            }
            $result[$id] = [
                'method' => $method, 'scopes' => $scopes, 'summary' => $summary,
                'description' => ($collaboration
                    ? 'Current exact company, parent and file access is required. Portal audiences see only effectively shared files. Publishing requires a current workspace owner/admin or project owner/manager; Agent identities with Portal access cannot write. '
                    : 'Workspace managers only, on the six existing generic upload parent types. Exact current parent and file access is required. ')
                    .($write ? 'Attachment sharing and the retained privacy latch must be enabled. Uploads start private. Mutations reauthorize current roles and captured versions, including receipt replay. File sharing never changes parent content or sharing.'
                        : 'This read remains available when sharing writers are disabled and performs no durable mutation.'),
                'parameters' => $parameters,
                'errors' => [401 => 'Authentication is missing, expired or revoked.', 403 => 'Current scope or role is insufficient.',
                    404 => 'The workspace, company, parent or file is unavailable, or the writer is disabled.',
                    409 => 'A captured version is stale or the idempotency key was reused for different input.',
                    422 => 'A field, confirmation, cursor or file is invalid.', 429 => 'Too many requests; retry after Retry-After.',
                    503 => 'The retained local privacy latch is unavailable; no write was performed.'],
            ] + ($response === null ? ['binary' => ['*/*', 'Authorized attachment content.']] : ['response' => $response])
                + ($id === 'collaboration.attachments.upload' ? ['request_types' => ['multipart/form-data']] : [])
                + ($request === null ? [] : ['request' => $request]);
        }

        return $result;
    }

    public static function writes(string $id): bool
    {
        return in_array($id, ['attachments.visibility.update', 'collaboration.attachments.upload_url',
            'collaboration.attachments.upload', 'collaboration.attachments.visibility.update', 'collaboration.attachments.delete'], true);
    }
}
