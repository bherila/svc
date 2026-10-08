<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiCredentials\ApiCredentialService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's REST credentials (#384).
 *
 * Browser routes on purpose, not API operations: a credential is minted only
 * by the person, in the browser, so no OAuth token can create another.
 *
 * A new secret travels once, in the body of the response that created it,
 * marked no-store. It is never flashed to the session (a readable row in the
 * session store) nor put in page props (persisted in browser history), so it
 * cannot be recovered from either after the page that received it is gone.
 */
final class ApiCredentialController extends Controller
{
    public function storeToken(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(ApiCredentialService::grantableScopes())],
            'days' => ['required', 'integer', Rule::in(ApiCredentialService::TOKEN_LIFETIMES_DAYS)],
        ]);
        $issued = $this->refusable(fn (): array => $credentials->issueToken($this->user($request), $data['name'], array_values($data['scopes']), (int) $data['days']));

        return $this->secret(['kind' => 'token', 'name' => trim($data['name']), 'token' => $issued['token']]);
    }

    public function destroyToken(Request $request, string $token, ApiCredentialService $credentials): RedirectResponse
    {
        $credentials->revokeToken($this->user($request), $token);

        return back()->with('status', 'API token revoked.');
    }

    public function storeApp(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2048', 'distinct'],
            'confidential' => ['required', 'boolean'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(ApiCredentialService::grantableScopes())],
        ]);
        $registered = $this->refusable(fn (): array => $credentials->registerApp(
            $this->user($request),
            $data['name'],
            array_values($data['redirect_uris']),
            (bool) $data['confidential'],
            array_values($data['scopes']),
        ));

        return $this->secret([
            'kind' => 'app',
            'name' => trim($data['name']),
            'client_id' => (string) $registered['client']->getKey(),
            'client_secret' => $registered['secret'],
        ]);
    }

    public function destroyApp(Request $request, string $client, ApiCredentialService $credentials): RedirectResponse
    {
        $credentials->deleteApp($this->user($request), $client);

        return back()->with('status', 'OAuth app deleted and its tokens revoked.');
    }

    /** @param array<string, string|null> $credential */
    private function secret(array $credential): JsonResponse
    {
        return response()->json(['data' => $credential], 201, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function refusable(callable $action): mixed
    {
        try {
            return $action();
        } catch (DomainException $refused) {
            throw ValidationException::withMessages(['credential' => $refused->getMessage()]);
        }
    }
}
