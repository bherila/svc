<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\Validator;

/**
 * The tenant-scoped, idempotent time-log workflow shared by REST and MCP.
 *
 * The action validates the payload inside the mutation executor so every
 * transport reaches the same receipt and failure-audit boundary.
 *
 * `approve: true` approves the logged entries in the same transaction and
 * under the same receipt, so a batch is either logged and approved or not
 * written at all. It is gated exactly as `time_entries.approve` is: the
 * workflow write cutover, the time:approve scope, and the approver role on
 * every project, each rechecked before a receipt is replayed.
 */
final class LogTimeEntriesAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
        private readonly ProjectAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, array $payload, bool $allowsApprovalScope): array
    {
        $additionalAuditOperation = $this->approves($payload) ? 'time_entries.approve' : null;

        return $this->mutations->run(
            $user,
            $workspace,
            $clientId,
            'time_entries.log',
            $idempotencyKey,
            $payload,
            function () use ($workspace, $user, $payload, $allowsApprovalScope): array {
                $data = Validator::make($payload, [
                    'approve' => ['sometimes', 'boolean'],
                    'entries' => ['required', 'array', 'min:1', 'max:20'],
                    'entries.*' => ['required', 'array:project_id,task_id,worked_on,minutes,description,is_billable,is_deferred,is_visible_to_client,client_visible_description,billing_rate_amount,currency'],
                    'entries.*.project_id' => ['required', 'uuid'],
                    'entries.*.task_id' => ['nullable', 'uuid'],
                    'entries.*.worked_on' => ['required', 'date_format:Y-m-d'],
                    'entries.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                    'entries.*.description' => ['required', 'string', 'max:10000'],
                    'entries.*.is_billable' => ['sometimes', 'boolean'],
                    'entries.*.is_deferred' => ['sometimes', 'boolean'],
                    'entries.*.is_visible_to_client' => ['sometimes', 'boolean'],
                    'entries.*.client_visible_description' => ['nullable', 'string', 'max:10000'],
                    'entries.*.billing_rate_amount' => ['nullable', 'integer', 'min:0'],
                    'entries.*.currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                ])->validate();
                $this->assertRateScope($data, $allowsApprovalScope);
                $approve = $this->approves($data);
                $this->assertApprovalAllowed($approve, $allowsApprovalScope);
                $ids = [];
                foreach ($data['entries'] as $entry) {
                    $project = ClientProject::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('public_id', $entry['project_id'] ?? null)
                        ->firstOrFail();
                    $ids[] = $this->time->create($workspace, $project, $user, $entry)->public_id;
                }
                if ($approve) {
                    $this->time->approve($workspace, $user, $this->approvalItems($workspace, $ids));
                }

                return $ids;
            },
            function (array $ids) use ($workspace, $user, $payload, $allowsApprovalScope): void {
                $this->assertRateScope($payload, $allowsApprovalScope);
                $approve = $this->approves($payload);
                $this->assertApprovalAllowed($approve, $allowsApprovalScope);
                $this->replayGuard->assertAllowed($workspace, $user, $ids);
                if ($approve) {
                    $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get();
                    abort_unless($entries->count() === count($ids), 404);
                    abort_unless($this->access->canApproveTimeForProjects(
                        $user,
                        $workspace,
                        $entries->map(static fn (ClientTimeEntry $entry): ClientProject => $entry->project),
                    ), 403);
                }
            },
            $additionalAuditOperation,
        );
    }

    /**
     * The raw payload is read on replay, so this accepts exactly what the
     * `boolean` rule accepted when the receipt was first written.
     *
     * @param  array<string, mixed>  $payload
     */
    private function approves(array $payload): bool
    {
        return in_array($payload['approve'] ?? false, [true, 1, '1'], true);
    }

    private function assertApprovalAllowed(bool $approve, bool $allowsApprovalScope): void
    {
        if (! $approve) {
            return;
        }
        // Folding approval into the log call must not route around the
        // cutover that withholds `time_entries.approve` itself.
        abort_unless((bool) config('agent_api.writes_enabled'), 403, 'Approving time is not enabled for agents.');
        abort_unless($allowsApprovalScope, 403, 'Approving time requires the time:approve scope.');
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string, expected_version: string}>
     */
    private function approvalItems(Workspace $workspace, array $ids): array
    {
        $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->get()->keyBy('public_id');

        return array_map(fn (string $id): array => [
            'id' => $id,
            'expected_version' => AgentApiVersion::for($entries->get($id) ?? throw new \LogicException('A time entry logged in this transaction is missing.')),
        ], $ids);
    }

    /** @param array<string, mixed> $payload */
    private function assertRateScope(array $payload, bool $allowsApprovalScope): void
    {
        // A manager's role is not authority delegated to every one of their
        // tokens. Pricing time requires the approval scope in addition to the
        // project-role check in the shared creator; null/omitted rates do not.
        foreach ($payload['entries'] ?? [] as $entry) {
            if (isset($entry['billing_rate_amount'])) {
                abort_unless($allowsApprovalScope, 403, 'Setting a billing rate requires the time:approve scope.');
            }
        }
    }
}
