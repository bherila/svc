<?php

namespace App\Services\Packages;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageComponent;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\Packages\EnrollmentStatus;
use App\Support\WorkspaceClock;

/**
 * Offering a package to a client company: its own project, a draft agreement
 * carrying the version's terms, and an `offered` enrollment.
 *
 * The one place an offer is made, whether an owner or admin approved a
 * sign-up request (ADM-2) or a company contact added the package from the
 * portal (ACC-8). Every subscription gets its own project, so the agreement
 * never meets today's one-active-agreement-per-company-and-project rule.
 * Callers hold whatever lock decides that an offer may be made; this writes.
 */
final class PackageOffers
{
    public function __construct(
        private readonly PackageAgreementTerms $agreementTerms,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * The package's currently published version with its components, or null
     * while it has none (EDGE-7: approval and acceptance offer this one).
     *
     * Locking reads, so the terms are current rather than the snapshot an
     * enclosing transaction may already hold (Codex P1 on #666). The caller
     * holds a lock on the package, so its published version cannot move.
     */
    public function publishedVersion(Workspace $workspace, ServicePackage $package): ?ServicePackageVersion
    {
        if ($package->published_version_id === null) {
            return null;
        }
        $version = ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($package->published_version_id)
            ->tap(Locks::forShare())
            ->firstOrFail();

        return $version->setRelation('components', ServicePackageComponent::query()
            ->where('workspace_id', $workspace->id)
            ->where('service_package_version_id', $version->id)
            ->orderBy('sort_order')->orderBy('id')
            ->tap(Locks::forShare())
            ->get());
    }

    /** A version of this workspace with its components. */
    public function version(Workspace $workspace, int $versionId): ServicePackageVersion
    {
        return ServicePackageVersion::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($versionId)
            ->with(['components' => fn ($components) => $components->where('workspace_id', $workspace->id)])
            ->firstOrFail();
    }

    /**
     * @param  ServicePackageVersion  $version  the version offered, components loaded
     * @param  User|null  $offeredBy  the approver, or the contact who added it
     * @param  ServicePackageRequest|null  $request  the request it answers, if any
     */
    public function offer(Workspace $workspace, ServicePackage $package, ServicePackageVersion $version, ClientCompany $company, ?User $offeredBy, ?ServicePackageRequest $request = null): ServicePackageEnrollment
    {
        $project = $company->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => $this->projectName($company, $version->name),
            'is_visible_to_client' => true,
        ]);
        $now = $this->clock->now($workspace);
        // A provisional start: acceptance sets the day the service starts.
        $agreement = $this->agreementTerms->createDraft($workspace, $company, $project, $version, $now->startOfDay());

        $enrollment = new ServicePackageEnrollment;
        $enrollment->forceFill([
            'workspace_id' => $workspace->id,
            'service_package_id' => $package->id,
            'service_package_version_id' => $version->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'client_agreement_id' => $agreement->id,
            'service_package_request_id' => $request?->id,
            'status' => EnrollmentStatus::Offered->value,
            'offered_by_user_id' => $offeredBy?->id,
            'offered_at' => $now,
        ])->save();

        return $enrollment;
    }

    /** The package's name, made unique among the company's projects. */
    private function projectName(ClientCompany $company, string $name): string
    {
        $base = mb_substr($name, 0, 240);
        $taken = ClientProject::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('name', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base).'%')
            ->pluck('name')
            ->all();
        $candidate = $base;
        for ($suffix = 2; in_array($candidate, $taken, true); $suffix++) {
            $candidate = "{$base} ({$suffix})";
        }

        return $candidate;
    }
}
