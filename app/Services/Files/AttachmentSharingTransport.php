<?php

namespace App\Services\Files;

use App\Models\AgentMutationReceipt;
use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAttachmentService;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\CursorPage;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Shared receipt orchestration. Origin and audience are selected by trusted adapters. */
final class AttachmentSharingTransport
{
    public function __construct(private readonly AttachmentSharingAction $action, private readonly AttachmentReader $reader,
        private readonly AttachmentStorageService $storage, private readonly AttachmentSharingPresenter $presenter,
        private readonly AgentAttachmentService $legacy, private readonly AgentMutationExecutor $mutations,
        private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function legacyReference(User|AgentPrincipal $actor, Workspace $workspace, string $id, ?AttachmentAudience $audience = null): AttachmentParentReference
    {
        $this->legacy->authorize($actor, $workspace);
        $file = $this->reader->queryForTypes($actor, $workspace, $audience ?? $this->reader->agentAudience($actor, $workspace), AgentAttachmentService::uploadableTypes())
            ->where('public_id', $id)->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)->firstOrFail();

        return new AttachmentParentReference($file->record_type, $file->record_public_id);
    }

    /** @return array<string,mixed> */
    public function visibility(User|AgentPrincipal $actor, Workspace $workspace, AttachmentParentReference $reference,
        string $id, AttachmentAudience $audience): array
    {
        $view = $this->presenter->view($actor, $workspace, $reference, $audience);
        $file = $this->find($actor, $workspace, $reference, $id, $audience);
        abort_unless($file->lifecycle_state === ClientAttachment::STATE_AVAILABLE, 404);

        return ['data' => $this->presenter->state($file, $view)];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function updateVisibility(User $actor, Workspace $workspace, AttachmentParentReference $reference, string $id,
        array $payload, string $clientId, string $key, AttachmentMutationOrigin $origin): array
    {
        $this->writeGate($reference, $origin);
        $this->key($key);
        $change = AttachmentVisibilityChange::fromInput($payload);
        $operation = $reference->collaboration() ? 'collaboration.attachments.visibility.update' : 'attachments.visibility.update';
        $this->mutations->run($actor, $workspace, $clientId, $operation, $key,
            [...$this->identity($reference), 'attachment_id' => $id, ...$payload],
            fn (): array => [$this->action->setVisibility($actor, $workspace, $id, $change, $origin, $reference)->public_id],
            function (array $ids) use ($actor, $workspace, $reference, $origin): void {
                $this->action->assertReplayAllowed($actor, $workspace, $reference, $ids[0], $origin);
            });

        return $this->visibility($actor, $workspace, $reference, $id, $this->writeAudience($actor, $workspace, $origin));
    }

    /** @return array<string,mixed> */
    public function listing(User|AgentPrincipal $actor, Workspace $workspace, AttachmentParentReference $reference,
        AttachmentAudience $audience, ?AttachmentPageQuery $page = null): array
    {
        if (! $reference->collaboration()) {
            abort_unless(in_array($reference->type, AgentAttachmentService::uploadableTypes(), true), 422);
            $this->legacy->authorize($actor, $workspace);
        }
        $view = $this->presenter->view($actor, $workspace, $reference, $audience);
        $query = $this->reader->forParent($actor, $workspace, $reference->type, $reference->publicId, $audience);
        $page ??= AttachmentPageQuery::fromInput([]);
        $result = CursorPage::run($workspace, implode(':', ['collaboration-attachments', $reference->companyId, $reference->type,
            $reference->publicId, $actor->public_id, $audience->name, 'available-id-desc']), $page->limit, $page->cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn (Builder $rows) => $rows->where('id', '<', $after))
                ->orderByDesc('id')->limit($take)->get(), fn (ClientAttachment $file): array => $this->presenter->collaborationFile($file, $view),
            maxDataBytes: AgentPageBudget::dataBytes(), allowLegacyCursor: false);

        return [...$result, 'parent_version' => AgentApiVersion::for($view->parent)];
    }

