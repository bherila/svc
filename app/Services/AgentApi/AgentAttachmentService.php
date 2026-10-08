<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Files\AttachmentAction;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\WorkspaceClock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Generic files exclude expenses, whose receipts have their own scope and routes. */
final class AgentAttachmentService
{
    public function __construct(private readonly AgentAccess $access, private readonly AttachmentRecordResolver $records,
        private readonly AttachmentStorageService $storage, private readonly AttachmentAction $action,
        private readonly AgentMutationExecutor $mutations, private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function authorize(User|AgentPrincipal $user, Workspace $workspace, bool $write = false): void
    {
        if ($write) {
            abort_unless(AgentWriteCutover::files(), 404);
        }
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_values(array_diff(AttachmentRecordResolver::allowedTypes(), ['expense']));
    }

    public function validateRecord(string $type, string $id): void
    {
        Validator::make(['record_type' => $type, 'record_id' => $id], [
            'record_type' => ['required', Rule::in(self::types())], 'record_id' => ['required', 'uuid'],
        ])->validate();
    }

    /** @return array<string,mixed> */
    public function listing(User|AgentPrincipal $user, Workspace $workspace, string $type, string $id): array
    {
        $this->authorize($user, $workspace);
        $this->validateRecord($type, $id);
        $record = $this->records->resolve($workspace, $type, $id);
        $rows = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('record_type', $type)
            ->where('record_public_id', $id)->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->orderByDesc('id')->limit(100)->get();

        return ['data' => $rows->map(fn (ClientAttachment $row): array => $this->present($row))->all(),
            'parent_version' => AgentApiVersion::for($record)];
    }

    /** @return array<string,mixed> */
    public function metadata(User|AgentPrincipal $user, Workspace $workspace, string $id): array
    {
        $this->authorize($user, $workspace);

        return ['data' => $this->present($this->find($workspace, $id))];
    }

    /** @return array<string,mixed> */
    public function downloadUrl(User|AgentPrincipal $user, Workspace $workspace, string $id): array
    {
        $this->authorize($user, $workspace);
        $attachment = $this->find($workspace, $id);
        abort_unless($attachment->lifecycle_state === ClientAttachment::STATE_AVAILABLE, 404);

        return ['data' => ['url' => $this->signed('attachments.content', ['workspace' => $workspace, 'attachment' => $id]),
            'expires_in' => 600, 'method' => 'GET']];
    }

    public function download(User|AgentPrincipal $user, Workspace $workspace, string $id): StreamedResponse
    {
        $this->authorize($user, $workspace);

        return $this->storage->download($this->find($workspace, $id));
    }

    /** URL preparation is read-only. The multipart request reserves the mutation receipt.
     * @return array<string,mixed> */
    public function uploadUrl(User|AgentPrincipal $user, Workspace $workspace, string $type, string $id): array
    {
        $this->authorize($user, $workspace, true);
        $this->validateRecord($type, $id);
        $record = $this->records->resolve($workspace, $type, $id);

        return ['data' => ['url' => $this->signed('attachments.upload', ['workspace' => $workspace,
            'recordType' => $type, 'recordPublicId' => $id]), 'expires_in' => 600, 'method' => 'POST',
            'expected_version' => AgentApiVersion::for($record), 'max_bytes' => 52428800,
            'file_field' => 'file', 'version_field' => 'expected_version', 'idempotency_header' => 'Idempotency-Key']];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function upload(User $user, Workspace $workspace, string $clientId, string $key, string $type, string $id,
        UploadedFile $file, array $payload): array
    {
        $this->authorize($user, $workspace, true);
        $this->validateRecord($type, $id);
        /** @var array{expected_version:string} $data */
        $data = Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64'],
            'file' => ['required', 'file', 'max:51200']])->validate();
        $digest = hash_file('sha256', $file->getPathname());
        abort_unless(is_string($digest), 422);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $parent = $this->records->resolve($workspace, $type, $id);
        $prepared = $this->storage->prepareForPublication($workspace, $parent, $file, $user);
        try {
            $ids = $this->mutations->run($user, $workspace, $clientId, 'attachments.upload', $key,
                ['record_type' => $type, 'record_id' => $id, 'expected_version' => $data['expected_version'],
                    'filename' => $file->getClientOriginalName(), 'bytes' => $file->getSize(), 'sha256' => $digest,
                    'media_type' => $file->getMimeType()],
                function () use ($workspace, $type, $id, $user, $data, $prepared): array {
                    $this->authorize($user, $workspace, true);
                    $published = $this->action->publishPrepared($workspace, $type, $id, $prepared, $data['expected_version']);

                    return [$published->public_id];
                }, function (array $ids) use ($user, $workspace): void {
                    $this->authorize($user, $workspace, true);
                    $this->find($workspace, $ids[0]);
                });
        } catch (Throwable $exception) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
            throw $exception;
        }
        if ($ids[0] !== $prepared->public_id) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
        }

        return ['data' => $this->present($this->find($workspace, $ids[0]))];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function delete(User $user, Workspace $workspace, string $clientId, string $key, string $id, array $payload): array
    {
        $this->authorize($user, $workspace, true);
        $ids = $this->mutations->run($user, $workspace, $clientId, 'attachments.delete', $key,
            ['attachment_id' => $id, ...$payload], function () use ($workspace, $id, $payload): array {
                $this->find($workspace, $id);
                /** @var array{expected_version:string,confirm:true} $data */
                $data = Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64'],
                    'confirm' => ['required', 'accepted', Rule::in([true])]])->validate();

                abort_unless(($payload['confirm'] ?? null) === true, 422, 'Explicit confirm true is required.');

                return [$this->action->delete($workspace, $id, $data['expected_version'])->public_id];
            }, function (array $ids) use ($user, $workspace): void {
                $this->authorize($user, $workspace, true);
                $this->find($workspace, $ids[0]);
            });

        return ['data' => $this->present($this->find($workspace, $ids[0]))];
    }

    private function find(Workspace $workspace, string $id): ClientAttachment
    {
        $attachment = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('public_id', $id)
            ->whereIn('record_type', self::types())->firstOrFail();
        $this->records->resolve($workspace, $attachment->record_type, $attachment->record_public_id);

        return $attachment;
    }

    /** @return array<string,mixed> */
    private function present(ClientAttachment $attachment): array
    {
        return ['id' => $attachment->public_id, 'record_type' => $attachment->record_type,
            'record_id' => $attachment->record_public_id, 'filename' => $attachment->original_filename,
            'media_type' => $attachment->media_type, 'bytes' => $attachment->bytes, 'sha256' => $attachment->sha256,
            'status' => $attachment->lifecycle_state, 'version' => AgentApiVersion::for($attachment)];
    }

    /** Relative signatures survive the in-process MCP transport's host; expose the configured public host.
     * @param array<string,mixed> $parameters */
    private function signed(string $name, array $parameters): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('agent-api.v1.'.$name, $this->clock->now()->addMinutes(10), $parameters, false);
    }
}
