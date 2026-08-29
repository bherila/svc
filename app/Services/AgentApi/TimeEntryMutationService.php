<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\AgreementBillingRateResolver;
use App\Services\Billing\MoneyService;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class TimeEntryMutationService
{
    public function __construct(
        private readonly ProjectAccess $access,
        private readonly AgreementBillingRateResolver $rates,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Workspace $workspace, ClientProject $project, User $actor, array $data): ClientTimeEntry
    {
        abort_unless($this->access->canView($actor, $project), 404);
        abort_unless($this->access->canLogTime($actor, $project), 403);
        $this->assertClientDescription($data['is_visible_to_client'] ?? false, $data['client_visible_description'] ?? null);
        $task = null;
        if (is_string($data['task_id'] ?? null)) {
            $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $data['task_id'])->firstOrFail();
            abort_unless($task->client_project_id === $project->id, 422, 'The task must belong to the selected project.');
        }

        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id, 'client_task_id' => $task?->id, 'user_id' => $actor->id,
            'worked_on' => $data['worked_on'], 'minutes' => $data['minutes'], 'description' => $data['description'],
            'client_visible_description' => $data['client_visible_description'] ?? null,
            'is_visible_to_client' => $data['is_visible_to_client'] ?? false,
            'is_billable' => $data['is_billable'] ?? true, 'is_deferred' => $data['is_deferred'] ?? false,
            'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : $workspace->default_currency,
            'status' => 'draft',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Workspace $workspace, ClientTimeEntry $entry, User $actor, array $data): ClientTimeEntry
    {
        $this->assertDraftEditable($workspace, $entry, $actor);
        $visible = array_key_exists('is_visible_to_client', $data) ? (bool) $data['is_visible_to_client'] : $entry->is_visible_to_client;
        $clientDescription = array_key_exists('client_visible_description', $data) ? $data['client_visible_description'] : $entry->client_visible_description;
        $this->assertClientDescription($visible, $clientDescription);
        $attributes = Arr::only($data, [
            'worked_on', 'minutes', 'description', 'is_billable', 'is_deferred',
            'is_visible_to_client', 'client_visible_description',
        ]);
        abort_unless(AgentApiVersion::matches($entry, $data['expected_version']), 409, 'The time entry has changed; read it and retry.');
        $updated = ClientTimeEntry::query()->whereKey($entry->id)->where('lock_version', $entry->lock_version)->update($attributes + ['lock_version' => DB::raw('lock_version + 1')]);
        abort_unless($updated === 1, 409, 'The time entry has changed; read it and retry.');

        return $entry->fresh() ?? throw new \RuntimeException('The time entry no longer exists.');
    }

    public function delete(Workspace $workspace, ClientTimeEntry $entry, User $actor, string $expectedVersion): void
    {
        $this->assertDraftEditable($workspace, $entry, $actor);
        abort_unless(AgentApiVersion::matches($entry, $expectedVersion), 409, 'The time entry has changed; read it and retry.');
        $updated = ClientTimeEntry::query()->whereKey($entry->id)->where('lock_version', $entry->lock_version)->update(['lock_version' => DB::raw('lock_version + 1'), 'deleted_at' => now()]);
        abort_unless($updated === 1, 409, 'The time entry has changed; read it and retry.');
    }

    /** @param list<array{id: string, expected_version: string, billing_rate_amount?: int, currency?: string}> $entries */
    public function approve(Workspace $workspace, User $actor, array $entries): void
    {
        DB::transaction(function () use ($workspace, $actor, $entries): void {
            foreach ($entries as $item) {
                $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $item['id'])->lockForUpdate()->firstOrFail();
                abort_unless($this->access->canApproveTime($actor, $entry->project), 403);
                abort_unless($entry->status === 'draft', 409, 'Only draft time entries can be approved.');
                abort_unless(AgentApiVersion::matches($entry, $item['expected_version']), 409, 'The time entry has changed; read it and retry.');
                $rate = $this->approvalRate($entry, $item);
                $entry->forceFill([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'billing_rate_amount' => $rate['amount'],
                    'billing_rate_source' => $rate['source'],
                    'currency' => $rate['currency'],
                    'lock_version' => $entry->lock_version + 1,
                ])->save();
            }
        });
    }

    /**
     * @param  array{id: string, expected_version: string, billing_rate_amount?: int, currency?: string}  $item
     * @return array{amount:int|null,currency:string|null,source:string|null}
     */
    private function approvalRate(ClientTimeEntry $entry, array $item): array
    {
        if (! $entry->is_billable) {
            return ['amount' => null, 'currency' => $entry->currency, 'source' => null];
        }

        $hasAmount = array_key_exists('billing_rate_amount', $item);
        $hasCurrency = array_key_exists('currency', $item);
        if ($hasAmount && $hasCurrency) {
            return [
                'amount' => MoneyService::nonNegativeInteger($item['billing_rate_amount'], 'billing_rate_amount'),
                'currency' => MoneyService::currency($item['currency']),
                'source' => 'explicit',
            ];
        }
        if ($hasAmount || $hasCurrency) {
            throw new DomainException('A billing-rate override requires both amount and currency.');
        }

        return $this->rates->resolve($entry) + ['source' => 'agreement'];
    }

    private function assertDraftEditable(Workspace $workspace, ClientTimeEntry $entry, User $actor): void
    {
        abort_unless($entry->workspace_id === $workspace->id, 404);
        abort_unless($entry->status === 'draft', 409, 'Only draft time entries can be changed.');
        // Status alone is not enough. An entry can still read `draft` while a
        // line already bills it - issuing rewrites attached time, but nothing
        // guarantees the two are in step, and the gap is exactly where an edit
        // would change what a sent invoice charged. Nor is it enough to check
        // the invoice is a draft: a draft is regenerated from its entries, but
        // this path changes only the entry, so the line would keep billing the
        // old quantity until something else recomposed it.
        abort_if(
            $entry->invoiceLines()->exists(),
            409,
            'This time entry is already on an invoice. Regenerate or void that invoice to change it.',
        );
        abort_unless($entry->user_id === $actor->id || $this->access->isWorkspaceManager($actor, $workspace), 403);
        abort_unless($this->access->canView($actor, $entry->project), 404);
        abort_unless($this->access->canLogTime($actor, $entry->project), 403);
    }

    private function assertClientDescription(bool $visible, mixed $description): void
    {
        if ($visible && (! is_string($description) || trim($description) === '')) {
            throw new DomainException('Client-visible time requires an explicit client-facing description.');
        }
    }
}
