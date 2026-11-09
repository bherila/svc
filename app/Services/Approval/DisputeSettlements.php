<?php

namespace App\Services\Approval;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\BillingMaintenance;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use App\Support\Approval\LateAnswer;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The provider settles a client's dispute on one hour or one expense
 * (docs/ux-overhaul.md 7b″).
 *
 * A dispute holds the row out of billing. Settling ends the hold one of two
 * ways ({@see DisputeSettlement}), always with a reply the client reads:
 *
 * - **answered**: the dispute is cleared and the row goes back on the client's
 *   To approve as undecided. It bills on the next draft; once this commits,
 *   the generated draft whose period covers it is rebuilt to take it back.
 * - **written off**: the row is never billed. An hour becomes non-billable; an
 *   expense keeps the settlement, which every billing read skips. A draft that
 *   still carried it is rebuilt without it.
 *
 * Only the provider's side settles, inside its own workspace: another
 * workspace's row is not found. Answering an hour is open to whoever may edit
 * it, its author included - answering is not approving, and the client still
 * decides. Writing one off is a commercial decision and needs the permission to
 * approve time on its project. Expenses are settled by a workspace manager,
 * who is also who approves them.
 *
 * Each settlement is a compare-and-set on the row's version under its lock, and
 * is written to the client's history in the same transaction.
 */
final class DisputeSettlements
{
    public const int REPLY_MAX = 1000;

