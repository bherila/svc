<?php

namespace App\Services\Revenue;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\Revenue\AgreementProjection;
use App\Support\Revenue\ClientRevenue;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueQuery;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The planning reads behind `revenue.summary`, `revenue.monthly`,
 * `revenue.projection` and `revenue.clients` (docs/ux-overhaul.md section
 * 13), for workspace managers only. Each read loads its inputs through the
 * tenant-scoped repositories, computes in memory, and returns the DTOs'
 * own arrays, so REST, MCP and the dashboard print the same figures.
 *
 * Everything runs in the request: there is no queue worker.
 */
final class RevenueReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly ProjectionInputsRepository $projectionInputs,
        private readonly RevenueActualsRepository $actuals,
        private readonly AgreementLedgerRepository $ledgers,
        private readonly RecurringRevenueProjector $projector = new RecurringRevenueProjector,
        private readonly RevenueSummaries $summaries = new RevenueSummaries,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User|AgentPrincipal $user, Workspace $workspace, RevenueQuery $query): array
    {
        [$assumptions, $clientId] = $this->context($user, $workspace, $query);
        $inputs = $this->projectionInputs->load($workspace, $assumptions->asOf);
        $projection = $this->projector->project($clientId === null ? $inputs : $inputs->forClient($clientId));

        return $this->summaries->summary($this->actuals->load($workspace, $assumptions->asOf), $inputs, $projection, $assumptions, $clientId)->toArray();
    }

    /** @return array{assumptions: array<string, mixed>, months: list<array<string, mixed>>} */
    public function monthly(User|AgentPrincipal $user, Workspace $workspace, RevenueQuery $query): array
    {
        [$assumptions, $clientId] = $this->context($user, $workspace, $query);
        $inputs = $this->projectionInputs->load($workspace, $assumptions->asOf);
        $projection = $this->projector->project($clientId === null ? $inputs : $inputs->forClient($clientId));

        return [
            'assumptions' => $assumptions->toArray(),
            'band' => $query->band,
            'months' => $this->summaries->monthly($this->actuals->load($workspace, $assumptions->asOf), $projection, $assumptions, $query->band, $clientId),
        ];
    }

    /** @return array{assumptions: array<string, mixed>, agreements: list<array<string, mixed>>} */
    public function projection(User|AgentPrincipal $user, Workspace $workspace, RevenueQuery $query): array
    {
        [$assumptions, $clientId] = $this->context($user, $workspace, $query);
        $inputs = $this->projectionInputs->load($workspace, $assumptions->asOf);
        $projection = $this->projector->project($clientId === null ? $inputs : $inputs->forClient($clientId));

        return [
            'assumptions' => $assumptions->toArray(),
            'agreements' => array_map(
                static fn (AgreementProjection $agreement): array => $agreement->toArray($assumptions->includeProjectedOverage),
                $projection->agreements,
            ),
        ];
    }

    /** @return array{assumptions: array<string, mixed>, clients: list<array<string, mixed>>, totals: array<string, int>} */
    public function clients(User|AgentPrincipal $user, Workspace $workspace, RevenueQuery $query): array
    {
        [$assumptions, $clientId] = $this->context($user, $workspace, $query);
        $inputs = $this->projectionInputs->load($workspace, $assumptions->asOf);
        $actuals = $this->actuals->load($workspace, $assumptions->asOf);
        if ($clientId !== null) {
            $inputs = $inputs->forClient($clientId);
            $actuals = $actuals->forClient($clientId);
        }
        $roster = $this->summaries->clients($actuals, $inputs, $assumptions, $this->ledgers->inForce($workspace, $inputs));

        return [
            'assumptions' => $assumptions->toArray(),
            'clients' => array_map(static fn (ClientRevenue $row): array => $row->toArray(), $roster['clients']),
            'totals' => $roster['totals'],
        ];
    }

    /**
     * The assumptions of this read and the client it narrows to, after the
     * caller is shown to manage the workspace. A non-manager, and a client of
     * another workspace, are not found.
     *
     * @return array{RevenueAssumptions, int|null}
     */
    private function context(User|AgentPrincipal $user, Workspace $workspace, RevenueQuery $query): array
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(Workspace::class);
        }
        $clientId = null;
        if ($query->clientId !== null) {
            $clientId = (int) ClientCompany::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('public_id', $query->clientId)
                ->firstOrFail()
                ->getKey();
        }

        return [new RevenueAssumptions(
            $this->clock->today($workspace),
            $query->basis,
            $query->includeExpenses,
            $query->includeProjectedOverage,
            $workspace->getAttribute('default_currency'),
        ), $clientId];
    }
}
