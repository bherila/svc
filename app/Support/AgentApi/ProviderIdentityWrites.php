<?php

namespace App\Support\AgentApi;

use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A fresh provider identity check before an agent write made with an OAuth
 * token (agent connection or personal API token).
 *
 * Every bearer request is already checked against the person's shared status
 * observation, which may be up to five minutes old. A write does not wait out
 * that window: it asks the provider again, so a disabled or reset identity
 * cannot keep writing on an observation made before the change. A no-op while
 * provider identity enforcement is off, for unbound accounts and for the
 * signed-in website, whose session middleware checks unsafe methods freshly.
 *
 * An MCP tool call is checked once, before it runs: a REST route it dispatches
 * internally for the same credential does not ask the provider a second time.
 */
final class ProviderIdentityWrites
{
    private ?string $verifiedTokenId = null;

    public function __construct(private readonly ProviderIdentityTokens $tokens) {}

    /**
     * Whether this credential may write now. Throws ProviderStatusUnavailable
     * (a retryable 503) when the provider cannot answer.
     */
    public function allows(Authenticatable $user, string $tokenId): bool
    {
        if (! ProviderIdentityTokens::enabled()) {
            return true;
        }
        if ($this->verifiedTokenId !== null && hash_equals($this->verifiedTokenId, $tokenId)) {
            return true;
        }

        return $this->tokens->verifyUser($user, $tokenId, fresh: true);
    }

    /**
     * Run a call already verified for this credential, so a write it makes
     * through an internal REST dispatch is not verified again.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    public function verifiedFor(string $tokenId, Closure $call): mixed
    {
        $previous = $this->verifiedTokenId;
        $this->verifiedTokenId = $tokenId;
        try {
            return $call();
        } finally {
            $this->verifiedTokenId = $previous;
        }
    }
}
