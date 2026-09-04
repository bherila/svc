<?php

namespace App\Services\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Engagement\ProposalAcceptanceAgreementQuery;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\WorkspaceAuthorization;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgreementWorkflow
{
    /**
     * The terms an operator may correct.
     *
     * Lifecycle is not here. `status`, `activated_at`, `signed_at` and the
     * signature fields have endpoints of their own that enforce the order of
     * events and record who did it; an agreement that can be marked signed by
     * editing a form is an agreement nobody signed.
     *
     * @var list<string>
     */
    private const EDITABLE = [
        'title', 'starts_on', 'ends_on', 'billing_cadence', 'currency', 'agreement_text',
        'is_visible_to_client', 'hourly_rate_amount', 'retainer_amount', 'retainer_minutes',
        'period_retainer_amount', 'period_retainer_minutes', 'catch_up_threshold_minutes',
        'rollover_months', 'rollover_policy', 'first_cycle_proration', 'bill_overage_interim',
    ];

    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ClientActivityRecorder $activities,
        private readonly ProposalAcceptanceAgreementQuery $acceptanceAgreements,
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
                'starts_on' => $attributes['starts_on'],
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

    /**
     * Correct the terms of an agreement that was recorded wrong.
     *
     * Keyed presence, not values: only the attributes the caller actually sent
     * are written, so the rename control - which sends a title and nothing
     * else - cannot blank the nine terms it never showed. A key that is present
     * and null is an erasure and is written as one; the difference between
     * "unstated" and "zero" is load-bearing all through the billing engine, and
     * a form that could only ever write zeros would quietly price unpriced work
     * at nothing.
     *
     * Re-read under a row lock inside the writing transaction and re-checked
     * against the workspace this request was authorized for. The controller
     * checks the instance the router bound and the router binds by key alone,
     * so without this the check and the write are two statements about a row
     * nothing held still in between.
     *
     * `update()` rather than `forceFill()`: the model's saving hook refuses a
     * catch-up threshold larger than the retainer it is meant to leave spare,
     * and that refusal is the point of editing these four terms together. It
     * throws `DomainException`, which the application renders as a 422.
     *
     * @param  array<string, mixed>  $attributes  only the keys the caller sent
     *
     * @throws EngagementException when the agreement is not this workspace's
     */
    public function update(Workspace $workspace, ClientAgreement $agreement, array $attributes): ClientAgreement
    {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $agreement)) {
            throw new EngagementException('The agreement does not belong to this workspace.');
        }

        $editable = array_intersect_key($attributes, array_flip(self::EDITABLE));

        return DB::transaction(function () use ($workspace, $agreement, $editable): ClientAgreement {
            $locked = ClientAgreement::query()
                ->whereKey($agreement->getKey())
                ->where('workspace_id', $workspace->id)
                ->tap(Locks::forUpdate())
                ->first();

            if (! $locked instanceof ClientAgreement) {
                throw new EngagementException('The agreement does not belong to this workspace.');
            }

            if ($editable === []) {
                return $locked;
            }

            if (array_key_exists('currency', $editable) && is_string($editable['currency'])) {
                $editable['currency'] = strtoupper($editable['currency']);
            }

            // What actually moved, recorded before the write. An audit line
            // saying "updated" answers nothing a month later; this says which
            // term changed and what it was.
            $changes = [];

            foreach ($editable as $attribute => $value) {
                $before = $locked->getAttribute($attribute);
                $normalised = $before instanceof CarbonImmutable ? $before->toDateString() : $before;

                if ($normalised != $value) {
                    $changes[$attribute] = ['old' => $normalised, 'new' => $value];
                }
            }

            if ($changes === []) {
                return $locked;
            }

            if ($locked->status === 'active'
                && (array_key_exists('starts_on', $changes) || array_key_exists('ends_on', $changes))) {
                $candidate = clone $locked;
                $candidate->fill($editable);
                $this->assertNoOverlappingActiveAgreement($candidate);
            }

            $locked->update($editable);
            $this->activities->record(
                $locked->workspace,
                $locked->clientCompany,
                'agreement.updated',
                $locked,
                ['changes' => $changes],
                occurrence: (string) Str::uuid(),
            );

            return $locked->fresh();
        });
    }

    public function activate(ClientAgreement $agreement): ClientAgreement
    {
        return DB::transaction(function () use ($agreement): ClientAgreement {
            $locked = ClientAgreement::query()->tap(Locks::forUpdate())->findOrFail($agreement->id);

            if ($locked->status === 'active') {
                return $locked;
            }

            if ($locked->status !== 'draft' && $locked->status !== 'paused') {
                throw new EngagementException('Only draft or paused agreements can be activated.');
            }

            $this->assertNoOverlappingActiveAgreement($locked);

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

    private function assertNoOverlappingActiveAgreement(ClientAgreement $agreement): void
    {
        $this->acceptanceAgreements->lockCompany($agreement->workspace_id, $agreement->client_company_id);

        if ($this->acceptanceAgreements->hasOverlappingActiveAgreement($agreement)) {
            throw new EngagementException('This agreement cannot overlap another active agreement. Ask an operator to verify its terms.');
        }
    }

    public function sign(ClientAgreement $agreement, ?User $signingUser, string $signerName, ?string $signerTitle): ClientAgreement
    {
        return DB::transaction(function () use ($agreement, $signingUser, $signerName, $signerTitle): ClientAgreement {
            $locked = ClientAgreement::query()->tap(Locks::forUpdate())->findOrFail($agreement->id);

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
