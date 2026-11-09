<?php

namespace App\Services\Engagement;

use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Support\Engagement\Cancellation\CancellationState;
use App\Support\WorkspaceClock;
use Illuminate\Support\Collection;

/**
 * The cancellation state of a set of agreements of one workspace, from two
 * bounded reads whatever their number, on the workspace's today.
 */
final class CancellationStates
{
    public function __construct(
        private readonly CancellationRecords $records,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @param  iterable<ClientAgreement>  $agreements
     * @return Collection<int, CancellationState> keyed by agreement id
     */
    public function for(Workspace $workspace, iterable $agreements): Collection
    {
        $agreements = collect($agreements)->filter(static fn (ClientAgreement $agreement): bool => (int) $agreement->workspace_id === (int) $workspace->id)->keyBy('id');
        ['pending' => $pending, 'scheduled' => $scheduled] = $this->records->openFor($workspace->id, array_values(array_map('intval', $agreements->keys()->all())));
        $today = $this->clock->today($workspace);

        return $agreements->map(static fn (ClientAgreement $agreement): CancellationState => CancellationState::of($agreement, $pending->get($agreement->id), $scheduled->get($agreement->id), $today));
    }

    /** One agreement loaded with {@see CancellationRecords::openRelations()}. */
    public function loaded(Workspace $workspace, ClientAgreement $agreement): CancellationState
    {
        return CancellationState::of($agreement, $agreement->cancellationRequests->first(), $agreement->scheduledTerminations->first(), $this->clock->today($workspace));
    }

    public function one(Workspace $workspace, ClientAgreement $agreement): CancellationState
    {
        return $this->for($workspace, [$agreement])->get($agreement->id) ?? throw new \LogicException('The agreement is not this workspace\'s.');
    }
}
