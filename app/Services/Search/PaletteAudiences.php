<?php

namespace App\Services\Search;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which workspaces the palette serves a person in, and as whom - and which of
 * them the page they are on belongs to.
 *
 * A workspace they belong to is served as staff. One where they hold only a
 * client company membership is served as a portal user: the operator family is
 * the strictly larger view, so a person who is both is never also offered the
 * narrower portal rows of the same workspace.
 *
 * The page they are on arrives as a path and is only ever matched against this
 * list. A workspace or client id in the path that is not theirs resolves to
 * nothing, the same as one that does not exist.
 */
final class PaletteAudiences
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** @return list<array{0: Workspace, 1: CollaborationAudience}> */
    public function for(User $user): array
    {
        /** @var Collection<int, Workspace> $member */
        $member = Workspace::query()
            ->whereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->get();

        /** @var Collection<int, Workspace> $portal */
        $portal = Workspace::query()
            ->whereIn('id', ClientCompanyMembership::query()->where('user_id', $user->id)->select('workspace_id'))
            ->whereNotIn('id', $member->pluck('id')->all())
            ->orderBy('name')
            ->get();

        $audiences = [];
        foreach ($member as $workspace) {
            $audiences[] = [$workspace, CollaborationAudience::Internal];
        }
        foreach ($portal as $workspace) {
            $audiences[] = [$workspace, CollaborationAudience::Portal];
        }

        return $audiences;
    }

    /**
     * The workspace (and client, when the path names one) the viewer is on.
     *
     * With no workspace in the path the answer is the viewer's only workspace,
     * if they have exactly one; with several, none is guessed.
     *
     * @return array{0: Workspace, 1: CollaborationAudience, 2: ClientCompany|null}|null
     */
    public function resolve(User $user, string $path): ?array
    {
        $audiences = $this->for($user);

        if (preg_match('#^/portal/('.self::UUID.')(?:/|$)#', $path, $match) === 1) {
            $company = ClientCompany::query()->where('public_id', strtolower($match[1]))->first();
            foreach ($audiences as [$workspace, $audience]) {
                if ($company instanceof ClientCompany && $company->workspace_id === $workspace->id) {
                    return [$workspace, $audience, $audience === CollaborationAudience::Portal || $this->holdsMembership($user, $company) ? $company : null];
                }
            }

            return null;
        }

        if (preg_match('#^/workspaces/('.self::UUID.')(?:/clients/('.self::UUID.'))?#', $path, $match) === 1) {
            foreach ($audiences as [$workspace, $audience]) {
                if ($workspace->public_id === strtolower($match[1])) {
                    $company = isset($match[2])
                        ? ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', strtolower($match[2]))->first()
                        : null;

                    return [$workspace, $audience, $company];
                }
            }

            return null;
        }

        return count($audiences) === 1 ? [$audiences[0][0], $audiences[0][1], null] : null;
    }

    private function holdsMembership(User $user, ClientCompany $company): bool
    {
        return ClientCompanyMembership::query()->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)->where('user_id', $user->id)->exists();
    }
}
