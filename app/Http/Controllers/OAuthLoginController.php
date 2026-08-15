<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthLoginController extends Controller
{
    private const string STATE_SESSION_KEY = 'oauth.login.state';

    private const string VERIFIER_SESSION_KEY = 'oauth.login.code_verifier';

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put([
            self::STATE_SESSION_KEY => $state,
            self::VERIFIER_SESSION_KEY => $verifier,
        ]);

        return redirect()->away($this->configuredValue('base_url').'/oauth/authorize?'.http_build_query([
            'client_id' => $this->configuredValue('client_id'),
            'redirect_uri' => $this->configuredValue('redirect_uri'),
            'response_type' => 'code',
            'scope' => 'identity:read',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_SESSION_KEY);
        $verifier = $request->session()->pull(self::VERIFIER_SESSION_KEY);
        $state = $request->query('state');
        $code = $request->query('code');

        abort_unless(
            is_string($expectedState)
            && is_string($state)
            && hash_equals($expectedState, $state)
            && is_string($verifier)
            && is_string($code)
            && $code !== '',
            403,
            'The OAuth response could not be verified.',
        );

        $baseUrl = $this->configuredValue('base_url');
        $tokenResponse = Http::asForm()->acceptJson()->post($baseUrl.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->configuredValue('client_id'),
            'client_secret' => $this->configuredValue('client_secret'),
            'redirect_uri' => $this->configuredValue('redirect_uri'),
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
        abort_unless($tokenResponse->successful(), 502, 'The identity provider rejected the authorization code.');

        $accessToken = $tokenResponse->json('access_token');
        abort_unless(is_string($accessToken) && $accessToken !== '', 502, 'The identity provider returned an invalid token.');

        $identityResponse = Http::acceptJson()->withToken($accessToken)->get($baseUrl.'/api/oauth/user');
        abort_unless($identityResponse->successful(), 502, 'The identity provider did not return an account.');

        $identity = $identityResponse->json();
        abort_unless(
            is_array($identity)
            && is_string($identity['sub'] ?? null)
            && $identity['sub'] !== ''
            && strlen($identity['sub']) <= 191
            && is_string($identity['name'] ?? null)
            && $identity['name'] !== ''
            && is_string($identity['email'] ?? null)
            && filter_var($identity['email'], FILTER_VALIDATE_EMAIL) !== false,
            502,
            'The identity provider returned an invalid account.',
        );

        $user = $this->resolveUser(
            provider: $this->configuredValue('name'),
            subject: $identity['sub'],
            name: $identity['name'],
            email: Str::lower($identity['email']),
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function resolveUser(string $provider, string $subject, string $name, string $email): User
    {
        try {
            return DB::transaction(function () use ($provider, $subject, $name, $email): User {
                $user = User::query()
                    ->where('oauth_provider', $provider)
                    ->where('oauth_subject', $subject)
                    ->lockForUpdate()
                    ->first();

                $emailOwner = User::query()->where('email', $email)->lockForUpdate()->first();

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

    private function configuredValue(string $key): string
    {
        $value = config("services.identity_provider.{$key}");
        abort_unless(is_string($value) && $value !== '', 503, 'OAuth is not configured.');

        return rtrim($value, $key === 'base_url' ? '/' : '');
    }
}
