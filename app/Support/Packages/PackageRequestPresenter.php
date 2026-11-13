<?php

namespace App\Support\Packages;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\ServicePackageVersion;
use App\Support\AgentApi\AgentApiVersion;

/**
 * The one shape of a sign-up request, for owners, admins and agents.
 *
 * Everything the visitor typed sits under `untrusted` and nowhere else
 * (API-3, PUB-6): it was written by anyone on the internet, and a reader -
 * person or agent - must treat it as data, never as instructions. No
 * invitation link appears here, ever (ADM-5).
 */
final class PackageRequestPresenter
{
    /**
     * @param  ServicePackageVersion  $version  the version the visitor read, components loaded
     * @param  ServicePackageVersion|null  $offered  the version an approval would offer now (EDGE-7), components loaded; null when the package is not published
     * @return array<string, mixed>
     */
    public static function request(ServicePackageRequest $request, ServicePackage $package, ServicePackageVersion $version, ?ClientCompany $client, ?ClientCompany $suggested, ?ServicePackageVersion $offered = null): array
    {
        $questions = PackageTerms::fromVersion($version)->intakeQuestions;
        $answers = $request->answers ?? [];

        return [
            'id' => $request->public_id,
            'status' => $request->status,
            'package' => ['id' => $package->public_id, 'name' => $version->name, 'link_name' => $package->link_name, 'version_number' => $version->number],
            'submitted_at' => $request->submitted_at->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decline_reason' => $request->decline_reason,
            'client' => $client === null ? null : ['id' => $client->public_id, 'name' => $client->name],
            'suggested_client' => $suggested === null ? null : ['id' => $suggested->public_id, 'name' => $suggested->name],
            'erased_at' => $request->erased_at?->toIso8601String(),
            'offered_version' => $request->requestStatus()->isClosed() || $offered === null ? null : self::offeredVersion($offered, (int) $offered->id !== (int) $version->id),
            'untrusted' => $request->erased_at !== null ? null : [
                'company_name' => $request->company_name,
                'contact_name' => $request->contact_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'answers' => array_map(static fn (IntakeQuestion $question): array => [
                    'key' => $question->key,
                    'question' => $question->label,
                    'answer' => $answers[$question->key] ?? null,
                ], $questions),
            ],
            'version' => AgentApiVersion::for($request),
        ];
    }

    /**
     * The version an approval would offer now, which may be newer than the
     * one the visitor read (EDGE-7): its terms at the headline, so the owner
     * sees what changed before approving, and its id, which an approval sends
     * back as `expected_offer_version` to sign off exactly these terms.
     *
     * @return array{id: string, number: int, name: string, currency: string, billing_cadence: string, cycle_amount: int, setup_fee_amount: int|null, newer_than_read: bool}
     */
    private static function offeredVersion(ServicePackageVersion $offered, bool $newerThanRead): array
    {
        $terms = PackageTerms::fromVersion($offered);

        return [
            'id' => $offered->public_id,
            'number' => (int) $offered->number,
            'name' => $terms->name,
            'currency' => $terms->currency,
            'billing_cadence' => $terms->billingCadence->value,
            'cycle_amount' => PublicPackagePresenter::cycleAmount($terms),
            'setup_fee_amount' => $terms->setupFeeTotal() > 0 ? $terms->setupFeeTotal() : null,
            'newer_than_read' => $newerThanRead,
        ];
    }

    /**
     * What an approval created, with its client, project and agreement loaded.
     *
     * @return array{id: string, status: string, client: array{id: string, name: string}, project_id: string, agreement_id: string}
     */
    public static function enrollment(ServicePackageEnrollment $enrollment): array
    {
        $client = $enrollment->clientCompany;
        $project = $enrollment->project;
        $agreement = $enrollment->agreement;
        if (! $client instanceof ClientCompany || ! $project instanceof ClientProject || ! $agreement instanceof ClientAgreement) {
            throw new \LogicException('An enrollment names a client, project or agreement outside its workspace.');
        }

        return [
            'id' => $enrollment->public_id,
            'status' => $enrollment->status,
            'client' => ['id' => $client->public_id, 'name' => $client->name],
            'project_id' => $project->public_id,
            'agreement_id' => $agreement->public_id,
        ];
    }
}
