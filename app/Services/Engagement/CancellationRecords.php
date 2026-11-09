<?php

namespace App\Services\Engagement;

use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Support\Concurrency\Locks;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Engagement\Cancellation\ScheduledTerminationStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The tenant-scoped persistence boundary of cancellation: an agreement's open
 * request and scheduled end, read with a lock, and the bounded reads every
 * screen and the agent API present them from.
 *
 * Every locking read here is made by a caller already holding the agreement's
 * row lock, and in registry order after it: the request, then the scheduled
 * end. Locking reads, not ordinary ones, so a transaction whose snapshot is
 * older than the agreement's lock still sees a request or an end committed
 * just before it.
 */
final class CancellationRecords
{
    /** The agreement's pending request, if any, locked. */
    public function lockPending(ClientAgreement $locked): ?AgreementCancellationRequest
    {
        return AgreementCancellationRequest::query()
            ->where('workspace_id', $locked->workspace_id)
            ->where('client_agreement_id', $locked->id)
            ->where('status', CancellationRequestStatus::Pending->value)
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->first();
    }

    /** One of the agreement's requests, locked; not found outside it. */
    public function lockRequest(ClientAgreement $locked, int $requestId): AgreementCancellationRequest
    {
        return AgreementCancellationRequest::query()
            ->where('workspace_id', $locked->workspace_id)
            ->where('client_agreement_id', $locked->id)
            ->whereKey($requestId)
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    /** The agreement's scheduled end, if any, locked. At most one is scheduled. */
    public function lockScheduled(ClientAgreement $locked): ?AgreementScheduledTermination
    {
        return AgreementScheduledTermination::query()
            ->where('workspace_id', $locked->workspace_id)
            ->where('client_agreement_id', $locked->id)
            ->where('status', ScheduledTerminationStatus::Scheduled->value)
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->first();
    }

    /**
     * The agreement has been terminated, on its scheduled date or otherwise:
     * its scheduled end is carried out and a request still pending is closed,
     * so nothing waits on an agreement that has ended. Under the agreement's
     * lock, inside the terminating transaction.
     */
    public function closeOnTermination(ClientAgreement $locked, CarbonImmutable $at): void
    {
        $pending = $this->lockPending($locked);
        $scheduled = $this->lockScheduled($locked);
        $pending?->forceFill([
            'status' => CancellationRequestStatus::Declined->value,
            'decided_at' => $at,
            'decision_note' => 'The agreement was terminated.',
        ])->save();
        $scheduled?->forceFill([
            'status' => ScheduledTerminationStatus::Terminated->value,
            'terminated_at' => $at,
        ])->save();
    }

    /**
     * Eager loads for a query of agreements: each one's pending request (with
     * who asked) and scheduled end, two bounded reads per page, read back by
     * {@see CancellationStates::loaded()}.
     *
     * @return array<string, Closure>
     */
    public static function openRelations(int $workspaceId): array
    {
        return [
            'cancellationRequests' => static fn (HasMany $requests) => $requests->where('workspace_id', $workspaceId)
                ->where('status', CancellationRequestStatus::Pending->value)->with(['requestedBy:id,name'])->orderBy('id'),
            'scheduledTerminations' => static fn (HasMany $ends) => $ends->where('workspace_id', $workspaceId)
                ->where('status', ScheduledTerminationStatus::Scheduled->value)->orderBy('id'),
        ];
    }

    /**
     * The open request and the scheduled end of each agreement, two bounded
     * reads whatever the number of agreements.
     *
     * @param  list<int>  $agreementIds
     * @return array{pending: Collection<int, AgreementCancellationRequest>, scheduled: Collection<int, AgreementScheduledTermination>} each keyed by agreement id
     */
    public function openFor(int $workspaceId, array $agreementIds): array
    {
        if ($agreementIds === []) {
            return ['pending' => new Collection, 'scheduled' => new Collection];
        }

        return [
            'pending' => AgreementCancellationRequest::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('client_agreement_id', $agreementIds)
                ->where('status', CancellationRequestStatus::Pending->value)
                ->with(['requestedBy:id,name'])
                ->orderBy('id')
                ->get()
                ->keyBy('client_agreement_id')
                ->toBase(),
            'scheduled' => AgreementScheduledTermination::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('client_agreement_id', $agreementIds)
                ->where('status', ScheduledTerminationStatus::Scheduled->value)
                ->orderBy('id')
                ->get()
                ->keyBy('client_agreement_id')
                ->toBase(),
        ];
    }
}
