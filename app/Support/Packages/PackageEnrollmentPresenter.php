<?php

namespace App\Support\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use LogicException;

/**
 * The shapes of an enrollment: the owner's and admin's (`enrollments.*`) and
 * the client's (`client_services.*` and the portal's Services pages).
 *
 * Reads only what the caller loaded - the company, project, agreement,
 * package, the version offered and the version accepted - so it issues no
 * queries. URLs are finished here, after the caller's authorization: an owner
 * is pointed at the operator screens and a client at the portal, never at a
 * page their route family does not serve.
 */
final class PackageEnrollmentPresenter
{
    /** @return array<string, mixed> */
    public static function forManager(Workspace $workspace, ServicePackageEnrollment $enrollment): array
    {
        [$company, $project, $agreement, $package, $offered, $accepted] = self::loaded($enrollment);
        $base = rtrim((string) config('app.url'), '/');

        return [
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'package' => ['id' => $package->public_id, 'link_name' => $package->link_name, 'name' => ($accepted ?? $offered)->name],
            'offered_version' => self::versionRef($offered),
            'accepted_version' => $accepted === null ? null : self::versionRef($accepted),
            'version_changed' => self::versionChanged($enrollment),
            'client' => ['id' => $company->public_id, 'name' => $company->name],
            'project_id' => $project->public_id,
            'agreement_id' => $agreement->public_id,
            'agreement_url' => $base.route('clients.agreement', ['workspace' => $workspace, 'clientCompany' => $company, 'clientAgreement' => $agreement], false),
            'request_id' => $enrollment->packageRequest?->public_id,
            'offered_at' => $enrollment->offered_at->toIso8601String(),
            'acceptance' => self::acceptance($enrollment),
            'activated_at' => $enrollment->activated_at?->toIso8601String(),
            'version' => AgentApiVersion::for($enrollment),
        ];
    }

    /**
     * A client's view of one of their services: the terms they agreed to (or,
     * before acceptance, the terms on offer), never the operator's settings.
     *
     * @param  ServicePackageVersion  $terms  the version whose terms to show, components loaded
     * @param  string  $url  the service's own portal page: review and sign while offered, its record after
     * @return array<string, mixed>
     */
    public static function forClient(ServicePackageEnrollment $enrollment, ServicePackageVersion $terms, string $url): array
    {
        [$company, $project, $agreement] = self::loaded($enrollment);
        $base = rtrim((string) config('app.url'), '/');
        $status = $enrollment->enrollmentStatus();
        // The portal never serves a draft agreement; a started one is the client's to read.
        $agreementUrl = match ($status) {
            EnrollmentStatus::Active => $base.route('portal.agreement', [$company, $agreement], false),
            EnrollmentStatus::Offered, EnrollmentStatus::AwaitingPayment, EnrollmentStatus::AwaitingReview, EnrollmentStatus::Lapsed, EnrollmentStatus::Withdrawn => null,
        };

        return [
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'company' => ['id' => $company->public_id, 'name' => $company->name],
            'project_id' => $project->public_id,
            'name' => $terms->name,
            'version_number' => $terms->number,
            // Accepted on a newer version than the one offered (EDGE-7).
            'version_changed' => self::versionChanged($enrollment),
            'terms' => self::clientTerms(PackageTerms::fromVersion($terms)),
            'acceptance' => self::acceptance($enrollment),
            'activated_at' => $enrollment->activated_at?->toIso8601String(),
            'agreement_url' => $agreementUrl,
            'url' => $url,
        ];
    }

    /**
     * What a client reads of a version's terms: the offer and the agreement
     * text, never the operator's settings (invoice issuing, lapse days).
     *
     * @return array<string, mixed>
     */
    public static function clientTerms(PackageTerms $terms): array
    {
        return [
            'name' => $terms->name,
            'summary' => $terms->summary,
            'body_markdown' => $terms->bodyMarkdown,
            'agreement_text' => $terms->agreementText,
            'currency' => $terms->currency,
            'billing_cadence' => $terms->billingCadence->value,
            'cycle_amount' => PublicPackagePresenter::cycleAmount($terms),
            'setup_fee_amount' => $terms->setupFeeTotal() > 0 ? $terms->setupFeeTotal() : null,
            'components' => array_map(static fn (PackageComponentTerms $component): array => [
                'description' => $component->description,
                'kind' => $component->kind->value,
                'cadence' => $component->cadence->value,
                'amount' => $component->total(),
            ], $terms->components),
            'hourly_rate_amount' => $terms->hourlyRateAmount,
            'retainer_amount' => $terms->retainerAmount,
            'retainer_minutes' => $terms->retainerMinutes,
            'rollover_months' => $terms->rolloverMonths,
            'activation_mode' => $terms->activationMode->value,
            'notice_days' => $terms->noticeDays,
            'minimum_term_months' => $terms->minimumTermMonths,
            'cancellation_mode' => $terms->cancellationMode->value,
        ];
    }

    /** Whether the version signed differs from the one offered (EDGE-7). */
    public static function versionChanged(ServicePackageEnrollment $enrollment): bool
    {
        return $enrollment->accepted_version_id !== null && $enrollment->accepted_version_id !== $enrollment->service_package_version_id;
    }

    /** @return array{accepted_at: string, signer_name: string, signer_title: string|null}|null */
    public static function acceptance(ServicePackageEnrollment $enrollment): ?array
    {
        if ($enrollment->accepted_at === null) {
            return null;
        }

        return [
            'accepted_at' => $enrollment->accepted_at->toIso8601String(),
            'signer_name' => (string) $enrollment->signer_name,
            'signer_title' => $enrollment->signer_title,
        ];
    }

    /** @return array{id: string, number: int} */
    private static function versionRef(ServicePackageVersion $version): array
    {
        return ['id' => $version->public_id, 'number' => $version->number];
    }

    /** @return array{ClientCompany, ClientProject, ClientAgreement, ServicePackage, ServicePackageVersion, ServicePackageVersion|null} */
    private static function loaded(ServicePackageEnrollment $enrollment): array
    {
        $company = $enrollment->clientCompany;
        $project = $enrollment->project;
        $agreement = $enrollment->agreement;
        $package = $enrollment->package;
        $offered = $enrollment->version;
        $accepted = $enrollment->accepted_version_id === null ? null : $enrollment->acceptedVersion;
        if (! $company instanceof ClientCompany || ! $project instanceof ClientProject || ! $agreement instanceof ClientAgreement
            || ! $package instanceof ServicePackage || ! $offered instanceof ServicePackageVersion
            || ($enrollment->accepted_version_id !== null && ! $accepted instanceof ServicePackageVersion)) {
            throw new LogicException('An enrollment names a record outside its workspace, or was presented without its relations loaded.');
        }

        return [$company, $project, $agreement, $package, $offered, $accepted];
    }
}
