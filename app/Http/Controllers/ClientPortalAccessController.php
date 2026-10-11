<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessMutationRefused;
use App\Http\Requests\PortalAccessRequest;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Services\WorkspaceAuthorization;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Who can use a client's portal, from the client's settings page.
 *
 * Each action is one {@see WorkspaceAccess} operation, so the same rules, locks
 * and audit trail apply as everywhere else; this only resolves the people and
 * turns a refusal into a message on the form.
 */
class ClientPortalAccessController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    /**
     * Give portal access to a person who already has an account here.
     *
     * Found by the exact address they sign in with, and only among accounts
     * bound to the configured identity provider: an account nobody can sign in to would
     * hold access nobody can use. Someone without an account signs in once
     * first; inviting them is a separate, later step.
     */
    public function store(PortalAccessRequest $request, Workspace $workspace, ClientCompany $clientCompany): RedirectResponse
    {
        $actor = $this->authorize($request, $workspace, $clientCompany);
        $person = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($request->string('email')->toString())])
            // Bound the way sign-in resolves people: to this provider, with a subject.
            ->where('oauth_provider', (string) config('bherila-auth.oauth_client.provider'))
            ->whereNotNull('oauth_subject')
            ->first();

        if (! $person instanceof User) {
            throw ValidationException::withMessages(['email' => 'Nobody signs in with that address yet. Ask them to sign in once, then add them.']);
        }

        return $this->refusable('email', fn () => $this->access->grantPortal(
            AccessMutationContext::user($actor), $clientCompany, $person, $request->scope(), $request->projectPublicIds(),
        ), 'Portal access given.');
    }

    public function update(PortalAccessRequest $request, Workspace $workspace, ClientCompany $clientCompany, string $user): RedirectResponse
    {
        $actor = $this->authorize($request, $workspace, $clientCompany);

        return $this->refusable('portal.'.$user, fn () => $this->access->changePortalScope(
            AccessMutationContext::user($actor), $clientCompany, $this->portalMember($workspace, $clientCompany, $user), $request->scope(), $request->projectPublicIds(),
        ), 'Portal access updated.');
    }

    public function destroy(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $user): RedirectResponse
    {
        $actor = $this->authorize($request, $workspace, $clientCompany);

        return $this->refusable('portal.'.$user, fn () => $this->access->revokePortal(
            AccessMutationContext::user($actor), $clientCompany, $this->portalMember($workspace, $clientCompany, $user),
        ), 'Portal access removed.');
    }

    /** The same gate as the rest of client settings; the boundary re-checks under its lock. */
    private function authorize(Request $request, Workspace $workspace, ClientCompany $clientCompany): User
    {
        Gate::authorize('manage', $workspace);
        $this->authorization->assertOwnedBy($workspace, $clientCompany);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** A person with portal access to this client, by public id; anyone else is not found. */
    private function portalMember(Workspace $workspace, ClientCompany $clientCompany, string $publicId): User
    {
        return User::query()
            ->where('public_id', $publicId)
            ->whereIn('id', ClientCompanyMembership::query()->select('user_id')
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $clientCompany->id))
            ->firstOrFail();
    }

    private function refusable(string $field, Closure $change, string $status): RedirectResponse
    {
        try {
            $change();
        } catch (AccessMutationRefused $refused) {
            abort_if($refused->reason === AccessRefusal::NotAuthorized, 403);

            throw ValidationException::withMessages([$field => $refused->reason->message()]);
        }

        return redirect()->back()->with('status', $status);
    }
}
