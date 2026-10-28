<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\BillingMaintenance;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentDeletion;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentStorageService;
use App\Services\Files\AttachmentUploadPolicy;
use App\Services\Files\AttachmentWriteAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\CursorPage;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Expense-only adapter: URLs never grant access without a current authorized API credential. */
final class AgentExpenseReceiptService
{
    public function __construct(private readonly AgentAccess $access, private readonly AgentMutationExecutor $mutations, private readonly AttachmentStorageService $storage, private readonly WorkspaceClock $clock, private readonly AttachmentReader $reader, private readonly AttachmentWriteAccess $writeAccess) {}

    /** @return array<string,mixed> */
    public function listing(User|AgentPrincipal $actor, Workspace $workspace, string $expenseId, ?AttachmentPageQuery $page = null, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $expense = $this->expense($actor, $workspace, $expenseId, $origin);
        $page ??= AttachmentPageQuery::fromInput([]);
        $query = $this->receipts($actor, $workspace, $expenseId, $origin)->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE);
        $result = CursorPage::run($workspace, 'expense-receipts:'.$expenseId.':'.$actor->public_id.':available:id-desc', $page->limit, $page->cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn (Builder $rows) => $rows->where('id', '<', $after))
                ->orderByDesc('id')->limit($take)->get(), $this->present(...), maxDataBytes: AgentPageBudget::dataBytes(), allowLegacyCursor: false);

        return [...$result, 'expense_version' => AgentApiVersion::for($expense)];
    }

    /** @return array<string,mixed> */
    public function downloadUrl(User|AgentPrincipal $actor, Workspace $workspace, string $expenseId, string $receiptId, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        $this->expense($actor, $workspace, $expenseId, $origin);
        $this->receipts($actor, $workspace, $expenseId, $origin)->where('public_id', $receiptId)->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)->firstOrFail();
        $expires = $this->clock->now($workspace)->addMinutes(10);

        return ['data' => ['download_url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('agent-api.v1.expense-receipts.content', $expires,
            ['workspace' => $workspace, 'expense' => $expenseId, 'receipt' => $receiptId], absolute: false), 'expires_at' => $expires->toISOString()]];
    }

    /** @return array<string,mixed> */
    public function uploadUrl(User|AgentPrincipal $actor, Workspace $workspace, string $expenseId, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        abort_unless($origin === AttachmentMutationOrigin::Native || AgentWriteCutover::expenses(), 404);
        $this->authorizeWrite($actor, $workspace, $origin);
        AttachmentUploadPolicy::defaultVisibility();
        $expense = $this->expense($actor, $workspace, $expenseId, $origin);
        $expires = $this->clock->now($workspace)->addMinutes(10);

        return ['data' => ['upload_url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('agent-api.v1.expense-receipts.store', $expires,
            ['workspace' => $workspace, 'expense' => $expenseId], absolute: false), 'expires_at' => $expires->toISOString(), 'expected_version' => AgentApiVersion::for($expense)]];
    }

    public function content(User|AgentPrincipal $actor, Workspace $workspace, string $expenseId, string $receiptId, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): StreamedResponse
    {
        $this->expense($actor, $workspace, $expenseId, $origin);
        $receipt = $this->receipts($actor, $workspace, $expenseId, $origin)->where('public_id', $receiptId)->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)->firstOrFail();

        return $this->storage->download($receipt);
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    public function upload(User $actor, Workspace $workspace, string $clientId, string $key, string $expenseId, array $body, UploadedFile $file, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        abort_unless($origin === AttachmentMutationOrigin::Native || AgentWriteCutover::expenses(), 404);
        $this->authorizeWrite($actor, $workspace, $origin);
        AttachmentUploadPolicy::validateLegacyPayload($body);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $payload = ['expense_id' => $expenseId, 'body' => $body, 'filename' => $file->getClientOriginalName(),
            'sha256' => hash_file('sha256', $file->getPathname()), 'bytes' => $file->getSize(), 'media_type' => $file->getMimeType()];
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:51200']])->validate();
        $parent = $this->expense($actor, $workspace, $expenseId, $origin);
        $prepared = $this->storage->prepareForPublication($workspace, $parent, $file, $actor);
        try {
            $ids = $this->mutations->run($actor, $workspace, $clientId, 'expenses.receipts.upload', $key, $payload,
                function () use ($actor, $workspace, $expenseId, $file, $body, $prepared, $origin): array {
                    $this->authorizeWrite($actor, $workspace, $origin);
                    abort_unless($origin === AttachmentMutationOrigin::Native || AgentWriteCutover::expenses(), 404);
                    Validator::make(['body' => $body], ['body' => ['required', 'array:expected_version']])->validate();
                    $data = Validator::make([...$body, 'file' => $file], ['expected_version' => ['required', 'string', 'size:64'], 'file' => ['required', 'file', 'max:51200']])->validate();
                    $this->writeAccess->lockWorkspace($workspace);
                    $expense = $this->expenses($workspace)->where('public_id', $expenseId)->tap(Locks::forUpdate())->firstOrFail();
                    $this->writeAccess->assertCurrentManager($actor, $workspace, $origin);
                    abort_unless(AgentApiVersion::matches($expense, $data['expected_version']), 409, 'The expense has changed; read it and retry.');
                    $published = $this->storage->publishPrepared($workspace, $expense, $prepared);
                    $expense->forceFill(['lock_version' => $expense->lock_version + 1])->save();

                    return [$published->public_id];
                }, function (array $ids) use ($actor, $workspace, $expenseId, $origin): void {
                    abort_unless($origin === AttachmentMutationOrigin::Native || AgentWriteCutover::expenses(), 404);
                    $this->authorizeWrite($actor, $workspace, $origin);
                    $this->writeAccess->lockWorkspace($workspace);
                    $this->expenses($workspace)->where('public_id', $expenseId)->tap(Locks::forUpdate())->firstOrFail();
                    $this->writeAccess->assertCurrentManager($actor, $workspace, $origin);
                });
        } catch (Throwable $exception) {
            $this->storage->discardPreparedUpload($workspace, $prepared);

            throw $exception;
        }
        // A replay returns the original receipt and discards this request's unused preparation.
        if ($ids[0] !== $prepared->public_id) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
        }
        $receipt = $this->receipts($actor, $workspace, $expenseId, $origin)->where('public_id', $ids[0])->firstOrFail();
        $expense = $this->expense($actor, $workspace, $expenseId, $origin);

        return ['data' => $this->present($receipt), 'expense_version' => AgentApiVersion::for($expense)];
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    public function delete(User $actor, Workspace $workspace, string $clientId, string $key, string $expenseId, string $receiptId,
        array $body, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): array
    {
        abort_unless($origin === AttachmentMutationOrigin::Native || AgentWriteCutover::expenses(), 404);
        $this->authorizeWrite($actor, $workspace, $origin);
        $ids = $this->mutations->run($actor, $workspace, $clientId, 'expenses.receipts.delete', $key,
            ['expense_id' => $expenseId, 'receipt_id' => $receiptId, 'body' => $body],
            function () use ($actor, $workspace, $expenseId, $receiptId, $body, $origin): array {
                $deletion = AttachmentDeletion::fromInput($body);
                [$expense, $receipt] = $this->lockedDeletion($actor, $workspace, $expenseId, $receiptId, $origin, $deletion);
                if ($receipt->lifecycle_state === ClientAttachment::STATE_AVAILABLE) {
                    $receipt->setRelation('workspace', $workspace);
                    $this->storage->requestDeletion($receipt);
                    $expense->forceFill(['lock_version' => $expense->lock_version + 1])->save();
                }

                return [$receipt->public_id];
            }, function (array $ids) use ($actor, $workspace, $expenseId, $origin): void {
                abort_unless($origin === AttachmentMutationOrigin::Native || AgentWriteCutover::expenses(), 404);
                $this->lockedDeletion($actor, $workspace, $expenseId, $ids[0], $origin);
            });
        $receipt = $this->receipts($actor, $workspace, $expenseId, $origin)->where('public_id', $ids[0])->firstOrFail();

        return ['data' => $this->present($receipt), 'expense_version' => AgentApiVersion::for($this->expense($actor, $workspace, $expenseId, $origin))];
    }

    /** Native CLI preview: locked authority and versions, no receipt, audit or object writes.
     * @param array<string,mixed> $body
     * @return array<string,mixed> */
    public function deletionPreflight(User $actor, Workspace $workspace, string $expenseId, string $receiptId, array $body): array
    {
        $deletion = AttachmentDeletion::fromInput($body);

        return DB::transaction(function () use ($actor, $workspace, $expenseId, $receiptId, $deletion): array {
            [$expense, $receipt] = $this->lockedDeletion($actor, $workspace, $expenseId, $receiptId, AttachmentMutationOrigin::Native, $deletion);

            return ['data' => $this->present($receipt), 'expense_version' => AgentApiVersion::for($expense), 'dry_run' => true];
        });
    }

    /** @return array{ClientExpense,ClientAttachment} */
    private function lockedDeletion(User $actor, Workspace $workspace, string $expenseId, string $receiptId,
        AttachmentMutationOrigin $origin, ?AttachmentDeletion $deletion = null): array
    {
        BillingMaintenance::share($workspace->id);
        $this->writeAccess->lockWorkspace($workspace);
        $expense = $this->expenses($workspace)->where('public_id', $expenseId)->tap(Locks::forUpdate())->firstOrFail();
        $this->writeAccess->assertCurrentManager($actor, $workspace, $origin);
        $receipt = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('record_type', 'expense')
            ->where('record_public_id', $expenseId)->where('public_id', $receiptId)->tap(Locks::forUpdate())->firstOrFail();
        abort_unless(in_array($receipt->lifecycle_state, $deletion === null
            ? [ClientAttachment::STATE_DELETING, ClientAttachment::STATE_DELETED]
            : [ClientAttachment::STATE_AVAILABLE, ClientAttachment::STATE_DELETING, ClientAttachment::STATE_DELETED], true), 404);
        if ($deletion !== null) {
            abort_unless(AgentApiVersion::matches($expense, $deletion->expectedParentVersion), 409, 'The expense changed; read it before deleting.');
            abort_unless(AgentApiVersion::matches($receipt, $deletion->expectedVersion), 409, 'The receipt changed; read it before deleting.');
        }

        return [$expense, $receipt];
    }

    private function authorizeWrite(User|AgentPrincipal $actor, Workspace $workspace, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): void
    {
        $this->authorize($actor, $workspace, $origin);
    }

    private function authorize(User|AgentPrincipal $actor, Workspace $workspace, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): void
    {
        abort_unless($this->access->canViewWorkspace($actor, $workspace), 404);
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
        // Agent-origin expense receipts have no Portal-readable parent. Native
        // staff managers keep their existing internal authority, including dual identities.
        abort_if($origin === AttachmentMutationOrigin::Agent && $this->access->isWorkspaceClient($actor, $workspace), 403);
    }

    private function expense(User|AgentPrincipal $actor, Workspace $workspace, string $id, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): ClientExpense
    {
        $this->authorize($actor, $workspace, $origin);

        return $this->expenses($workspace)->where('public_id', $id)->firstOrFail();
    }

    /** @return Builder<ClientExpense> */
    private function expenses(Workspace $workspace): Builder
    {
        return (new WorkspaceExpenses($workspace))->query()->whereIn('client_company_id',
            ClientCompany::query()->where('workspace_id', $workspace->id)->select('id'));
    }

    /** @return Builder<ClientAttachment> */
    private function receipts(User|AgentPrincipal $actor, Workspace $workspace, string $expenseId, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): Builder
    {
        return $this->reader->query($actor, $workspace, $origin === AttachmentMutationOrigin::Native ? AttachmentAudience::Native : $this->reader->agentAudience($actor, $workspace), 'expense')->where('record_public_id', $expenseId);
    }

    /** @return array<string,mixed> */
    private function present(ClientAttachment $receipt): array
    {
        return ['id' => $receipt->public_id, 'filename' => $receipt->original_filename, 'media_type' => $receipt->media_type,
            'bytes' => $receipt->bytes, 'sha256' => $receipt->sha256, 'uploaded_at' => $receipt->available_at?->toISOString(), 'version' => AgentApiVersion::for($receipt), 'lifecycle_state' => $receipt->lifecycle_state];
    }
}
