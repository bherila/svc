<?php

namespace App\Services\Packages;

use App\Exceptions\ServicePackageRefused;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\CompanyContacts;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\EngagementException;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\Packages\EnrollmentSigner;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PortalAddRule;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A client's enrollment from offer to service: adding a package from the
 * portal (ACC-8), accepting it (ACC-1, ACC-2) and activating it (ACC-5).
 *
 * Adding and accepting are a company contact's own acts, in their browser:
 * neither is an agent operation. Activating an enrollment that waits for
 * review is an owner's or admin's, from the website or the agent API.
 *
 * Locks, in registry order: the package (shared, so a publish waits for an
 * acceptance in flight and the version accepted is the current one), then the
 * enrollment, then the agreement and company AgreementWorkflow takes. Access is
 * checked again under the enrollment's lock (EDGE-3), and once recorded an
 * acceptance is never changed (ACC-2).
 *
 * In this slice no sign-up takes a payment: a version that charges at sign-up,
 * requires autopay or starts only once paid is refused when added and when
 * accepted ({@see PackageRefusal::PaymentNotAvailableYet}), and slice 3b
 * replaces that refusal with the payment step and `awaiting_payment`.
 */
final class PackageEnrollmentWorkflow
{
    public function __construct(
        private readonly AgentAccess $access,
        private readonly CompanyContacts $contacts,
        private readonly PackageOffers $offers,
        private readonly PackageAgreementTerms $agreementTerms,
        private readonly AgreementWorkflow $agreements,
        private readonly PackageNotifier $notifier,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * A company contact adds a published, portal-addable package (ACC-8):
     * an offer straight away, with no request. Adding the same package again
     * while its offer is open returns that offer rather than a second one.
     */
    public function addFromPortal(User $contact, ClientCompany $company, ServicePackage $package): ServicePackageEnrollment
    {
        abort_unless($package->workspace_id === $company->workspace_id, 404);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $package, $workspace): ServicePackageEnrollment {
            $locked = ServicePackage::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($package->getKey())
                ->tap(Locks::forUpdate())
                ->firstOrFail();
            $this->requireCompanyContact($company, $contact);

            $open = ServicePackageEnrollment::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->where('service_package_id', $locked->id)
                ->where('status', EnrollmentStatus::Offered->value)
                ->orderBy('id')
                ->first();
            if ($open instanceof ServicePackageEnrollment) {
                return $open;
            }

            $version = $this->offers->publishedVersion($workspace, $locked);
            $refusal = PortalAddRule::refusal($locked, $version === null ? null : PackageTerms::fromVersion($version));
            if ($refusal !== null || $version === null) {
                throw ServicePackageRefused::because($refusal ?? PackageRefusal::NotAddable);
            }

            return $this->offers->offer($workspace, $locked, $version, $company, $contact);
        });
    }

    /**
     * A company contact accepts an offer (ACC-1, ACC-2), and the version's
     * activation rule follows (ACC-5).
     *
     * The terms accepted are the package's currently published version
     * (EDGE-7): when it is newer than the one offered, its terms are written
     * onto the draft agreement first. A retired package's offer is accepted on
     * the terms it was offered with (EDGE-8). The agreement starts on the day
     * of acceptance, in the workspace's timezone.
     *
     * @param  array<string, mixed>  $input  signer_name, signer_title, accepted_terms
     */
    public function accept(User $contact, ClientCompany $company, ServicePackageEnrollment $enrollment, array $input): ServicePackageEnrollment
    {
        $signer = EnrollmentSigner::fromInput($input);
        abort_unless($enrollment->workspace_id === $company->workspace_id && $enrollment->client_company_id === $company->id, 404);
        $workspace = $this->workspaceOf($company);

        return DB::transaction(function () use ($contact, $company, $enrollment, $signer, $workspace): ServicePackageEnrollment {
            $package = ServicePackage::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($enrollment->service_package_id)
                ->tap(Locks::forShare())
                ->firstOrFail();
            $locked = $this->locked($workspace, $enrollment);
            // Again, under the lock: access may have gone since the page loaded (EDGE-3).
            $this->requireCompanyContact($company, $contact);

            $status = $locked->enrollmentStatus();
            if ($status->isAccepted()) {
                if ($signer->signed($locked, $contact)) {
                    return $locked;
                }
                throw ServicePackageRefused::because(PackageRefusal::AlreadyAccepted);
            }
            if ($status !== EnrollmentStatus::Offered) {
                throw ServicePackageRefused::because(PackageRefusal::OfferNotOpen);
            }

            $version = $this->versionToAccept($workspace, $package, $locked);
            $terms = PackageTerms::fromVersion($version);
            if ($terms->involvesPayment()) {
                throw ServicePackageRefused::because(PackageRefusal::PaymentNotAvailableYet);
            }
            $agreement = $this->agreementOf($workspace, $locked);
            if ($agreement->status !== 'draft') {
                throw ServicePackageRefused::because(PackageRefusal::OfferNotOpen);
            }
            if ($version->id !== $locked->service_package_version_id) {
                $agreement = $this->agreementTerms->apply($workspace, $agreement, $version);
            }
            $agreement = $this->agreements->update($workspace, $agreement, ['starts_on' => $this->clock->today($workspace)->toDateString()]);

            $locked->forceFill([
                'accepted_at' => $this->clock->now($workspace),
                'accepted_by_user_id' => $contact->id,
                'signer_name' => $signer->name,
                'signer_title' => $signer->title,
                'accepted_version_id' => $version->id,
            ]);
            $next = $terms->activationMode->statusOnceAccepted();
            if ($next === EnrollmentStatus::Active) {
                try {
                    $this->start($workspace, $company, $locked, $agreement, $version, null);

                    return $locked;
                } catch (ServicePackageRefused $refused) {
                    if ($refused->refusalCode() !== PackageRefusal::ActivationConflict->value) {
                        throw $refused;
                    }
                    // The signature stands; an owner or admin resolves the
                    // overlap and activates it from Needs you.
                    $next = EnrollmentStatus::AwaitingReview;
                }
            }
            $this->assertMove($status, $next);
            $locked->forceFill(['status' => $next->value])->save();

            return $locked;
        });
    }

    /**
     * An owner or admin starts an accepted enrollment that waits for review
     * (ACC-5, ACC-7). Activating one already active changes nothing.
     */
    public function activate(User $actor, Workspace $workspace, ServicePackageEnrollment $enrollment, ?string $expectedVersion = null): ServicePackageEnrollment
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);

        return DB::transaction(function () use ($actor, $workspace, $enrollment, $expectedVersion): ServicePackageEnrollment {
            $locked = $this->locked($workspace, $enrollment);
            abort_unless($expectedVersion === null || AgentApiVersion::matches($locked, $expectedVersion), 409, 'The enrollment changed. Read it again before retrying.');
            $status = $locked->enrollmentStatus();
            if ($status === EnrollmentStatus::Active) {
                return $locked;
            }
            if ($status !== EnrollmentStatus::AwaitingReview) {
                throw ServicePackageRefused::because(PackageRefusal::NotAwaitingReview);
            }

            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($locked->client_company_id)->firstOrFail();
            $version = $this->offers->version($workspace, $locked->carriedVersionId());
            // The service starts on the day it is activated.
            $agreement = $this->agreements->update($workspace, $this->agreementOf($workspace, $locked), ['starts_on' => $this->clock->today($workspace)->toDateString()]);
            $this->start($workspace, $company, $locked, $agreement, $version, $actor);

            return $locked;
        });
    }

    /**
     * Activate the agreement, sign it as the contact who accepted, mark the
     * enrollment active, and tell the client once it commits. One savepoint,
     * so a refused activation leaves no part of it behind.
     */
    private function start(Workspace $workspace, ClientCompany $company, ServicePackageEnrollment $enrollment, ClientAgreement $agreement, ServicePackageVersion $version, ?User $activatedBy): void
    {
        $this->assertMove($enrollment->enrollmentStatus(), EnrollmentStatus::Active);
        DB::transaction(function () use ($workspace, $company, $enrollment, $agreement, $version, $activatedBy): void {
            try {
                $active = $this->agreements->activate($agreement);
            } catch (EngagementException) {
                throw ServicePackageRefused::because(PackageRefusal::ActivationConflict);
            }
            $signer = $enrollment->accepted_by_user_id === null ? null : User::query()->find($enrollment->accepted_by_user_id);
            $this->agreements->sign($active, $signer, (string) $enrollment->signer_name, $enrollment->signer_title);
            $enrollment->forceFill([
                'status' => EnrollmentStatus::Active->value,
                'activated_at' => $this->clock->now($workspace),
                'activated_by_user_id' => $activatedBy?->id,
            ])->save();

            $this->notifier->serviceActivated($workspace, $company, $version->name, route('portal.agreement', [$company, $active], absolute: true));
        });
    }

    /**
     * The version an acceptance signs: the package's published one, or a
     * retired package's offer as it was made (EDGE-8).
     */
    private function versionToAccept(Workspace $workspace, ServicePackage $package, ServicePackageEnrollment $enrollment): ServicePackageVersion
    {
        $versionId = match ($package->packageStatus()) {
            PackageStatus::Published => $package->published_version_id ?? $enrollment->service_package_version_id,
            PackageStatus::Retired => $enrollment->service_package_version_id,
            PackageStatus::Draft => throw ServicePackageRefused::because(PackageRefusal::OfferNotOpen),
        };

        return $this->offers->version($workspace, $versionId);
    }

    private function assertMove(EnrollmentStatus $from, EnrollmentStatus $to): void
    {
        if ($from !== $to && ! $from->canMoveTo($to)) {
            throw new LogicException("An enrollment cannot move from {$from->value} to {$to->value}.");
        }
    }

    private function requireCompanyContact(ClientCompany $company, User $contact): void
    {
        // Staff previewing the portal and project-limited contacts may look, not act (ACC-1, ACC-8).
        abort_unless($this->contacts->isCompanyContact($company, $contact), 403, 'Only a contact with access to the whole company can do this.');
    }

    private function locked(Workspace $workspace, ServicePackageEnrollment $enrollment): ServicePackageEnrollment
    {
        return ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($enrollment->getKey())
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    private function agreementOf(Workspace $workspace, ServicePackageEnrollment $enrollment): ClientAgreement
    {
        return ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($enrollment->client_agreement_id)->firstOrFail();
    }

    private function workspaceOf(ClientCompany $company): Workspace
    {
        return Workspace::query()->findOrFail($company->workspace_id);
    }
}
