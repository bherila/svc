<?php

namespace App\Services\Engagement;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Engagement\ProposalAcceptanceAgreementQuery;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\WorkspaceAuthorization;
use App\Support\WorkspaceClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ProposalWorkflow
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ClientActivityRecorder $activities,
        private readonly ProposalAcceptanceAgreementQuery $acceptanceAgreements,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Workspace $workspace,
        ClientCompany $company,
        ?ClientProject $project,
        User $creator,
        array $attributes,
    ): ClientProposal {
        $this->assertParents($workspace, $company, $project);

        return DB::transaction(function () use ($workspace, $company, $project, $creator, $attributes): ClientProposal {
            $proposal = ClientProposal::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project?->id,
                'created_by_user_id' => $creator->id,
                'title' => $attributes['title'],
                'summary' => $attributes['summary'] ?? null,
                'terms' => $attributes['terms'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
                'currency' => strtoupper($attributes['currency']),
                'is_visible_to_client' => $attributes['is_visible_to_client'] ?? false,
                'status' => 'draft',
            ]);

            foreach ($attributes['items'] ?? [] as $index => $item) {
                $proposal->items()->create([
                    'workspace_id' => $workspace->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_amount' => $item['unit_amount'],
                    'cadence' => $item['cadence'] ?? 'one_time',
                    'sort_order' => $item['sort_order'] ?? $index,
                ]);
            }

            return $proposal->load('items');
        });
    }

    public function send(ClientProposal $proposal): ClientProposal
    {
        return DB::transaction(function () use ($proposal): ClientProposal {
            $locked = ClientProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status === 'sent') {
                return $locked->load('items');
            }

            if ($locked->status !== 'draft') {
                throw new EngagementException('Only draft proposals can be sent.');
            }

            $locked->forceFill([
                'status' => 'sent',
                'sent_at' => $this->clock->now($locked->workspace),
                'is_visible_to_client' => true,
            ])->save();

            return $locked->load('items');
        });
    }

    public function accept(ClientProposal $proposal, ?User $acceptingUser, string $signerName, ?string $signerTitle): ClientProposal
    {
        return DB::transaction(function () use ($proposal, $acceptingUser, $signerName, $signerTitle): ClientProposal {
            $locked = ClientProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status === 'accepted') {
                return $locked->load(['items', 'agreements']);
            }

            if ($locked->status !== 'sent') {
                throw new EngagementException('Only sent proposals can be accepted.');
            }

            if ($locked->valid_until !== null && $locked->valid_until->isBefore($this->clock->today($locked->workspace))) {
                throw new EngagementException('This proposal has expired.');
            }

            $this->acceptanceAgreements->lockCompany($locked->workspace_id, $locked->client_company_id);
            $agreement = $this->acceptanceAgreements->linkedAgreement($locked);

            if ($agreement === null && $this->acceptanceAgreements->hasActiveUnlinkedAgreement($locked)) {
                throw new EngagementException('This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.');
            }

            $acceptedAt = $this->clock->now($locked->workspace);
            $locked->forceFill([
                'status' => 'accepted',
                'accepted_at' => $acceptedAt,
                'accepted_by_user_id' => $acceptingUser?->id,
                'acceptance_signer_name' => $signerName,
                'acceptance_signer_title' => $signerTitle,
            ])->save();

            if ($agreement === null) {
                try {
                    $agreement = $locked->agreements()->create([
                        'workspace_id' => $locked->workspace_id,
                        'client_company_id' => $locked->client_company_id,
                        'client_project_id' => $locked->client_project_id,
                        'source_proposal_id' => $locked->id,
                        'title' => $locked->title,
                        'status' => 'active',
                        'starts_on' => $acceptedAt->toDateString(),
                        'ends_on' => null,
                        'agreement_text' => $this->agreementText($locked->summary, $locked->terms),
                        'is_visible_to_client' => $locked->is_visible_to_client,
                        'currency' => $locked->currency,
                        'billing_cadence' => $this->agreementCadence($locked),
                        'activated_at' => $acceptedAt,
                        'signed_at' => $acceptedAt,
                        'signed_by_user_id' => $acceptingUser?->id,
                        'signer_name' => $signerName,
                        'signer_title' => $signerTitle,
                    ]);
                } catch (UniqueConstraintViolationException $collision) {
                    // The source-proposal key is globally unique. A malformed
                    // row in another tenant can therefore claim this proposal
                    // without being visible to the scoped linked lookup. Let
                    // the database arbitrate that race, roll back every earlier
                    // acceptance write, and disclose no foreign row details.
                    throw new EngagementException(
                        'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
                        previous: $collision,
                    );
                }

                foreach ($locked->items as $item) {
                    $agreement->recurringItems()->create([
                        'workspace_id' => $locked->workspace_id,
                        'description' => $item->description,
                        'cadence' => $item->cadence,
                        'amount' => $item->unit_amount,
                        'quantity' => $item->quantity,
                        'currency' => $locked->currency,
                        'sort_order' => $item->sort_order,
                        'is_active' => true,
                    ]);
                }

                $workspace = $locked->workspace;
                $company = $locked->clientCompany;
                $this->activities->record($workspace, $company, 'agreement.created', $agreement, [
                    'status' => 'active',
                    'billing_cadence' => $agreement->billing_cadence,
                ], $acceptingUser);
                $this->activities->record($workspace, $company, 'agreement.activated', $agreement, [
                    'status' => 'active',
                    'source' => 'proposal_acceptance',
                ], $acceptingUser);
                $this->activities->record($workspace, $company, 'agreement.signed', $agreement, [
                    'status' => 'active',
                ], $acceptingUser);
            }

            return $locked->load(['items', 'agreements.recurringItems']);
        });
    }

    private function assertParents(Workspace $workspace, ClientCompany $company, ?ClientProject $project): void
    {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $company)) {
            throw new EngagementException('The client company does not belong to this workspace.');
        }

        if ($project !== null && (! $this->workspaceAuthorization->isOwnedBy($workspace, $project) || $project->client_company_id !== $company->id)) {
            throw new EngagementException('The client project does not belong to this client company and workspace.');
        }
    }

    private function agreementText(?string $summary, ?string $terms): string
    {
        return collect([$summary, $terms])->filter(static fn (?string $value): bool => $value !== null && $value !== '')
            ->implode("\n\n");
    }

    private function agreementCadence(ClientProposal $proposal): string
    {
        $cadences = $proposal->items->pluck('cadence')->filter()->unique()->values();

        return $cadences->count() === 1 ? (string) $cadences->first() : 'one_time';
    }
}
