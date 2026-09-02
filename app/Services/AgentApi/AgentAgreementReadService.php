<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\Presenters\AgreementReadPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Manager-only agreement reads using the same derived public DTO as the UI. */
final class AgentAgreementReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly AgreementReadPresenter $presenter,
    ) {}

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function list(User|AgentPrincipal $user, Workspace $workspace, ?string $status, int $limit, ?string $cursor): array
    {
        $this->requireManager($user, $workspace);
        $query = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->with('project')
            ->orderBy('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $this->page($query, $workspace, $status, $limit, $cursor);
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $user, Workspace $workspace, string $agreementId): array
    {
        $this->requireManager($user, $workspace);
        $agreement = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $agreementId)
            ->with('project')
            ->firstOrFail();

        return $this->present($agreement);
    }

    private function requireManager(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->isWorkspaceManager($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(ClientAgreement::class);
        }
    }

    /**
     * @param  Builder<ClientAgreement>  $query
     * @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}}
     */
    private function page($query, Workspace $workspace, ?string $status, int $limit, ?string $cursor): array
    {
        $queryKey = 'agreements|status='.($status ?? '');
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $agreements = $query->limit($limit + 1)->get();
        $next = $agreements->count() > $limit ? $agreements->pop() : null;
        $data = [];
        foreach ($agreements as $agreement) {
            $data[] = $this->present($agreement);
        }

        return [
            'data' => $data,
            'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $agreements->last()->getKey(), $workspace->public_id, $queryKey)],
        ];
    }

    /** @return array<string, mixed> */
    private function present(ClientAgreement $agreement): array
    {
        return $this->presenter->present($agreement, $agreement->project?->name);
    }
}
