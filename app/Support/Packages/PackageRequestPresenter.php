<?php

namespace App\Support\Packages;

use App\Models\ClientCompany;
use App\Models\ServicePackage;
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
    /** @return array<string, mixed> */
    public static function request(ServicePackageRequest $request, ServicePackage $package, ServicePackageVersion $version, ?ClientCompany $client, ?ClientCompany $suggested): array
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
}
