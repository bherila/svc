<?php

namespace App\Services\Packages;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Models\ServicePackageEnrollment;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Packages\EnrollmentStatus;
use Illuminate\Support\Facades\Route;

/**
 * How the operator screens start an agreement a service package sign-up
 * created. Such an agreement never starts through `agreements.activate`
 * ({@see AgreementWorkflow::activate()} refuses it): it starts with its
 * service, and the one way an owner or admin starts it is `enrollments.activate`
 * while the enrollment waits for review (ACC-5, ACC-7).
 *
 * One bounded read for a page of agreements, scoped to the workspace.
 */
final class PackageAgreementActivations
{
    /**
     * For each of these agreements that a sign-up created, the enrollment's
     * activation - its endpoint and the version to send - while it waits for
     * review, else null. Agreements no enrollment owns are absent, so the
     * keys say which agreements may still use `agreements.activate`.
     *
     * @param  list<int>  $agreementIds
     * @return array<int, array{href: string, version: string}|null> agreement id => activation
     */
    public function forAgreements(Workspace $workspace, array $agreementIds): array
    {
        if ($agreementIds === []) {
            return [];
        }
        $offered = EnsureServicePackagesEnabled::enabled() && Route::has('agent-api.v1.enrollments.activate');
        $activations = [];
        $enrollments = ServicePackageEnrollment::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_agreement_id', array_values(array_unique($agreementIds)))
            ->orderBy('id')
            ->get();
        foreach ($enrollments as $enrollment) {
            $activations[(int) $enrollment->client_agreement_id] = $offered && $enrollment->enrollmentStatus() === EnrollmentStatus::AwaitingReview ? [
                'href' => route('agent-api.v1.enrollments.activate', [$workspace, $enrollment->public_id], absolute: false),
                'version' => AgentApiVersion::for($enrollment),
            ] : null;
        }

        return $activations;
    }
}
