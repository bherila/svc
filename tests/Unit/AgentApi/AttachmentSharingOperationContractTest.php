<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AttachmentSharingOperations;
use App\Services\Mcp\AgentMcpAttachmentSharingTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttachmentSharingOperations::class)]
#[CoversClass(AgentOperationCatalog::class)]
#[CoversClass(AgentDeploymentFlags::class)]
final class AttachmentSharingOperationContractTest extends TestCase
{
    public function test_closed_file_protocol_preserves_reader_scopes_writer_gates_and_transport_boundaries(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $documents = AttachmentSharingOperations::documentation();
            $root = '/workspaces/{workspace}/clients/{client}/collaboration/attachments/{recordType}/{recordPublicId}';
            $legacy = '/workspaces/{workspace}/attachments/{attachment}/visibility';
            $cases = [
                'attachments.visibility.get' => ['GET', $legacy, 'visibilityGet', false, 'AttachmentVisibilityResponse', null, Effect::Read],
                'attachments.visibility.update' => ['PATCH', $legacy, 'visibilityUpdate', true, 'AttachmentVisibilityResponse', 'AttachmentVisibilityRequest', Effect::LocalWrite],
                'collaboration.attachments.list' => ['GET', $root, 'collaborationList', false, 'CollaborationAttachmentListResponse', null, Effect::Read],
                'collaboration.attachments.download_url' => ['POST', $root.'/{attachment}/download-url', 'collaborationDownloadUrl', false, 'AttachmentDownloadUrlResponse', null, Effect::Read],
                'collaboration.attachments.download' => ['GET', $root.'/{attachment}/content', null, false, null, null, Effect::Download],
                'collaboration.attachments.upload_url' => ['POST', $root.'/upload-url', 'collaborationUploadUrl', true, 'CollaborationAttachmentUploadUrlResponse', null, Effect::LocalWrite],
                'collaboration.attachments.upload' => ['POST', $root.'/upload', null, true, 'CollaborationAttachmentResponse', 'CollaborationAttachmentUploadRequest', Effect::Upload],
                'collaboration.attachments.visibility.update' => ['PATCH', $root.'/{attachment}/visibility', 'collaborationVisibilityUpdate', true, 'AttachmentVisibilityResponse', 'AttachmentVisibilityRequest', Effect::LocalWrite],
                'collaboration.attachments.delete' => ['DELETE', $root.'/{attachment}', 'collaborationDelete', true, 'CollaborationAttachmentResponse', 'CollaborationAttachmentDeleteRequest', Effect::Destructive],
            ];
            $this->assertSame(array_keys($cases), array_keys($documents));
            $toolCount = 0;
            foreach ($cases as $id => [$method, $path, $handler, $write, $response, $request, $effect]) {
                $scoped = str_starts_with($id, 'collaboration.');
                $visibility = str_ends_with($id, 'visibility.update');
                $upload = $id === 'collaboration.attachments.upload';
                $download = $id === 'collaboration.attachments.download';
                $preparation = $id === 'collaboration.attachments.upload_url';
                $document = $documents[$id];
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($write, AttachmentSharingOperations::writes($id), $id);
                $this->assertSame($method, $document['method'], $id);
                $this->assertSame($method, $operation->rest->method, $id);
                $this->assertSame($path, $operation->rest->path, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $scopes = [$write ? 'files:write' : 'files:read'];
                if ($visibility) {
                    $scopes[] = 'files:read';
                }
                if ($scoped) {
                    $scopes[] = $write ? 'collaboration:write' : 'collaboration:read';
                }
                $this->assertSame($scopes, $document['scopes'], $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $parameters = ['WorkspaceId'];
                if ($scoped) {
                    $parameters[] = ['name' => 'client', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
                    $parameters[] = ['name' => 'recordType', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['topic', 'comment']]];
                    $parameters[] = ['name' => 'recordPublicId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
                }
                if (str_contains($path, '{attachment}')) {
                    $parameters[] = ['name' => 'attachment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
                }
                if ($id === 'collaboration.attachments.list') {
                    $parameters[] = ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100]];
                    $parameters[] = ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 4096]];
                }
                if ($write && ! $preparation) {
                    $parameters[] = 'IdempotencyKey';
                }
                if ($upload || $download) {
                    $parameters[] = ['name' => 'expires', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']];
                    $parameters[] = ['name' => 'signature', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
                }
                $this->assertSame($parameters, $document['parameters'], $id);
                $this->assertSame([401, 403, 404, 409, 422, 429, 503], array_keys($document['errors']), $id);
                if ($download) {
                    $this->assertSame(['application/octet-stream', 'Authorized attachment content.'], $document['binary']);
                    $this->assertArrayNotHasKey('response', $document);
                } else {
                    $this->assertSame($response, $document['response']);
                    $this->assertArrayNotHasKey('binary', $document);
                }
                if ($request === null) {
                    $this->assertArrayNotHasKey('request', $document);
                } else {
                    $this->assertSame($request, $document['request']);
                }
                if ($upload) {
                    $this->assertSame(['multipart/form-data'], $document['request_types']);
                } else {
                    $this->assertArrayNotHasKey('request_types', $document);
                }
                $this->assertStringContainsString($scoped ? 'Current exact company, parent and file access is required.' : 'Workspace managers only, on the six existing generic upload parent types.', $document['description']);
                $this->assertStringContainsString($write ? 'Uploads start private.' : 'This read remains available when sharing writers are disabled', $document['description']);
                $flags = $write ? [AgentDeploymentFlags::FILES, ...($scoped ? [AgentDeploymentFlags::COLLABORATION] : []), AgentDeploymentFlags::ATTACHMENT_SHARING] : [];
                if ($handler === null) {
                    $this->assertNull($operation->mcp, $id);
                } else {
                    $toolCount++;
                    $this->assertSame([AgentMcpAttachmentSharingTools::class, $handler], $operation->mcp->handler, $id);
                    $this->assertSame(! $scoped, $catalog->isManagerOnly($id), $id);
                    $this->assertTrue($operation->isIdempotent(), $id);
                    $flags = [...$flags, 'mcp', 'mcp.switch:mcp.'.($write ? 'write' : 'read'), 'mcp.switch:'.$id];
                }
                $this->assertSame($flags, $operation->requirement->flags, $id);
                $this->assertSame($write && ! $preparation, $operation->safety->expectedVersion, $id);
                $this->assertSame($id === 'collaboration.attachments.delete', $operation->safety->confirm, $id);
                $this->assertSame($write && ! $preparation ? ($handler === null ? IdempotencyKey::Header : IdempotencyKey::HeaderAndArgument) : IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
            }
            $this->assertSame(7, $toolCount);
            // Availability may appear before nested Agent cutovers, and must not erase them.
            $this->assertSame(['SVC_ATTACHMENT_SHARING_WRITES_ENABLED', 'AGENT_API_WRITES_ENABLED', 'AGENT_API_FILE_WRITES_ENABLED', 'AGENT_API_COLLABORATION_WRITES_ENABLED'],
                AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::ATTACHMENT_SHARING, AgentDeploymentFlags::FILES, AgentDeploymentFlags::COLLABORATION]));
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
