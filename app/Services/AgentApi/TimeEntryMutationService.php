<?php

namespace App\Services\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\AgreementBillingRateResolver;
use App\Services\Billing\DraftInvoiceTimeRegenerator;
use App\Services\Billing\MoneyService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\SubcontractorBillingMode;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class TimeEntryMutationService
{
    public function __construct(
        private readonly ProjectAccess $access,
        private readonly AgreementBillingRateResolver $rates,
        private readonly DraftInvoiceTimeRegenerator $draftInvoices,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
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
        return $this->serialized($workspace, $entry, function (ClientTimeEntry $entry, ?ClientInvoice $invoice) use ($workspace, $actor, $data): ClientTimeEntry {
            $this->assertDraftEditable($workspace, $entry, $actor, $invoice);
            $lineageRootId = $entry->split_from_time_entry_id ?? $entry->id;
            $visible = array_key_exists('is_visible_to_client', $data) ? (bool) $data['is_visible_to_client'] : $entry->is_visible_to_client;
            $clientDescription = array_key_exists('client_visible_description', $data) ? $data['client_visible_description'] : $entry->client_visible_description;
            $this->assertClientDescription($visible, $clientDescription);
            $attributes = Arr::only($data, [
                'worked_on', 'minutes', 'description', 'is_billable', 'is_deferred',
                'is_visible_to_client', 'client_visible_description',
            ]);

            // Task attribution is optional, so an entry saved against the
            // wrong one has to be correctable while it is still a draft -
            // including back to none, which is why an explicit null differs
            // from an absent key here.
            if (array_key_exists('task_id', $data)) {
                $attributes['client_task_id'] = $this->taskFor($workspace, $entry, $data['task_id']);
            }
            abort_unless(AgentApiVersion::matches($entry, $data['expected_version']), 409, 'The time entry has changed; read it and retry.');
            $updated = ClientTimeEntry::query()
                ->whereKey($entry->id)
                ->where('workspace_id', $workspace->id)
                ->where('lock_version', $entry->lock_version)
                ->update($attributes + ['lock_version' => DB::raw('lock_version + 1')]);
            abort_unless($updated === 1, 409, 'The time entry has changed; read it and retry.');

            if ($invoice instanceof ClientInvoice) {
                $this->assertNoForeignAllocations($workspace, $entry);
                $this->draftInvoices->regenerate($invoice, $workspace, $entry->id);
            }

            // Draft regeneration can put an unchanged split group back
            // together. If the edited row was the overflow fragment, its
            // minutes now live on the lineage root and that is the record the
            // next sheet read will expose.
            $survivor = ClientTimeEntry::query()
                ->whereKey($entry->id)
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $entry->client_company_id)
                ->first()
                ?? ClientTimeEntry::query()
                    ->whereKey($lineageRootId)
                    ->where('workspace_id', $workspace->id)
                    ->where('client_company_id', $entry->client_company_id)
                    ->first();
            abort_unless($survivor instanceof ClientTimeEntry, 409, 'The regenerated time entry has an inconsistent split lineage.');

            return $survivor;
        });
    }

    public function delete(Workspace $workspace, ClientTimeEntry $entry, User $actor, string $expectedVersion): void
    {
        $this->serialized($workspace, $entry, function (ClientTimeEntry $entry, ?ClientInvoice $invoice) use ($workspace, $actor, $expectedVersion): null {
            $this->assertDraftEditable($workspace, $entry, $actor, $invoice);
            abort_unless(AgentApiVersion::matches($entry, $expectedVersion), 409, 'The time entry has changed; read it and retry.');
            $updated = ClientTimeEntry::query()
                ->whereKey($entry->id)
                ->where('workspace_id', $workspace->id)
                ->where('lock_version', $entry->lock_version)
                ->update(['lock_version' => DB::raw('lock_version + 1'), 'deleted_at' => $this->clock->now($workspace)]);
            abort_unless($updated === 1, 409, 'The time entry has changed; read it and retry.');

            if ($invoice instanceof ClientInvoice) {
                $this->assertNoForeignAllocations($workspace, $entry);
                $this->draftInvoices->regenerate($invoice, $workspace, $entry->id);
            }

            return null;
        });
    }

    /**
     * Run a write against the same row lock invoice allocation takes.
     *
     * The optimistic version cannot see an allocation: `InvoiceFromTimeService`
     * locks the entry, attaches the pivot and leaves `lock_version` untouched,
     * because attaching does not change the entry. So a check of the freeze
     * outside a lock is a read that can be true when it is written and false
     * when it is acted on - the allocation commits in between, the version
     * still matches, and the write lands under a draft invoice that goes on
     * charging the old quantity, or against an entry that has just been
     * deleted from beneath its own line.
     *
     * Locking the row here puts both sides in the same queue, and re-reading
     * inside the lock is what makes the freeze check mean anything.
     *
     * @template TReturn
     *
     * @param  callable(ClientTimeEntry, ?ClientInvoice): TReturn  $write
     * @return TReturn
     */
    private function serialized(Workspace $workspace, ClientTimeEntry $entry, callable $write): mixed
    {
        return DB::transaction(function () use ($workspace, $entry, $write) {
            $probe = ClientTimeEntry::query()
                ->whereKey($entry->id)
                ->where('workspace_id', $workspace->id)
                ->first();
            abort_unless($probe instanceof ClientTimeEntry, 404);

            // Generated invoices lock their agreement before the invoice and
            // selected-time invoices lock the invoice before their entries.
            // Take both families in that order so an edit cannot deadlock a
            // concurrent refresh. Locking all agreements for this company also
            // prevents a new cadence draft from selecting the entry between
            // the allocation read and the entry write.
            ClientAgreement::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $probe->client_company_id)
                ->orderBy('id')
                ->tap(Locks::forUpdate())
                ->get(['id']);

            $prelinkedInvoiceIds = $this->allocatedInvoiceIds($probe);
            $lockedInvoiceIds = ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where(function (Builder $query) use ($probe, $prelinkedInvoiceIds): void {
                    $query->where('client_company_id', $probe->client_company_id);
                    if ($prelinkedInvoiceIds !== []) {
                        $query->orWhereIn('id', $prelinkedInvoiceIds);
                    }
                })
                ->orderBy('id')
                ->tap(Locks::forUpdate())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            // Named here and not only in `assertDraftEditable()`: the route
            // binding does not scope the entry, so without this the lock is
            // taken on another tenant's row and released by the refusal a
            // moment later. Nothing leaks, but an actor holding a foreign id
            // can make this workspace queue behind writes it cannot see.
            $locked = ClientTimeEntry::query()
                ->whereKey($entry->id)
                ->where('workspace_id', $workspace->id)
                ->tap(Locks::forUpdate())
                ->first();
            abort_unless($locked instanceof ClientTimeEntry, 404);

            $invoice = $this->allocatedInvoice($locked);
            abort_unless(
                ! $invoice instanceof ClientInvoice || in_array($invoice->id, $lockedInvoiceIds, true),
                409,
                'The time entry invoice allocation changed; read it and retry.',
            );

            return $write($locked, $invoice);
        });
    }

    /** @param list<array{id: string, expected_version: string, billing_rate_amount?: int, currency?: string}> $entries */
    public function approve(Workspace $workspace, User $actor, array $entries): void
    {
        DB::transaction(function () use ($workspace, $actor, $entries): void {
            foreach ($entries as $item) {
                $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $item['id'])->tap(Locks::forUpdate())->firstOrFail();
                abort_unless($this->access->canApproveTime($actor, $this->projectOf($workspace, $entry)), 403);
                abort_unless($entry->status === 'draft', 409, 'Only draft time entries can be approved.');
                // The same freeze update and delete carry, for the same
                // reason and then some: approval is where the rate is stamped,
                // so approving attached time changes what the line bills
                // without touching the line. Status alone does not catch it -
                // an entry stays `draft` until approved, invoice or no.
                abort_if(
                    $this->isAllocated($entry),
                    409,
                    'This time entry is already on an invoice. Regenerate or void that invoice to change it.',
                );
                abort_unless(AgentApiVersion::matches($entry, $item['expected_version']), 409, 'The time entry has changed; read it and retry.');
                $rate = $this->approvalRate($workspace, $entry, $item);
                $entry->forceFill([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => $this->clock->now($workspace),
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
    private function approvalRate(Workspace $workspace, ClientTimeEntry $entry, array $item): array
    {
        if (! $entry->is_billable) {
            return ['amount' => null, 'currency' => $entry->currency, 'source' => null];
        }

        $hasAmount = array_key_exists('billing_rate_amount', $item);
        $hasCurrency = array_key_exists('currency', $item);
        $rawMode = $entry->getRawOriginal('subcontractor_billing_mode');
        $mode = $rawMode === null ? null : SubcontractorBillingMode::tryFrom((string) $rawMode);
        if ($rawMode !== null && ! $mode instanceof SubcontractorBillingMode) {
            throw new DomainException('The time entry has an unsupported subcontractor billing mode.');
        }
        if ($entry->subcontractor_cost_amount !== null && $mode !== SubcontractorBillingMode::FlatHourly) {
            throw new DomainException('Only flat-hourly subcontractor time may carry a subcontractor rate.');
        }

        if ($mode === SubcontractorBillingMode::FlatHourly || $mode === SubcontractorBillingMode::Direct) {
            if ($hasAmount || $hasCurrency) {
                throw new DomainException('A flat-hourly or direct subcontractor entry does not use an ordinary billing-rate override.');
            }
            if ($mode === SubcontractorBillingMode::FlatHourly
                && ($entry->subcontractor_cost_amount === null || trim((string) $entry->subcontractor_cost_currency) === '')) {
                throw new DomainException('Flat-hourly subcontractor time requires a snapshotted amount and currency.');
            }

            return ['amount' => null, 'currency' => $entry->currency, 'source' => null];
        }
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

        // A rate already stated on the entry is not a default to be replaced.
        // `TimeEntryWorkflow` records one when the operator types it at the
        // point of logging and marks it `explicit`; resolving the agreement
        // rate over the top means the invoice charges a number nobody entered,
        // silently, on an approval that asked for no change of rate.
        if ($entry->billing_rate_source === 'explicit' && $entry->billing_rate_amount !== null) {
            return [
                'amount' => $entry->billing_rate_amount,
                // The amount is the operator's statement; a missing currency
                // is not, and rows predating the workflow's default still
                // carry one. Resolving the agreement rate would discard the
                // amount, which is the thing this branch exists to keep.
                'currency' => $entry->currency ?? $workspace->default_currency,
                'source' => 'explicit',
            ];
        }

        return $this->rates->resolve($entry) + ['source' => 'agreement'];
    }

    private function assertDraftEditable(
        Workspace $workspace,
        ClientTimeEntry $entry,
        User $actor,
        ?ClientInvoice $invoice,
    ): void {
        abort_unless($entry->workspace_id === $workspace->id, 404);
        if ($invoice instanceof ClientInvoice) {
            abort_unless(
                $invoice->client_company_id === $entry->client_company_id,
                409,
                'The time entry invoice allocation has an inconsistent client company.',
            );
            abort_unless($invoice->status === 'draft', 409, 'Time on an issued, paid, void, or unknown invoice cannot be changed.');
            abort_unless(in_array($entry->status, ['draft', 'approved'], true), 409, 'Only unissued time entries can be changed.');
        } else {
            abort_unless($entry->status === 'draft', 409, 'Only draft time entries can be changed.');
        }
        $project = $this->projectOf($workspace, $entry);
        abort_unless($entry->user_id === $actor->id || $this->access->isWorkspaceManager($actor, $workspace), 403);
        abort_unless($this->access->canView($actor, $project), 404);
        abort_unless($this->access->canLogTime($actor, $project), 403);
    }

    private function taskFor(Workspace $workspace, ClientTimeEntry $entry, mixed $taskId): ?int
    {
        if (! is_string($taskId) || $taskId === '') {
            return null;
        }

        $task = ClientTask::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $taskId)
            ->firstOrFail();

        abort_unless(
            $task->client_project_id === $entry->client_project_id,
            422,
            'The task must belong to the selected project.',
        );

        return $task->id;
    }

    /**
     * The entry's project, once it is established to be this workspace's.
     *
     * Every permission on this screen is asked of the project, and
     * `ProjectAccess` resolves a role against the project's *own* workspace.
     * So an entry of workspace A pointing at a project of workspace B - which
     * the schema permits, the keys being independent - had its approval
     * authorised against B: an actor who manages B and merely views A could
     * approve A's time and stamp its rate. The project has to be shown to
     * belong here before it is allowed to answer for anything.
     */
    private function projectOf(Workspace $workspace, ClientTimeEntry $entry): ClientProject
    {
        $project = ClientProject::query()
            ->whereKey($entry->client_project_id)
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $entry->client_company_id)
            // The company closes the chain. Matching the project to the entry
            // proves only that the two agree with each other; both can name a
            // company of another workspace, and the walk leaves this tenant
            // at the last link rather than the first.
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company
                ->where('workspace_id', $workspace->id))
            ->first();

        abort_unless($project instanceof ClientProject, 404);

        return $project;
    }

    /**
     * Is a line of this entry's own workspace billing it?
     *
     * Line, pivot and invoice each carry their own `workspace_id` and the
     * schema does not require the three to agree, so every one of them is
     * named. An unscoped check lets another workspace's row freeze this one's
     * entry - a refusal the rightful owner cannot see the cause of or clear -
     * and a partly scoped one disagrees with the sheet, which decides the
     * badge from all three: the entry would refuse every write while showing
     * no invoice to explain why.
     */
    private function isAllocated(ClientTimeEntry $entry): bool
    {
        return $entry->invoiceLines()
            ->where('client_invoice_lines.workspace_id', $entry->workspace_id)
            ->wherePivot('workspace_id', $entry->workspace_id)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('workspace_id', $entry->workspace_id))
            ->exists();
    }

    /** A hidden foreign pivot must never change local invoice selection. */
    private function assertNoForeignAllocations(Workspace $workspace, ClientTimeEntry $entry): void
    {
        $hasForeignAllocation = DB::table('client_invoice_line_time_entries as pivot')
            ->join('client_invoice_lines as lines', 'lines.id', '=', 'pivot.client_invoice_line_id')
            ->join('client_invoices as invoices', 'invoices.id', '=', 'lines.client_invoice_id')
            ->where('pivot.client_time_entry_id', $entry->id)
            ->where(function ($query) use ($workspace, $entry): void {
                $query->whereNull('pivot.workspace_id')
                    ->orWhere('pivot.workspace_id', '!=', $workspace->id)
                    ->orWhereNull('lines.workspace_id')
                    ->orWhere('lines.workspace_id', '!=', $workspace->id)
                    ->orWhereNull('invoices.workspace_id')
                    ->orWhere('invoices.workspace_id', '!=', $workspace->id)
                    ->orWhereNull('invoices.client_company_id')
                    ->orWhere('invoices.client_company_id', '!=', $entry->client_company_id);
            })
            ->exists();

        abort_if(
            $hasForeignAllocation,
            409,
            'The time entry has an invoice allocation owned by another workspace or client company.',
        );
    }

    /** @return list<int> */
    private function allocatedInvoiceIds(ClientTimeEntry $entry): array
    {
        return array_values($entry->invoiceLines()
            ->where('client_invoice_lines.workspace_id', $entry->workspace_id)
            ->wherePivot('workspace_id', $entry->workspace_id)
            ->whereHas('invoice', fn (Builder $invoice): Builder => $invoice
                ->where('workspace_id', $entry->workspace_id))
            ->pluck('client_invoice_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all());
    }

    private function allocatedInvoice(ClientTimeEntry $entry): ?ClientInvoice
    {
        $invoiceIds = $this->allocatedInvoiceIds($entry);
        abort_unless(count($invoiceIds) <= 1, 409, 'The time entry is allocated to more than one invoice.');

        if ($invoiceIds === []) {
            return null;
        }

        return ClientInvoice::query()
            ->whereKey($invoiceIds[0])
            ->where('workspace_id', $entry->workspace_id)
            ->first();
    }

    private function assertClientDescription(bool $visible, mixed $description): void
    {
        if ($visible && (! is_string($description) || trim($description) === '')) {
            throw new DomainException('Client-visible time requires an explicit client-facing description.');
        }
    }
}
