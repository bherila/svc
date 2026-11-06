<?php

namespace App\Queries\Review;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ReviewerGrant;
use App\Models\Workspace;
use App\Services\Review\ReviewerGrants;

/**
 * What a provider's reviewer-grants page shows: the grants it has given and the
 * choices for a new one (docs/ux-overhaul.md section 7d).
 *
 * Every read is the granting workspace's own - its clients, their projects and
 * contacts, its grants - and every list is bounded, so the page costs the same
 * number of queries however many rows the workspace holds. The only other
 * workspaces named are those that already share a project with it.
 */
final class ReviewerGrantsOverview
{
    public const int CLIENTS = 200;

    public const int PER_KIND = 1000;

    public const int GRANTS = 200;

    public function __construct(private readonly ReviewerGrants $grants) {}

    /**
     * @return array{clients: list<array<string, mixed>>, workspaces: list<array{id: string, name: string}>, grants: list<array<string, mixed>>}
     */
    public function for(Workspace $workspace): array
    {
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->orderBy('name')->orderBy('id')->limit(self::CLIENTS)->get();
        $ids = $companies->modelKeys();

        $projects = $ids === [] ? collect() : ClientProject::query()->where('workspace_id', $workspace->id)->whereIn('client_company_id', $ids)
            ->orderBy('name')->orderBy('id')->limit(self::PER_KIND)->get(['public_id', 'name', 'client_company_id']);
        $contacts = $ids === [] ? collect() : ClientContact::query()->where('workspace_id', $workspace->id)->whereIn('client_company_id', $ids)
            ->where('is_archived', false)->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(self::PER_KIND)->get(['public_id', 'first_name', 'last_name', 'email', 'client_company_id']);

        $sharing = $this->grants->sharingWorkspaceIds($workspace);
        $others = $sharing === [] ? collect() : Workspace::query()->whereIn('id', $sharing)->orderBy('name')->orderBy('id')->limit(100)->get(['public_id', 'name']);

        $given = ReviewerGrant::query()->where('granter_workspace_id', $workspace->id)
            ->with(['company:id,public_id,name', 'project:id,public_id,name', 'reviewerWorkspace:id,public_id,name'])
            ->orderByRaw('active_marker IS NULL')->orderByDesc('id')->limit(self::GRANTS)->get();

        return [
            'clients' => array_values($companies->map(fn (ClientCompany $company): array => [
                'id' => $company->public_id,
                'name' => $company->name,
                'projects' => array_values($projects->where('client_company_id', $company->id)
                    ->map(fn (ClientProject $project): array => ['id' => $project->public_id, 'name' => $project->name])->all()),
                'contacts' => array_values($contacts->where('client_company_id', $company->id)
                    ->map(fn (ClientContact $contact): array => [
                        'id' => $contact->public_id,
                        'name' => trim($contact->first_name.' '.$contact->last_name),
                        'email' => (string) $contact->email,
                    ])->all()),
            ])->all()),
            'workspaces' => array_values($others->map(fn (Workspace $other): array => ['id' => $other->public_id, 'name' => $other->name])->all()),
            'grants' => array_values($given->map(fn (ReviewerGrant $grant): array => [
                'id' => $grant->public_id,
                'client' => (string) $grant->company?->name,
                'project' => $grant->project?->name,
                'kind' => $grant->grantee_kind,
                'grantee' => $this->granteeName($grant),
                'detail' => $grant->reviewer_email,
                'granted_at' => $grant->granted_at->toIso8601String(),
                'revoked_at' => $grant->revoked_at?->toIso8601String(),
                'revoke_url' => $grant->state()->reaches()
                    ? route('workspaces.reviewers.destroy', [$workspace, $grant->public_id], absolute: false)
                    : null,
            ])->all()),
        ];
    }

    private function granteeName(ReviewerGrant $grant): string
    {
        $named = $grant->reviewerWorkspace->name ?? $grant->reviewer_label ?? $grant->reviewer_email;

        return (string) $named;
    }
}
