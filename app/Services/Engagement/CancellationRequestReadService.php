<?php

namespace App\Services\Engagement;

use App\Models\AgentPrincipal;
use App\Models\AgreementCancellationRequest;
use App\Models\ClientAgreement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\CursorPage;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use App\Support\Engagement\Cancellation\CancellationState;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The workspace's cancellation requests for owners and admins
 * (`cancellation_requests.*`, CAN-4): newest first, each with its client,
 * agreement and decision, and - while pending - the dates it may be approved
 * on today. Anyone else, and any other workspace's request, is not found.
 *
 * Bounded: one page of requests with their agreements, companies and people,
 * then one read of the open scheduled ends of those agreements.
 */
final class CancellationRequestReadService
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, ?CancellationRequestStatus $status, int $limit, ?string $cursor): array
    {
        $query = $this->visible($actor, $workspace)->when($status !== null, fn (Builder $requests): Builder => $requests->where('status', $status?->value));

        return CursorPage::run(
            $workspace,
            'cancellation_requests|status='.($status->value ?? ''),
            $limit,
            $cursor,
            fn (int $take, ?int $after): Collection => $query->when($after !== null, fn (Builder $rows): Builder => $rows->where('id', '<', $after))->orderByDesc('id')->limit($take)->get(),
            fn (AgreementCancellationRequest $request): array => $this->present($workspace, $request),
        );
    }

    /** @return array<string, mixed> */
    public function get(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): array
    {
        return $this->present($workspace, $this->find($actor, $workspace, $publicId));
    }

    public function find(User|AgentPrincipal $actor, Workspace $workspace, string $publicId): AgreementCancellationRequest
    {
        return $this->visible($actor, $workspace)->where('public_id', $publicId)->firstOrFail();
    }

    /** @return Builder<AgreementCancellationRequest> */
    private function visible(User|AgentPrincipal $actor, Workspace $workspace): Builder
    {
        if (! $this->access->isWorkspaceManager($actor, $workspace)) {
            throw (new ModelNotFoundException)->setModel(AgreementCancellationRequest::class);
        }

        return AgreementCancellationRequest::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'agreement' => fn ($agreement) => $agreement->where('workspace_id', $workspace->id)->with(CancellationRecords::openRelations($workspace->id)),
                'clientCompany' => fn ($company) => $company->where('workspace_id', $workspace->id),
                'requestedBy:id,name',
                'decidedBy:id,name',
            ]);
    }

    /** @return array<string, mixed> */
    private function present(Workspace $workspace, AgreementCancellationRequest $request): array
    {
        $agreement = $request->agreement ?? throw new \LogicException('A cancellation request names an agreement outside its workspace.');
        $company = $request->clientCompany ?? throw new \LogicException('A cancellation request names a company outside its workspace.');
        $pending = $request->requestStatus() === CancellationRequestStatus::Pending;
        $state = CancellationState::of($agreement, $pending ? $request : null, $agreement->scheduledTerminations->first(), $this->clock->today($workspace));

        return [
            'id' => $request->public_id,
            'version' => AgentApiVersion::for($request),
            'status' => $request->status,
            'client' => ['id' => $company->public_id, 'name' => $company->name],
            'agreement' => [
                'id' => $agreement->public_id,
                'title' => $agreement->title,
                'status' => $agreement->status,
                'ends_on' => $agreement->ends_on?->toDateString(),
                'url' => self::agreementUrl($workspace, $company->public_id, $agreement),
            ],
            'requested_end_on' => $request->requested_end_on->toDateString(),
            'reason' => $request->reason,
            'requested_by' => $request->requestedBy?->name,
            'requested_at' => $request->requested_at->toIso8601ZuluString(),
            'decided_by' => $request->decidedBy?->name,
            'decided_at' => $request->decided_at?->toIso8601ZuluString(),
            'decided_end_on' => $request->decided_end_on?->toDateString(),
            'notice_waived' => (bool) $request->notice_waived,
            'decision_note' => $request->decision_note,
            'offered_end_dates' => $pending ? $state->offered() : [],
            'offered_end_dates_without_notice' => $pending ? $state->offered(true) : [],
        ];
    }

    private static function agreementUrl(Workspace $workspace, string $companyId, ClientAgreement $agreement): string
    {
        return route('clients.agreement', [$workspace, $companyId, $agreement], absolute: true);
    }
}
