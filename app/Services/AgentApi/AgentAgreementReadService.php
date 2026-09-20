<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\CursorPage;
use App\Support\AgentApi\Presenters\AgreementReadPresenter;
use Illuminate\Database\Eloquent\Collection;
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
            ->with(['project', 'clientCompany'])
            ->orderBy('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return CursorPage::run(
            $workspace,
            'agreements|status='.($status ?? ''),
            $limit,
            $cursor,
            fn (int $take, ?int $after): Collection => $query->when($after !== null, fn ($rows) => $rows->where('id', '>', $after))->limit($take)->get(),
            $this->present(...),
        );
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $user, Workspace $workspace, string $agreementId): array
    {
        $this->requireManager($user, $workspace);
        $agreement = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $agreementId)
            ->with(['project', 'clientCompany'])
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
     * The derived terms, plus who the agreement is with.
     *
     * The web UI reads an agreement from beneath its client, so the shared
     * presenter never had to say which one. An MCP caller gets the record on its
     * own, and without these an agreement cannot be tied to its client - or to
     * the project it is scoped to - from the response alone.
     *
     * @return array<string, mixed>
     */
    private function present(ClientAgreement $agreement): array
    {
        return [
            ...$this->presenter->present($agreement, $agreement->project?->name),
            'client_id' => $agreement->clientCompany->public_id,
            'client_name' => $agreement->clientCompany->name,
            'project_id' => $agreement->project?->public_id,
        ];
    }
}