    /** Native CLI read, without a receipt or a writer gate.
     * @return array<string,mixed> */
    public function metadata(User $actor, Workspace $workspace, AttachmentParentReference $reference, string $id): array
    {
        if (! $reference->collaboration()) {
            $this->legacyReference($actor, $workspace, $id, AttachmentAudience::Internal);
        }
        $view = $this->presenter->view($actor, $workspace, $reference, AttachmentAudience::Internal);

        return ['data' => $this->presenter->collaborationFile($this->find($actor, $workspace, $reference, $id, AttachmentAudience::Internal), $view),
            'parent_version' => AgentApiVersion::for($view->parent)];
    }

    /** @return array<string,mixed> */
    public function downloadUrl(User|AgentPrincipal $actor, Workspace $workspace, AttachmentParentReference $reference,
        string $id, AttachmentAudience $audience): array
    {
        $this->presenter->view($actor, $workspace, $reference, $audience);
        $file = $this->find($actor, $workspace, $reference, $id, $audience);
        abort_unless($file->lifecycle_state === ClientAttachment::STATE_AVAILABLE, 404);

        return ['data' => ['url' => $this->signed('collaboration.attachments.content', $workspace, $reference, ['attachment' => $id]),
            'expires_in' => 600, 'method' => 'GET']];
    }

    public function download(User|AgentPrincipal $actor, Workspace $workspace, AttachmentParentReference $reference,
        string $id, AttachmentAudience $audience): StreamedResponse
    {
        $this->presenter->view($actor, $workspace, $reference, $audience);

        return $this->storage->download($this->find($actor, $workspace, $reference, $id, $audience));
    }

    /** No receipt, durable row, byte staging or object operation.
     * @return array<string,mixed> */
    public function uploadUrl(User $actor, Workspace $workspace, AttachmentParentReference $reference, AttachmentMutationOrigin $origin): array
    {
        $this->writeGate($reference, $origin);
        $parent = $this->action->uploadPreflight($actor, $workspace, $reference, $origin);

        return ['data' => ['url' => $this->signed('collaboration.attachments.upload', $workspace, $reference),
            'expires_in' => 600, 'method' => 'POST', 'expected_version' => AgentApiVersion::for($parent), 'max_bytes' => 52428800,
            'file_field' => 'file', 'version_field' => 'expected_version', 'idempotency_header' => 'Idempotency-Key']];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function upload(User $actor, Workspace $workspace, AttachmentParentReference $reference, UploadedFile $file,
        array $payload, string $clientId, string $key, AttachmentMutationOrigin $origin): array
    {
        $this->writeGate($reference, $origin);
        abort_unless($reference->collaboration(), 422);
        $this->key($key);
        if (array_diff(array_keys($payload), ['file', 'expected_version']) !== []) {
            throw ValidationException::withMessages(['attachment' => 'Unsupported attachment upload field.']);
        }
        $data = Validator::make([...$payload, 'file' => $file], ['expected_version' => ['required', 'string', 'size:64'],
            'file' => ['required', 'file', 'max:51200']])->validate();
        $digest = hash_file('sha256', $file->getPathname());
        abort_unless(is_string($digest), 422);
        $receiptPayload = [...$this->identity($reference), 'expected_version' => $data['expected_version'],
            'filename' => $file->getClientOriginalName(), 'bytes' => $file->getSize(), 'sha256' => $digest, 'media_type' => $file->getMimeType()];
        $operation = 'collaboration.attachments.upload';
        $guard = function (array $ids) use ($actor, $workspace, $reference, $origin): void {
            $this->action->assertReplayAllowed($actor, $workspace, $reference, $ids[0], $origin);
        };
        // Exact replay must not fail stale original CAS versions or stage another object.
        $existing = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->where('oauth_client_id', $clientId)->where('operation', $operation)->where('idempotency_key', $key)->exists();
        if ($existing) {
            $ids = $this->mutations->run($actor, $workspace, $clientId, $operation, $key, $receiptPayload,
                static function (): array {
                    abort(409, 'The original receipt is no longer available.');
                }, $guard);

            return $this->fileResponse($actor, $workspace, $reference, $ids[0], $origin);
        }
        $prepared = $this->action->prepareUpload($actor, $workspace, $reference, $file, $data['expected_version'], $origin);
        try {
            $ids = $this->mutations->run($actor, $workspace, $clientId, $operation, $key, $receiptPayload,
                function () use ($actor, $workspace, $reference, $prepared, $data, $origin): array {
                    $this->writeGate($reference, $origin);

                    return [$this->action->publishPrepared($actor, $workspace, $reference, $prepared, $data['expected_version'], $origin)->public_id];
                }, $guard);
        } catch (Throwable $exception) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
            throw $exception;
        }
        if ($ids[0] !== $prepared->public_id) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
        }

