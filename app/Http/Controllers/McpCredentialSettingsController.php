<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Credentials\CredentialFingerprint;
use App\Services\Mcp\McpCredentialSettings;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * The "Show all tools" setting of one MCP credential, changed from the MCP
 * setup page by the signed-in person who owns it.
 *
 * Ownership is not a check made after the fact: the credential is looked up in
 * the signed-in person's own live credentials (the same lists the credential
 * service gives them), so another person's credential, a revoked one and an
 * id that never existed are all the same 404 and change nothing. The setting
 * grants nothing - every call is authorized again on its own - and applies at
 * the credential's next `initialize`; sessions already open keep theirs.
 */
final class McpCredentialSettingsController extends Controller
{
    public function __construct(
        private readonly ApiCredentialService $credentials,
        private readonly McpCredentialSettings $settings,
        private readonly LoggerInterface $log,
    ) {}

    /** A connected app (an OAuth client holding the person's live grant). */
    public function connection(Request $request, string $client): JsonResponse
    {
        $user = $this->owner($request);
        $showAllTools = $this->requested($request);
        $clientId = collect($this->credentials->connectedClients($user))->first(
            static fn (array $connected): bool => $connected['id'] === strtolower($client),
        )['id'] ?? null;
        abort_if($clientId === null, 404);

        $this->settings->setForConnection($user->id, $clientId, $showAllTools);
        $this->audit($user, 'connection', $clientId, $showAllTools);

        return $this->answer($showAllTools);
    }

    /** A personal token that opens MCP connections. */
    public function token(Request $request, string $token): JsonResponse
    {
        $user = $this->owner($request);
        $showAllTools = $this->requested($request);
        $tokenId = collect($this->credentials->tokens($user))->first(
            fn (array $listed): bool => $listed['id'] === $token && $this->credentials->carriesConnectionScope($listed['scopes']),
        )['id'] ?? null;
        abort_if($tokenId === null, 404);

        $this->settings->setForToken($user->id, $tokenId, $showAllTools);
        $this->audit($user, 'token', $tokenId, $showAllTools);

        return $this->answer($showAllTools);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function requested(Request $request): bool
    {
        return $request->validate(['show_all_tools' => ['required', 'boolean']])['show_all_tools'] === true;
    }

    /**
     * Audited like every other credential change: the person's public id and a
     * fingerprint of the credential, never a token, a token id or an address.
     */
    private function audit(User $user, string $kind, string $credentialId, bool $showAllTools): void
    {
        $this->log->info('mcp_credential.show_all_tools_changed', [
            'actor' => 'web',
            'subject_id' => $user->public_id,
            'credential' => $kind,
            'credential_fingerprint' => CredentialFingerprint::of($credentialId),
            'show_all_tools' => $showAllTools,
        ]);
    }

    private function answer(bool $showAllTools): JsonResponse
    {
        return response()->json(['data' => ['show_all_tools' => $showAllTools]], headers: ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
