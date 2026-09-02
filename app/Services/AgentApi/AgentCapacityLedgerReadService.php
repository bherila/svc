<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Support\AgentApi\Presenters\CapacityLedgerPresenter;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** A bounded view of the canonical computed capacity ledger for one agreement. */
final class AgentCapacityLedgerReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly InvoiceLedgerBuilder $ledgers,
        private readonly CapacityLedgerPresenter $presenter,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @return array{agreement_id:string,through:string,months:list<array<string, int|float|string|bool|null>>} */
    public function get(User|AgentPrincipal $user, Workspace $workspace, string $agreementId, int $months): array
    {
        $this->requireManager($user, $workspace);
        $agreement = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $agreementId)
            ->with('clientCompany')
            ->firstOrFail();
        $through = Carbon::instance($this->clock->today($workspace));
        $ledger = $this->ledgers->buildAgreementLedgerThrough($agreement->clientCompany, $agreement, $through);
        $rows = array_slice($ledger, -$months);

        return [
            'agreement_id' => $agreement->public_id,
            'through' => $through->toDateString(),
            'months' => array_map($this->presenter->present(...), $rows),
        ];
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientAgreement::class);
        }
    }
}
