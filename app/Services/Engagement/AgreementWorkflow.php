<?php

namespace App\Services\Engagement;

use App\Exceptions\AgreementCancellationRefused;
use App\Exceptions\ServicePackageRefused;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Engagement\ProposalAcceptanceAgreementQuery;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\WorkspaceAuthorization;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Engagement\Cancellation\CancellationRefusal;
use App\Support\Packages\PackageRefusal;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

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
        'is_visible_to_client', 'show_deferred_backlog_to_client', 'hourly_rate_amount', 'retainer_amount', 'retainer_minutes',
        'period_retainer_amount', 'period_retainer_minutes', 'catch_up_threshold_minutes',
        'rollover_months', 'rollover_policy', 'first_cycle_proration', 'bill_overage_interim',
        'notice_days', 'minimum_term_months', 'cancellation_mode',
    ];

    public function __construct(
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly ClientActivityRecorder $activities,
        private readonly ProposalAcceptanceAgreementQuery $acceptanceAgreements,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
        private readonly CancellationRecords $cancellations = new CancellationRecords,
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
                'show_deferred_backlog_to_client' => $attributes['show_deferred_backlog_to_client'] ?? false,
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
     * A draft a service package sign-up created carries the terms of the
     * package version its client signs until its service starts (ACC-2), so
     * it is refused here with `package_agreement_terms_fixed` unless
     * `$enrollment` - passed only by the package services, which write the
     * version's terms and the start date under the enrollment's lock - is the
     * enrollment that owns it. Once active it is corrected like any other.
     *
     * @param  array<string, mixed>  $attributes  only the keys the caller sent
     *
     * @throws EngagementException when the agreement is not this workspace's
     */
    public function update(Workspace $workspace, ClientAgreement $agreement, array $attributes, ?string $expectedVersion = null, ?ServicePackageEnrollment $enrollment = null): ClientAgreement
    {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $agreement)) {
            throw new EngagementException('The agreement does not belong to this workspace.');
        }

        $editable = array_intersect_key($attributes, array_flip(self::EDITABLE));

        return DB::transaction(function () use ($workspace, $agreement, $editable, $expectedVersion, $enrollment): ClientAgreement {
            $locked = ClientAgreement::query()
                ->whereKey($agreement->getKey())
                ->where('workspace_id', $workspace->id)
                ->tap(Locks::forUpdate())
                ->first();

            if (! $locked instanceof ClientAgreement) {
                throw new EngagementException('The agreement does not belong to this workspace.');
            }

            $this->assertVersion($locked, $expectedVersion);
            if ($editable === []) {
                return $locked;
            }
            if ($locked->status === 'draft') {
                $this->assertNotAPackageSignup($locked, $enrollment, PackageRefusal::AgreementTermsFixed);
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

            // A scheduled end owns `ends_on` until it is reached or undone
            // (EDGE-9): undoing it restores the end it replaced, which would
            // silently discard an edit made in between.
            if (array_key_exists('ends_on', $changes) && $this->cancellations->lockScheduled($locked) !== null) {
                throw AgreementCancellationRefused::because(CancellationRefusal::EndScheduled);
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

            // Read back under the lock already held: an ordinary read would
            // answer from a snapshot the caller's transaction may have fixed
            // before this lock was taken.
            return ClientAgreement::query()
                ->whereKey($locked->getKey())
                ->where('workspace_id', $workspace->id)
                ->tap(Locks::forUpdate())
                ->firstOrFail();
        });
    }

    /**
     * Start a draft agreement, or resume a paused one.
     *
     * A draft a service package sign-up created starts with its service, not
     * on its own: only once the client has accepted, any payment it waits for
     * has arrived and, where the package asks, an owner or admin has activated
     * the enrollment (ACC-5). So it is refused here with
     * `package_agreement_starts_with_service` unless `$enrollment` - passed
     * only by {@see PackageEnrollmentWorkflow}, which has checked all of that
     * under the enrollment's lock - is the enrollment that owns it.
     */
    public function activate(ClientAgreement $agreement, ?string $expectedVersion = null, ?ServicePackageEnrollment $enrollment = null): ClientAgreement
    {
        return DB::transaction(function () use ($agreement, $expectedVersion, $enrollment): ClientAgreement {
            $locked = ClientAgreement::query()->where('workspace_id', $agreement->workspace_id)->tap(Locks::forUpdate())->findOrFail($agreement->id);

            $this->assertVersion($locked, $expectedVersion);
            if ($locked->status === 'active') {
                return $locked;
            }

            if ($locked->status !== 'draft' && $locked->status !== 'paused') {
                throw new EngagementException('Only draft or paused agreements can be activated.');
            }
            if ($locked->status === 'draft') {
                $this->assertNotAPackageSignup($locked, $enrollment, PackageRefusal::AgreementStartsWithService);
            }

            $this->assertNoOverlappingActiveAgreement($locked);

            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => 'active',
                'activated_at' => $locked->activated_at ?? $this->clock->instant(),
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

    /**
     * Refuse to start or rewrite a sign-up's draft agreement anywhere but in
     * its enrollment. An enrollment's agreement is fixed when the offer is
     * made, in the transaction that creates both, so an ordinary read decides
     * it.
     */
    private function assertNotAPackageSignup(ClientAgreement $agreement, ?ServicePackageEnrollment $owner, PackageRefusal $refusal): void
    {
        if ($owner instanceof ServicePackageEnrollment) {
            if ((int) $owner->workspace_id !== (int) $agreement->workspace_id || (int) $owner->client_agreement_id !== (int) $agreement->id) {
                throw new LogicException('An enrollment can start or rewrite only its own agreement.');
            }

            return;
        }
        $owned = ServicePackageEnrollment::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->exists();
        if ($owned) {
            throw ServicePackageRefused::because($refusal);
        }
    }

    /**
     * Retire an agreement without deleting it.
     *
     * The archive of this domain: invoices, time and the capacity ledger keep
     * pointing at the row, and billing still reads a terminated agreement for
     * the periods it covered. What ends is the window - `ends_on` stops it
     * governing anything later.
     *
     * With no date it ends today in the workspace's timezone, never extending an
     * end date already in the past and never landing before the start. An
     * explicit date is taken as stated, so a caller correcting a lapse can
     * backdate it; the one thing refused is an end before the start.
     *
     * One-way: nothing reactivates a terminated agreement, and `activate()`
     * refuses it, so a mistaken termination is corrected by a new agreement.
     *
     * Records when it happened in `terminated_at` (the revenue projection and
     * the agreement screens read it), and carries out any scheduled end and
     * closes any pending cancellation request, so nothing waits on an
     * agreement that has ended (CAN-5, EDGE-9).
     */
    public function terminate(ClientAgreement $agreement, ?string $endsOn = null, ?string $expectedVersion = null): ClientAgreement
    {
        return DB::transaction(function () use ($agreement, $endsOn, $expectedVersion): ClientAgreement {
            $locked = ClientAgreement::query()->where('workspace_id', $agreement->workspace_id)->tap(Locks::forUpdate())->findOrFail($agreement->id);

            $this->assertVersion($locked, $expectedVersion);
            if ($locked->status === 'terminated') {
                return $locked;
            }

            if (! in_array($locked->status, ['draft', 'active', 'paused'], true)) {
                throw new EngagementException('Only draft, active or paused agreements can be terminated.');
            }

            $startsOn = $locked->starts_on->toDateString();
            $today = $this->clock->now($locked->workspace)->toDateString();
            $existing = $locked->ends_on?->toDateString();
            $default = $existing !== null && $existing < $today ? $existing : $today;
            $end = $endsOn ?? max($default, $startsOn);

            if ($end < $startsOn) {
                throw new EngagementException('The agreement cannot end before it starts.');
            }

            $previousStatus = $locked->status;
            $at = $this->clock->now()->utc();
            $locked->forceFill(['status' => 'terminated', 'ends_on' => $end, 'terminated_at' => $at])->save();
            $this->cancellations->closeOnTermination($locked, $at);
            // The end moves what the agreement's drafts charge: the cycle it
            // falls in is prorated, the final reconciliation collects the
            // deferred work, and a draft written while it was open sells a
            // cycle after it. They are rebuilt in this request, once the end
            // has committed, under the generators' own locks (#699).
            $workspaceId = (int) $locked->workspace_id;
            $agreementId = (int) $locked->id;
            DB::afterCommit(fn () => app(ClientInvoicingService::class)->regenerateAgreementDrafts($workspaceId, $agreementId));
            $this->activities->record(
                $locked->workspace,
                $locked->clientCompany,
                'agreement.transitioned',
                $locked,
                ['changes' => [
                    'status' => ['old' => $previousStatus, 'new' => 'terminated'],
                    'ends_on' => ['old' => $existing, 'new' => $end],
                ]],
                occurrence: (string) Str::uuid(),
            );

            return $locked;
        });
    }

    private function assertVersion(ClientAgreement $agreement, ?string $expectedVersion): void
    {
        if ($expectedVersion !== null) {
            abort_unless(AgentApiVersion::matches($agreement, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
        }
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
            $locked = ClientAgreement::query()->where('workspace_id', $agreement->workspace_id)->tap(Locks::forUpdate())->findOrFail($agreement->id);

            if ($locked->signed_at !== null) {
                return $locked;
            }

            if ($locked->status !== 'active') {
                throw new EngagementException('Only active agreements can be signed.');
            }

            $locked->forceFill([
                'signed_at' => $this->clock->instant(),
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
