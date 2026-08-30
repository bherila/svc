<?php

namespace App\Services\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\WorkspaceAuthorization;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgreementWorkflow
{
    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        Workspace $workspace,
        ClientCompany $company,
        ?ClientProject $project,
        ?ClientProposal $sourceProposal,
        array $attributes,
    ): ClientAgreement {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $company)) {
            throw new EngagementException('The client company does not belong to this workspace.');
        }

        if ($project !== null && (! $this->workspaceAuthorization->isOwnedBy($workspace, $project) || $project->client_company_id !== $company->id)) {
            throw new EngagementException('The client project does not belong to this client company and workspace.');
        }

        if ($sourceProposal !== null && (! $this->workspaceAuthorization->isOwnedBy($workspace, $sourceProposal) || $sourceProposal->client_company_id !== $company->id)) {
            throw new EngagementException('The source proposal does not belong to this client company and workspace.');
        }

        return DB::transaction(function () use ($workspace, $company, $project, $sourceProposal, $attributes): ClientAgreement {
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project?->id,
                'source_proposal_id' => $sourceProposal?->id,
                'title' => $attributes['title'],
                'status' => 'draft',
                'starts_on' => $attributes['starts_on'] ?? null,
                'ends_on' => $attributes['ends_on'] ?? null,
                'agreement_text' => $attributes['agreement_text'] ?? null,
                'is_visible_to_client' => $attributes['is_visible_to_client'] ?? false,
                'billing_cadence' => $attributes['billing_cadence'] ?? 'one_time',
                'currency' => strtoupper($attributes['currency']),
                'hourly_rate_amount' => $attributes['hourly_rate_amount'] ?? null,
                'retainer_amount' => $attributes['retainer_amount'] ?? null,
                'retainer_minutes' => $attributes['retainer_minutes'] ?? null,
            ]);
            $this->activities->record($workspace, $company, 'agreement.created', $agreement, [
                'status' => 'draft',
                'billing_cadence' => $agreement->billing_cadence,
            ]);

            return $agreement;
        });
    }

    public function activate(ClientAgreement $agreement): ClientAgreement
    {
        return DB::transaction(function () use ($agreement): ClientAgreement {
            $locked = ClientAgreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($locked->status === 'active') {
                return $locked;
            }

            if ($locked->status !== 'draft' && $locked->status !== 'paused') {
                throw new EngagementException('Only draft or paused agreements can be activated.');
            }

            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => 'active',
                'activated_at' => $locked->activated_at ?? $this->clock->now($locked->workspace),
            ])->save();
            $this->activities->record(
                $locked->workspace,
                $locked->clientCompany,
                'agreement.activated',
                $locked,
                ['changes' => ['status' => ['old' => $previousStatus, 'new' => 'active']]],
                occurrence: (string) Str::uuid(),
            );

            return $locked;
        });
    }

    public function sign(ClientAgreement $agreement, ?User $signingUser, string $signerName, ?string $signerTitle): ClientAgreement
    {
        return DB::transaction(function () use ($agreement, $signingUser, $signerName, $signerTitle): ClientAgreement {
            $locked = ClientAgreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($locked->signed_at !== null) {
                return $locked;
            }

            if ($locked->status !== 'active') {
                throw new EngagementException('Only active agreements can be signed.');
            }

            $locked->forceFill([
                'signed_at' => $this->clock->now($locked->workspace),
                'signed_by_user_id' => $signingUser?->id,
                'signer_name' => $signerName,
                'signer_title' => $signerTitle,
            ])->save();
            $this->activities->record(
                $locked->workspace,
                $locked->clientCompany,
                'agreement.signed',
                $locked,
                ['status' => $locked->status],
                $signingUser,
            );

            return $locked;
        });
    }
}