        return $this->fileResponse($actor, $workspace, $reference, $ids[0], $origin);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function delete(User $actor, Workspace $workspace, AttachmentParentReference $reference, string $id,
        array $payload, string $clientId, string $key, AttachmentMutationOrigin $origin): array
    {
        $this->writeGate($reference, $origin);
        $this->key($key);
        $deletion = AttachmentDeletion::fromInput($payload);
        $ids = $this->mutations->run($actor, $workspace, $clientId, 'collaboration.attachments.delete', $key,
            [...$this->identity($reference), 'attachment_id' => $id, ...$payload],
            fn (): array => [$this->action->delete($actor, $workspace, $reference, $id, $deletion, $origin)->public_id],
            function (array $ids) use ($actor, $workspace, $reference, $origin): void {
                $this->action->assertReplayAllowed($actor, $workspace, $reference, $ids[0], $origin, true);
            });

        return $this->fileResponse($actor, $workspace, $reference, $ids[0], $origin);
    }

    /** @return array<string,mixed> */
    private function fileResponse(User $actor, Workspace $workspace, AttachmentParentReference $reference, string $id, AttachmentMutationOrigin $origin): array
    {
        $audience = $this->writeAudience($actor, $workspace, $origin);
        $view = $this->presenter->view($actor, $workspace, $reference, $audience);

        return ['data' => $this->presenter->collaborationFile($this->find($actor, $workspace, $reference, $id, $audience), $view)];
    }

    private function find(User|AgentPrincipal $actor, Workspace $workspace, AttachmentParentReference $reference, string $id,
        AttachmentAudience $audience): ClientAttachment
    {
        return $this->reader->query($actor, $workspace, $audience, $reference->type)->where('record_public_id', $reference->publicId)
            ->where('public_id', $id)->firstOrFail();
    }

    private function writeGate(AttachmentParentReference $reference, AttachmentMutationOrigin $origin): void
    {
        AttachmentSharingGate::assertEnabled();
        if ($origin === AttachmentMutationOrigin::Agent) {
            abort_unless(AgentWriteCutover::files() && (! $reference->collaboration() || AgentWriteCutover::collaboration()), 404);
        }
    }

    private function writeAudience(User $actor, Workspace $workspace, AttachmentMutationOrigin $origin): AttachmentAudience
    {
        return $origin === AttachmentMutationOrigin::Agent ? $this->reader->agentAudience($actor, $workspace) : AttachmentAudience::Internal;
    }

    private function key(string $key): void
    {
        abort_unless(trim($key) !== '' && strlen($key) <= 255, 422, 'An Idempotency-Key header is required.');
    }

    /** @return array<string,mixed> */
    private function identity(AttachmentParentReference $reference): array
    {
        return ['record_type' => $reference->type, 'record_id' => $reference->publicId, 'company_id' => $reference->companyId];
    }

    /** @param array<string,mixed> $parameters */
    private function signed(string $name, Workspace $workspace, AttachmentParentReference $reference, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('agent-api.v1.'.$name, $this->clock->now()->addMinutes(10),
            ['workspace' => $workspace, 'client' => $reference->companyId, 'recordType' => $reference->type, 'recordPublicId' => $reference->publicId, ...$parameters], false);
    }
}
