<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Files\AttachmentAction;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentMetadata;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentStorageService;
use App\Services\Files\AttachmentUploadPolicy;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\CursorPage;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly AttachmentReader $reader, private readonly AgentMutationExecutor $mutations, private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function authorize(User|AgentPrincipal $user, Workspace $workspace, bool $write = false, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): void
    {
        if ($write && $origin === AttachmentMutationOrigin::Agent) {
            abort_unless(AgentWriteCutover::files(), 404);
        }
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
        if ($write && $origin === AttachmentMutationOrigin::Agent) {
            abort_if($this->access->isWorkspaceClient($user, $workspace), 403);
        }
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_values(array_diff(AttachmentRecordResolver::readableTypes(), ['expense']));
    }

    /** @return list<string> */
    public static function uploadableTypes(): array
    {
        return array_values(array_diff(AttachmentRecordResolver::uploadableTypes(), ['expense']));
    }

    public function validateRecord(string $type, string $id, bool $write = false): void
    {
        Validator::make(['record_type' => $type, 'record_id' => $id], [
            'record_type' => ['required', Rule::in($write ? self::uploadableTypes() : self::types())], 'record_id' => ['required', 'uuid'],
        ])->validate();
    }

    /** @return array<string,mixed> */
    public function listing(User|AgentPrincipal $user, Workspace $workspace, string $type, string $id, ?AttachmentPageQuery $page = null, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->authorize($user, $workspace, origin: $origin);
        $this->validateRecord($type, $id);
        $record = $this->records->resolve($workspace, $type, $id);
        $page ??= AttachmentPageQuery::fromInput([]);
        $audience = $this->audience($user, $workspace, $origin);
        $query = $this->reader->forParent($user, $workspace, $type, $id, $audience);
        $result = CursorPage::run($workspace, 'attachments:'.$type.':'.$id.':'.$user->public_id.':'.$audience->name.':available:id-desc', $page->limit, $page->cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn (Builder $rows) => $rows->where('id', '<', $after))
                ->orderByDesc('id')->limit($take)->get(), $this->present(...), maxDataBytes: AgentPageBudget::dataBytes(), allowLegacyCursor: false);

        return [...$result, 'parent_version' => AgentApiVersion::for($record)];
    }

    /** @return array<string,mixed> */
    public function metadata(User|AgentPrincipal $user, Workspace $workspace, string $id, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->authorize($user, $workspace, origin: $origin);

        return ['data' => $this->present($this->find($user, $workspace, $id, $origin))];
    }

    /** @return array<string,mixed> */
    public function downloadUrl(User|AgentPrincipal $user, Workspace $workspace, string $id, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->authorize($user, $workspace, origin: $origin);
        $attachment = $this->find($user, $workspace, $id, $origin);
        abort_unless($attachment->lifecycle_state === ClientAttachment::STATE_AVAILABLE, 404);

        return ['data' => ['url' => $this->signed('attachments.content', ['workspace' => $workspace, 'attachment' => $id]),
            'expires_in' => 600, 'method' => 'GET']];
    }

    public function download(User|AgentPrincipal $user, Workspace $workspace, string $id, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): StreamedResponse
    {
        $this->authorize($user, $workspace, origin: $origin);

        return $this->storage->download($this->find($user, $workspace, $id, $origin));
    }

    /** URL preparation is read-only. The multipart request reserves the mutation receipt.
     * @return array<string,mixed> */
    public function uploadUrl(User|AgentPrincipal $user, Workspace $workspace, string $type, string $id, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->authorize($user, $workspace, true, $origin);
        AttachmentUploadPolicy::defaultVisibility();
        $this->validateRecord($type, $id, true);
        $record = $this->records->resolve($workspace, $type, $id);

        return ['data' => ['url' => $this->signed('attachments.upload', ['workspace' => $workspace,
            'recordType' => $type, 'recordPublicId' => $id]), 'expires_in' => 600, 'method' => 'POST',
            'expected_version' => AgentApiVersion::for($record), 'max_bytes' => 52428800,
            'file_field' => 'file', 'version_field' => 'expected_version', 'idempotency_header' => 'Idempotency-Key']];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function upload(User $user, Workspace $workspace, string $clientId, string $key, string $type, string $id,
        UploadedFile $file, array $payload, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->authorize($user, $workspace, true, $origin);
        $this->validateRecord($type, $id, true);
        AttachmentUploadPolicy::validateLegacyPayload($payload);
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
                function () use ($workspace, $type, $id, $user, $data, $prepared, $origin): array {
                    $this->authorize($user, $workspace, true, $origin);
                    $published = $this->action->publishPrepared($workspace, $type, $id, $prepared, $data['expected_version'], $user, $origin);

                    return [$published->public_id];
                }, function (array $ids) use ($user, $workspace, $type, $id, $origin): void {
                    $this->authorize($user, $workspace, true, $origin);
                    $this->action->lockAgentParent($workspace, $type, $id, $user, $origin);
                    $this->find($user, $workspace, $ids[0], $origin);
                });
        } catch (Throwable $exception) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
            throw $exception;
        }
        if ($ids[0] !== $prepared->public_id) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
        }

        return ['data' => $this->present($this->find($user, $workspace, $ids[0], $origin))];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function delete(User $user, Workspace $workspace, string $clientId, string $key, string $id, array $payload, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->authorize($user, $workspace, true, $origin);
        $ids = $this->mutations->run($user, $workspace, $clientId, 'attachments.delete', $key,
            ['attachment_id' => $id, ...$payload], function () use ($user, $workspace, $id, $payload, $origin): array {
                $this->find($user, $workspace, $id, $origin);
                /** @var array{expected_version:string,confirm:true} $data */
                $data = Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64'],
                    'confirm' => ['required', 'accepted', Rule::in([true])]])->validate();

                abort_unless(($payload['confirm'] ?? null) === true, 422, 'Explicit confirm true is required.');

                return [$this->action->delete($workspace, $id, $data['expected_version'], $user, $origin)->public_id];
            }, function (array $ids) use ($user, $workspace, $origin): void {
                $this->authorize($user, $workspace, true, $origin);
                $file = $this->find($user, $workspace, $ids[0], $origin);
                $this->action->lockAgentParent($workspace, $file->record_type, $file->record_public_id, $user, $origin);
            });

        return ['data' => $this->present($this->find($user, $workspace, $ids[0], $origin))];
    }

    private function find(User|AgentPrincipal $user, Workspace $workspace, string $id, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): ClientAttachment
    {
        $attachment = $this->reader->query($user, $workspace, $this->audience($user, $workspace, $origin))->where('public_id', $id)
            ->whereIn('record_type', self::types())->firstOrFail();
        $this->records->resolve($workspace, $attachment->record_type, $attachment->record_public_id);

        return $attachment;
    }

    /** @return array<string,mixed> */
    private function audience(User|AgentPrincipal $user, Workspace $workspace, AttachmentMutationOrigin $origin): AttachmentAudience
    {
        return $origin === AttachmentMutationOrigin::Native ? AttachmentAudience::Internal : $this->reader->agentAudience($user, $workspace);
    }

    private function present(ClientAttachment $attachment): array
    {
        return AttachmentMetadata::for($attachment);
    }

    /** Relative signatures survive the in-process MCP transport's host; expose the configured public host.
     * @param array<string,mixed> $parameters */
    private function signed(string $name, array $parameters): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('agent-api.v1.'.$name, $this->clock->now()->addMinutes(10), $parameters, false);
    }
}
