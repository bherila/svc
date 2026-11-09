<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Engagement\CancellationRecords;
use App\Services\Engagement\CancellationStates;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\CursorPage;
use App\Support\AgentApi\Presenters\AgreementReadPresenter;
use App\Support\Engagement\Cancellation\CancellationState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Manager-only agreement reads using the same derived public DTO as the UI. */
final class AgentAgreementReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly AgreementReadPresenter $presenter,
        private readonly CancellationStates $cancellations,
    ) {}

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function list(User|AgentPrincipal $user, Workspace $workspace, ?string $status, int $limit, ?string $cursor): array
    {
        $this->requireManager($user, $workspace);
        $query = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('clientCompany', fn ($company) => $company->where('workspace_id', $workspace->id))
            ->with(['project' => fn ($project) => $project->where('workspace_id', $workspace->id), 'clientCompany' => fn ($company) => $company->where('workspace_id', $workspace->id)])
            // Each page's cancellation state in two bounded reads, not one per row.
            ->with(CancellationRecords::openRelations($workspace->id))
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
            fn (ClientAgreement $agreement): array => $this->present($agreement, $this->cancellations->loaded($workspace, $agreement)),
        );
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $user, Workspace $workspace, string $agreementId): array
    {
        $this->requireManager($user, $workspace);
        $agreement = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $agreementId)
            ->whereHas('clientCompany', fn ($company) => $company->where('workspace_id', $workspace->id))
            ->with(['project' => fn ($project) => $project->where('workspace_id', $workspace->id), 'clientCompany' => fn ($company) => $company->where('workspace_id', $workspace->id)])
            ->firstOrFail();

        return $this->present($agreement, $this->cancellations->one($workspace, $agreement));
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
    private function present(ClientAgreement $agreement, CancellationState $cancellation): array
    {
        return [
            ...$this->presenter->present($agreement, $agreement->project?->name),
            'version' => AgentApiVersion::for($agreement),
            'client_id' => $agreement->clientCompany->public_id,
            'client_name' => $agreement->clientCompany->name,
            'project_id' => $agreement->project?->public_id,
            // Billed in Square (#438): SVC issues but never collects its invoices.
            'square_series_key' => $agreement->square_series_key,
            'show_deferred_backlog_to_client' => (bool) $agreement->show_deferred_backlog_to_client,
            // The series' Square location (#488); null for a marker from before markers named one.
            'square_location_id' => $agreement->square_location_id,
            // Cancellation terms (CAN-1), and where a cancellation stands:
            // the end dates offered today, any pending request and any
            // scheduled end (CAN-2, CAN-4, EDGE-9).
            'notice_days' => $cancellation->terms->noticeDays,
            'minimum_term_months' => $cancellation->terms->minimumTermMonths,
            'cancellation_mode' => $cancellation->terms->mode->value,
            'cancellation' => $cancellation->forOperator(),
        ];
    }
}
