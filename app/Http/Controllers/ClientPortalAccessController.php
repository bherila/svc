<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessMutationRefused;
use App\Exceptions\PortalInvitationFailed;
use App\Http\Requests\PortalAccessRequest;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientPortalInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\PortalInvitations;
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
        private readonly PortalInvitations $invitations,
    ) {}

    /**
     * Give portal access to a person who already has an account here.
     *
     * Found by the exact address they sign in with, and only among accounts
     * bound to the configured identity provider: an account nobody can sign in to would
     * hold access nobody can use. Someone without an account signs in once
     * first, or - with portal invitations on - is offered an invitation
     * instead: the refusal then comes back under `invite`, which the form
     * answers with a "Send invitation" button.
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
            throw ValidationException::withMessages(PortalInvitations::enabled()
                ? ['invite' => 'Nobody signs in with that address yet. Send them an invitation, and they get this access when they first sign in.']
                : ['email' => 'Nobody signs in with that address yet. Ask them to sign in once, then add them.']);
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

    /**
     * Invite an address nobody signs in with yet (#430). The identity
     * provider admits them to this application, and the access is given
     * when they first sign in with that address.
     */
    public function invite(PortalAccessRequest $request, Workspace $workspace, ClientCompany $clientCompany): RedirectResponse
    {
        $actor = $this->authorizeInvitations($request, $workspace, $clientCompany);

        return $this->refusable('invite', fn (): ClientPortalInvitation => $this->invitations->invite(
            AccessMutationContext::user($actor), $clientCompany, $request->string('email')->toString(), $request->scope(), $request->projectPublicIds(),
        ), 'Invitation sent.');
    }

    public function resendInvitation(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $invitation): RedirectResponse
    {
        $actor = $this->authorizeInvitations($request, $workspace, $clientCompany);

        return $this->refusable('invitation.'.$invitation, fn (): ClientPortalInvitation => $this->invitations->resend(
            AccessMutationContext::user($actor), $clientCompany, $this->invitation($workspace, $clientCompany, $invitation),
        ), 'Invitation sent again.');
    }

    public function revokeInvitation(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $invitation): RedirectResponse
    {
        $actor = $this->authorizeInvitations($request, $workspace, $clientCompany);

        return $this->refusable('invitation.'.$invitation, fn (): ClientPortalInvitation => $this->invitations->revoke(
            AccessMutationContext::user($actor), $clientCompany, $this->invitation($workspace, $clientCompany, $invitation),
        ), 'Invitation withdrawn.');
    }

    /** Settings' gate, and not found at all while invitations are off. */
    private function authorizeInvitations(Request $request, Workspace $workspace, ClientCompany $clientCompany): User
    {
        $actor = $this->authorize($request, $workspace, $clientCompany);
        abort_unless(PortalInvitations::enabled(), 404);

        return $actor;
    }

    private function invitation(Workspace $workspace, ClientCompany $clientCompany, string $publicId): ClientPortalInvitation
    {
        return ClientPortalInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
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
        } catch (PortalInvitationFailed $failed) {
            // The provider's field messages never repeat the address.
            throw ValidationException::withMessages([$field => array_merge([$failed->getMessage()], ...array_values($failed->errors))]);
        }

        return redirect()->back()->with('status', $status);
    }
}