    public const string CHARGED_MESSAGE = 'An issued invoice already charges this. Answer the dispute, and correct the invoice if the charge should change.';

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly DisputedDraftRebuilder $drafts,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public function settleTime(Workspace $workspace, string $entryId, User $actor, DisputeSettlement $outcome, string $reply, string $expectedVersion): ClientTimeEntry
    {
        $reply = $this->reply($reply);

        return DB::transaction(function () use ($workspace, $entryId, $actor, $outcome, $reply, $expectedVersion): ClientTimeEntry {
            // A time-entry writer: the maintenance share lock comes first, as
            // for every other (docs/ux-overhaul.md 7b″, LockOrderConformanceTest).
            BillingMaintenance::share($workspace->id);
            $entry = ClientTimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $entryId)
                ->tap(Locks::forUpdate())
                ->first();
            abort_unless($entry instanceof ClientTimeEntry, 404);
            $project = ClientProject::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $entry->client_company_id)
                ->whereKey($entry->client_project_id)
                ->first();
            abort_unless($project instanceof ClientProject && $this->access->canView($actor, $project), 404);
            abort_unless(match ($outcome->needsApprover()) {
                true => $this->access->canApproveTime($actor, $project),
                false => $entry->user_id === $actor->id || $this->access->isWorkspaceManager($actor, $workspace),
            }, 403, $outcome->needsApprover()
                ? 'Only someone who may approve time on this project can write it off.'
                : 'Only the person who logged this time, or a workspace owner or admin, can answer the dispute.');

            $this->assertOpen($entry, $expectedVersion);
            // Disputed and charged by no issued invoice; otherwise the charge stands.
            $held = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereKey($entry->id)->heldForDispute()->exists();
            abort_if($outcome === DisputeSettlement::WrittenOff && ! $held, 409, self::CHARGED_MESSAGE);
            $this->write(ClientTimeEntry::query(), $workspace, $entry, $actor, $outcome, $reply, match ($outcome) {
                // Answered after its own period was reconciled: it can no
                // longer bill there, so it becomes deferred work.
                DisputeSettlement::Answered => LateAnswer::defers(
                    is_string($mode = $entry->getRawOriginal('subcontractor_billing_mode')) ? $mode : null,
                    $this->periodCharged($workspace, $entry),
                    ! $held,
                ) ? ['is_deferred' => true] : [],
                DisputeSettlement::WrittenOff => ['is_billable' => false],
            });
            $this->record($workspace, (int) $entry->client_company_id, 'time', $entry->public_id, (int) $entry->lock_version, $entry->client_dispute_note, $outcome, $reply, $actor);
            $this->afterCommit((int) $workspace->id, $outcome, [(int) $entry->id], []);

            return $entry->fresh() ?? $entry;
        });
    }

    public function settleExpense(Workspace $workspace, string $expenseId, User $actor, DisputeSettlement $outcome, string $reply, string $expectedVersion): ClientExpense
    {
        $reply = $this->reply($reply);

        return DB::transaction(function () use ($workspace, $expenseId, $actor, $outcome, $reply, $expectedVersion): ClientExpense {
            BillingMaintenance::share($workspace->id);
            abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403, 'Only a workspace owner or admin can settle a dispute on an expense.');
            $expense = ClientExpense::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $expenseId)
                ->tap(Locks::forUpdate())
                ->first();
            abort_unless($expense instanceof ClientExpense, 404);

            $this->assertOpen($expense, $expectedVersion);
            abort_if($outcome === DisputeSettlement::WrittenOff && $this->charged($workspace, $expense), 409, self::CHARGED_MESSAGE);
            $this->write(ClientExpense::query(), $workspace, $expense, $actor, $outcome, $reply, []);
            $this->record($workspace, (int) $expense->client_company_id, 'expense', $expense->public_id, (int) $expense->lock_version, $expense->client_dispute_note, $outcome, $reply, $actor);
            $this->afterCommit((int) $workspace->id, $outcome, [], [(int) $expense->id]);

            return $expense->fresh() ?? $expense;
        });
    }

    /** Does an issued, partially paid, paid or disputed invoice of this workspace carry the expense? */
    private function charged(Workspace $workspace, ClientExpense $expense): bool
    {
        return $expense->client_invoice_line_id !== null && ClientInvoiceLine::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($expense->client_invoice_line_id)
            ->whereHas('invoice', fn ($invoice) => $invoice
                ->where('client_invoices.workspace_id', $workspace->id)
                ->whereIn('client_invoices.status', InvoiceStatus::charged()))
            ->exists();
    }

    private function reply(string $reply): string
    {
        $reply = trim($reply);
        abort_if($reply === '', 422, 'Write a reply for the client.');
        abort_if(mb_strlen($reply) > self::REPLY_MAX, 422, 'A reply is at most '.self::REPLY_MAX.' characters.');

        return $reply;
    }

    /**
     * Whether a charged cadence invoice of the entry's agreement scope covers
     * its date: the invoice that reconciled its period. An ordinary read - it
     * decides only how the answered hour waits, and the hour's own lock is
     * what this transaction serialises on.
     */
    private function periodCharged(Workspace $workspace, ClientTimeEntry $entry): bool
    {
        $day = $entry->worked_on->toDateString();

        return ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $entry->client_company_id)
            ->whereIn('status', InvoiceStatus::charged())
            ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->whereHas('agreement', fn ($agreement) => $agreement
                ->where('workspace_id', $workspace->id)
                ->where(fn ($scope) => $scope->whereNull('client_project_id')->orWhere('client_project_id', $entry->client_project_id)))
            ->whereDate('service_period_start', '<=', $day)
            ->whereDate('service_period_end', '>=', $day)
            ->exists();
    }

    private function assertOpen(ClientTimeEntry|ClientExpense $row, string $expectedVersion): void
    {
        abort_unless(AgentApiVersion::matches($row, $expectedVersion), 409, 'This row has changed; read it and retry.');
        abort_if($row->client_disputed_at === null, 422, 'There is no open dispute on this row to settle.');
    }

    /**
     * @param  Builder<ClientTimeEntry>|Builder<ClientExpense>  $query
     * @param  array<string, mixed>  $extra
     */
    private function write($query, Workspace $workspace, ClientTimeEntry|ClientExpense $row, User $actor, DisputeSettlement $outcome, string $reply, array $extra): void
    {
        $written = $query
            ->where('workspace_id', $workspace->id)
            ->whereKey($row->id)
            ->where('lock_version', $row->lock_version)
            ->whereNotNull('client_disputed_at')
            ->update($extra + [
                'client_disputed_at' => null,
                'client_disputed_by_user_id' => null,
                'client_dispute_note' => null,
                'client_dispute_settlement' => $outcome->value,
                'client_dispute_reply' => $reply,
                'client_dispute_settled_at' => $this->clock->now($workspace),
                'client_dispute_settled_by_user_id' => $actor->id,
                'lock_version' => DB::raw('lock_version + 1'),
            ]);
        abort_unless($written === 1, 409, 'This row has changed; read it and retry.');
    }

    /**
     * The company's history, one event per settled dispute: the occurrence is
     * the row and the version it was disputed at, which every new dispute
     * moves, so two rows - or two disputes of one row - never share a key.
     */
    private function record(Workspace $workspace, int $companyId, string $kind, string $publicId, int $disputedVersion, ?string $note, DisputeSettlement $outcome, string $reply, User $actor): void
    {
        $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($companyId)->first();
        if (! $company instanceof ClientCompany) {
            return;
        }

        $this->activities->record($workspace, $company, 'dispute.'.$outcome->value, $company, [
            'kind' => $kind, 'id' => $publicId, 'dispute_note' => $note, 'reply' => $reply,
        ], $actor, occurrence: $kind.':'.$publicId.':'.$disputedVersion);
    }

    /**
     * @param  list<int>  $entryIds
     * @param  list<int>  $expenseIds
     */
    private function afterCommit(int $workspaceId, DisputeSettlement $outcome, array $entryIds, array $expenseIds): void
    {
        DB::afterCommit(fn () => match ($outcome->bills()) {
            true => $this->drafts->restore($workspaceId, $entryIds, $expenseIds),
            false => $this->drafts->withdraw($workspaceId, $entryIds, $expenseIds),
        });
    }
}
