<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access\PortalInvitations;
use App\Services\Navigation\WorkspaceReturnPoint;
use BWH\Auth\Http\Controllers\RelyingPartyLoginController;
use BWH\Auth\OAuth\OAuthIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Sign-in and sign-out through the identity provider. The flow, the account rules and the
 * Inertia hand-off live in the package; this class only says where SVC differs.
 *
 * `/logout` stays outside `auth` on purpose (see routes/web.php): a page left open past its
 * session still shows the menu, and clicking it must still end the provider session rather
 * than bounce the person into signing in again.
 */
final class OAuthLoginController extends RelyingPartyLoginController
{
    /**
     * Back where they left off, when they may still go there. `WorkspaceReturnPoint`
     * revalidates against this person's current access before offering a workspace, so
     * losing a membership means the selector rather than a 404 they were sent to.
     */
    protected function landingUrl(Request $request, Authenticatable $account): string
    {
        return $account instanceof User
            ? app(WorkspaceReturnPoint::class)->landingUrl($account)
            : parent::landingUrl($request, $account);
    }

    /**
     * Portal access promised to this address before the person had an account here. Runs only
     * once the sign-in has fully succeeded; a failure leaves the invitation pending and never
     * blocks the sign-in.
     */
    protected function afterSignIn(Request $request, Authenticatable $account, OAuthIdentity $identity): void
    {
        if ($account instanceof User) {
            app(PortalInvitations::class)->applyAtSignIn($account);
        }
    }

    protected function afterSignOutUrl(Request $request): string
    {
        return route('home');
    }
}
