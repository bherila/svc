<?php

namespace App\Services\Navigation;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\AccessibleWorkspacesQuery;

/**
 * Where a person was last working, and whether they may go back there.
 *
 * Signing in used to land everyone on the workspace selector, every time. For
 * an account with one workspace and one client that is a screen whose only
 * content is the answer to a question nobody asked; for an operator who works
 * in the same client daily it is two clicks between them and the work.
 *
 * ## Remembered, then re-earned
 *
 * Nothing here trusts what it stored. A remembered id outlives the grant that
 * produced it - a membership revoked, a project scope narrowed, a portal
 * membership removed, a company reparented - and the whole hazard of landing
 * someone automatically is landing them somewhere they are no longer allowed.
 * So every read revalidates: the workspace against the viewer's *current*
 * accessible list, and the client against the options the navigation factory is
 * willing to offer them right now. A value that no longer holds is not an
 * error, it is simply not used, and the reader gets the selector they would
 * have got anyway.
 *
 * ## Written only when it moves
 *
 * `remember()` is called from middleware on every navigated page inside a
 * workspace, so it compares before it writes. Otherwise reading a client's
 * invoice list would be a database write, on every request, forever.
 */
final class WorkspaceReturnPoint
{
    public function __construct(private readonly AccessibleWorkspacesQuery $accessible) {}

    /**
     * Record where this request left the reader.
     *
     * The company is passed as the model the route bound and the navigation
     * factory was willing to call current - which is to say only after
     * authorization.
     *
     * A null client means the reader is inside the workspace but not inside any
     * one client, and it must not erase what is remembered. The workspace entry
     * point is exactly such a page: it runs this middleware before the
     * controller, so clearing here would wipe the memory one line before the
     * screen that exists to use it. What a null does do is drop a client
     * remembered against a *different* workspace, because that pair says
     * nothing about this one.
     */
    public function remember(User $user, Workspace $workspace, ?ClientCompany $client): void
    {
        $workspaceId = (int) $workspace->id;
        $sameWorkspace = (int) $user->last_workspace_id === $workspaceId;

        $clientId = match (true) {
            $client !== null => (int) $client->id,
            $sameWorkspace => $user->last_client_company_id === null
                ? null
                : (int) $user->last_client_company_id,
            default => null,
        };

        if ($sameWorkspace && (int) $user->last_client_company_id === (int) $clientId) {
            return;
        }

        $user->forceFill([
            'last_workspace_id' => $workspaceId,
            'last_client_company_id' => $clientId,
        ])->save();
    }

    /**
     * Where signing in should land, as a URL.
     *
     * The workspace entry point rather than a client screen: which client to
     * open is a question about this viewer at this moment, and that route is
     * the one place that answers it. So this decides the tenant and nothing
     * more.
     */
    public function landingUrl(User $user): string
    {
        $workspace = $this->rememberedWorkspace($user);

        return $workspace === null
            ? route('workspaces.index', absolute: false)
            : route('workspaces.enter', $workspace, absolute: false);
    }

    /**
     * The workspace this person was last in, if they may still enter it.
     *
     * `AccessibleWorkspacesQuery` unions both doors - workspace membership for
     * an operator, portal membership for a client - so a client who lost their
     * portal membership is refused here by the same query that would refuse
     * them at the door.
     */
    public function rememberedWorkspace(User $user): ?Workspace
    {
        $remembered = $user->last_workspace_id;

        if ($remembered === null) {
            return null;
        }

        return $this->accessible->for($user)
            ->first(fn (Workspace $option): bool => (int) $option->id === (int) $remembered);
    }

    /**
     * The client this person was last inside *of this workspace*.
     *
     * Returns the public id, because that is what the navigation options are
     * keyed by and what the caller compares against. The workspace has to match:
     * a client remembered against another tenant is not a client of this one,
     * and pairing them would be exactly the cross-tenant guess this refuses to
     * make.
     */
    public function rememberedClientId(User $user, Workspace $workspace): ?string
    {
        if ((int) $user->last_workspace_id !== (int) $workspace->id) {
            return null;
        }

        $clientId = $user->last_client_company_id;

        if ($clientId === null) {
            return null;
        }

        $company = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($clientId)
            ->first();

        return $company === null ? null : (string) $company->public_id;
    }
}
