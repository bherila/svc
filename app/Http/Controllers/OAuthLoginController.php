<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Navigation\WorkspaceReturnPoint;
use App\Support\Concurrency\Locks;
use BWH\Auth\Concerns\SignsOutThroughProvider;
use BWH\Auth\OAuth\OAuthClient;
use BWH\Auth\OAuth\ProviderApplications;
use BWH\Auth\OAuth\Session\ProviderSession;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OAuthLoginController extends Controller
{
    use SignsOutThroughProvider;

    public function redirect(Request $request, OAuthClient $oauth): SymfonyResponse
    {
        $redirect = $oauth->redirect($request);

        if ($request->header('X-Inertia')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }

    public function callback(Request $request, OAuthClient $oauth, WorkspaceReturnPoint $returnPoint): RedirectResponse
    {
        $identity = $oauth->identityFromCallback($request);

        $user = $this->resolveUser(
            provider: $identity->provider,
            subject: $identity->subject,
            name: $identity->name,
            email: $identity->email,
        );

        Auth::login($user);
        $request->session()->regenerate();

        // The provider credential generation this login was made under, the
        // baseline every later check of this session compares against.
        // Recorded while enforcement is off too, so turning it on does not end
        // every session at once. With enforcement on, a login that cannot be
        // verified is undone and the person retries.
        try {
            app(ProviderSession::class)->establish($request, $identity, Auth::guard('web'));
        } catch (ProviderStatusUnavailable) {
            abort(503, 'Sign-in verification is unavailable. Please retry.', ['Retry-After' => '30', 'Cache-Control' => 'private, no-store']);
        }

        // Cached for the session rather than fetched per request: this is navigation chrome,
        // and the callback is the only moment an access token for the provider is in hand.
        // Keeping it server-side also keeps the list of the other applications out of the
        // JS bundle, so what exists is not readable by anyone who downloads it.
        ProviderApplications::remember($request, $identity->apps);

        // Back where they left off, when they may still go there. The
        // selector stays the default and stays reachable - the navbar's exit
        // button is the way to it - but landing on it every single time made
        // signing in a screen with one row on it and one thing to click.
        //
        // `WorkspaceReturnPoint` revalidates against this person's current
        // access before offering a workspace, so losing a membership means the
        // selector rather than a 404 they were sent to.
        return redirect()->intended($returnPoint->landingUrl($user));
    }

    /**
     * Outside `auth` on purpose: a page left open past its session still shows
     * the menu, and clicking it must still end the provider session rather than
     * bounce the person into signing in again.
     */
    public function logout(Request $request, OAuthClient $oauth): SymfonyResponse
    {
        $redirect = $this->signOutThroughProvider($request, $oauth, route('home'));

        // The menu posts through Inertia, an XHR, and an XHR cannot follow a
        // redirect to the provider's end-session endpoint on another origin:
        // the session here ended while the page stayed exactly where it was.
        // A 409 with X-Inertia-Location makes the Inertia client navigate.
        if ($request->header('X-Inertia')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }

    private function resolveUser(string $provider, string $subject, string $name, string $email): User
    {
        try {
            return DB::transaction(function () use ($provider, $subject, $name, $email): User {
                $user = User::query()
                    ->where('oauth_provider', $provider)
                    ->where('oauth_subject', $subject)
                    ->tap(Locks::forUpdate())
                    ->first();

                $emailOwner = User::query()->where('email', $email)->tap(Locks::forUpdate())->first();

                abort_if(
                    $emailOwner !== null && ($user === null || $emailOwner->id !== $user->id),
                    409,
                    'That email address belongs to a different local account.',
                );

                if ($user === null) {
                    return User::query()->create([
                        'name' => $name,
                        'email' => $email,
                        'email_verified_at' => now(),
                        'password' => Hash::make(Str::random(64)),
                        'oauth_provider' => $provider,
                        'oauth_subject' => $subject,
                    ]);
                }

                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),
                ])->save();

                return $user;
            });
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $exception;
            }

            $user = User::query()
                ->where('oauth_provider', $provider)
                ->where('oauth_subject', $subject)
                ->first();

            abort_if(
                $user === null || $user->name !== $name || strcasecmp($user->email, $email) !== 0,
                409,
                'The provider identity could not be provisioned.',
            );

            return $user;
        }
    }
}
