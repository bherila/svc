<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientBillingSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\Presenters\BillingScheduleReadPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Tenant-scoped, manager-only visibility for recurring billing schedules. */
final class AgentBillingScheduleReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly BillingScheduleReadPresenter $presenter,
    ) {}

    /** @return array{data:list<array{id:string,agreement_id:string,cadence:string,next_run_on:string,is_active:bool}>,meta:array{next_cursor:?string}} */
    public function list(User|AgentPrincipal $user, Workspace $workspace, ?bool $active, int $limit, ?string $cursor): array
    {
        $this->requireManager($user, $workspace);
        $query = ClientBillingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->with('agreement')
            ->orderBy('id');
        if ($active !== null) {
            $query->where('is_active', $active);
        }
        $after = AgentApiCursor::decode($cursor);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $schedules = $query->limit($limit + 1)->get();
        $next = $schedules->count() > $limit ? $schedules->pop() : null;
        $data = [];
        foreach ($schedules as $schedule) {
            $data[] = $this->presenter->present($schedule);
        }

        return [
            'data' => $data,
            'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $schedules->last()->getKey())],
        ];
    }

    /** @return array{id:string,agreement_id:string,cadence:string,next_run_on:string,is_active:bool} */
    public function get(User|AgentPrincipal $user, Workspace $workspace, string $scheduleId): array
    {
        $this->requireManager($user, $workspace);
        $schedule = ClientBillingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $scheduleId)
            ->with('agreement')
            ->firstOrFail();

        return $this->presenter->present($schedule);
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientBillingSchedule::class);
        }
    }
}
