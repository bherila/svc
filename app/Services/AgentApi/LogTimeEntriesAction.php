<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\Engagement\TimeEntryCreationRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

/**
 * The tenant-scoped, idempotent time-log workflow shared by REST and MCP.
 *
 * The action validates the payload inside the mutation executor so every
 * transport reaches the same receipt and failure-audit boundary.
 *
 * `approve: true` is deprecated and does nothing (UX overhaul section 7b):
 * time you log is your own, and no one approves their own time, so the
 * entries are written unapproved and the response says so. It is still
 * gated exactly as `time_entries.approve` is - the workflow write cutover,
 * the time:approve scope, and the approver role on every project, each
 * rechecked before a receipt is replayed - so a caller who could not have
 * asked is still refused rather than silently ignored.
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
                    'entries.*' => ['required', 'array:'.implode(',', TimeEntryCreationRules::FIELDS)],
                    ...TimeEntryCreationRules::entries($workspace->timezone),
                ])->validate();
                $this->assertRateScope($data, $allowsApprovalScope);
                $approve = $this->approves($data);
                $this->assertApprovalAllowed($approve, $allowsApprovalScope);
                $ids = [];
                $projects = [];
                foreach ($data['entries'] as $entry) {
                    $project = ClientProject::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('public_id', $entry['project_id'] ?? null)
                        ->firstOrFail();
                    $projects[$project->id] = $project;
                    $ids[] = $this->time->create($workspace, $project, $user, $entry)->public_id;
                }
                // Nothing is approved - see the class comment - but the ask is
                // still held to the approver's gate, so a request nobody could
                // have made is refused and rolled back, not quietly ignored.
                if ($approve) {
                    abort_unless($this->access->canApproveTimeForProjects($user, $workspace, collect(array_values($projects))), 403);
                }

                return $ids;
            },
            function (array $ids) use ($workspace, $user, $payload, $allowsApprovalScope): void {
                $this->assertRateScope($payload, $allowsApprovalScope);
                $approve = $this->approves($payload);
                $this->assertApprovalAllowed($approve, $allowsApprovalScope);
                $this->replayGuard->assertAllowed($workspace, $user, $ids);
                if ($approve) {
                    $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->get();
                    abort_unless($entries->count() === count($ids), 404);
                    $projectIds = $entries->pluck('client_project_id')->unique()->values();
                    $projects = ClientProject::query()
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('id', $projectIds)
                        ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))
                        ->get()
                        ->keyBy('id');
                    abort_unless($projects->count() === $projectIds->count(), 404);
                    foreach ($entries as $entry) {
                        $project = $projects->get($entry->client_project_id);
                        abort_unless($project instanceof ClientProject && $project->client_company_id === $entry->client_company_id, 404);
                    }
                    abort_unless($this->access->canApproveTimeForProjects(
                        $user,
                        $workspace,
                        $projects->values(),
                    ), 403);
                }
            },
        );
    }

    /**
     * The raw payload is read on replay, so this accepts exactly what the
     * `boolean` rule accepted when the receipt was first written.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function approvalRequested(array $payload): bool
    {
        return in_array($payload['approve'] ?? false, [true, 1, '1'], true);
    }

    /** @param array<string, mixed> $payload */
    private function approves(array $payload): bool
    {
        return self::approvalRequested($payload);
    }

    private function assertApprovalAllowed(bool $approve, bool $allowsApprovalScope): void
    {
        if (! $approve) {
            return;
        }
        // Folding approval into the log call must not route around the
        // cutover that withholds `time_entries.approve` itself.
        abort_unless(AgentWriteCutover::writes(), 403, 'Approving time is not enabled for agents.');
        abort_unless($allowsApprovalScope, 403, 'Approving time requires the time:approve scope.');
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
