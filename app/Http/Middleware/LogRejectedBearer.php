<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\BearerRejection\BearerFingerprint;
use App\Support\AgentApi\BearerRejection\BearerRejectionClassifier;
use App\Support\AgentApi\BearerRejection\BearerRejectionReason;
use App\Support\AgentApi\BearerRejection\VerifiedBearerClaims;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Exceptions\MissingScopeException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One structured log line for every bearer the API refuses (#441): the
 * reason, and a {@see BearerFingerprint} of what was presented - never the
 * token, its payload or its signature.
 *
 * Every refusal reaches this one place. It runs first on every API route (the
 * `api` group), keeps the bearer before Passport blanks a refused one, and
 * reads the response the refusal became: a 401 from any check, or a 403 that
 * is a missing scope. Other 403s and 404s (a hidden workspace, a policy) are
 * not about the credential and are not logged. The specific reason comes from
 * Passport's reported validator failure, or for a token this server signed,
 * from its stored row.
 *
 * Lines are rate-limited per verified client, or per address when the token
 * names no client this server can verify, so a retry loop cannot flood the log.
 */
final class LogRejectedBearer
{
    public const string MESSAGE = 'Agent API bearer rejected';

    public const int LINES_PER_MINUTE = 5;

    private const string VALIDATOR_REASON = 'svc.rejected_bearer.validator_reason';

    private const string WATCHING = 'svc.rejected_bearer.watching';

    public function __construct(private readonly BearerRejectionClassifier $classifier) {}

    /**
     * Passport reports its validator's refusal and then answers a bare 401;
     * the exception handler hands the report here so the reason survives.
     *
     * Returns whether this request's rejection line covers the refusal. When
     * it does, the report itself is not written: the refused bearer is an
     * argument in the parser's stack frames, so its trace would carry a
     * short token whole (and fifteen characters of a long one), once per
     * retry with no rate limit.
     */
    public static function recordValidatorFailure(OAuthServerException $exception): bool
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request || $request->attributes->get(self::WATCHING) !== true) {
            return false;
        }
        $request->attributes->set(self::VALIDATOR_REASON, BearerRejectionClassifier::fromValidatorFailure($exception));

        return true;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Read before the guard runs: Passport clears a refused bearer's header.
        $token = trim((string) $request->bearerToken());
        $request->attributes->set(self::WATCHING, $token !== '');
        $response = $next($request);
        if ($token === '') {
            return $response;
        }
        try {
            $this->logRefusal($request, $token, $response);
        } catch (Throwable $exception) {
            // The line is diagnostics; the response stands either way.
            report($exception);
        }

        return $response;
    }

    private function logRefusal(Request $request, #[\SensitiveParameter] string $token, Response $response): void
    {
        $status = $response->getStatusCode();
        $reason = match (true) {
            $status === 401 => null,
            $status === 403 && $this->isMissingScope($response) => BearerRejectionReason::InsufficientScope,
            default => false,
        };
        if ($reason === false) {
            return;
        }
        $claims = $this->classifier->verifiedClaims($token);
        $limiterKey = 'agent-api:rejected-bearer:'.($claims !== null ? 'client:'.$claims->clientId : 'ip:'.$request->ip());
        if (RateLimiter::tooManyAttempts($limiterKey, self::LINES_PER_MINUTE)) {
            return;
        }
        RateLimiter::hit($limiterKey, 60);

        $fingerprint = BearerFingerprint::of($token);
        Log::warning(self::MESSAGE, [
            'reason' => ($reason ?? $this->reason($request, $claims))->value,
            'status' => $status,
            'token_length' => $fingerprint->length,
            'token_prefix' => $fingerprint->prefix,
            'jwt_shaped' => $fingerprint->jwtShaped,
            'client_id' => $claims?->clientId,
            'request_id' => $this->requestId($request),
            'route' => $request->route()?->getName(),
        ]);
    }

    private function reason(Request $request, ?VerifiedBearerClaims $claims): BearerRejectionReason
    {
        $validator = $request->attributes->get(self::VALIDATOR_REASON);
        if ($validator instanceof BearerRejectionReason && $validator !== BearerRejectionReason::Revoked) {
            return $validator;
        }
        // Refused past the signature check: by the validator's revocation
        // step, after the guard (an inactive client), or by a later check.
        if ($claims !== null) {
            return $this->classifier->ofStoredCredential($request, $claims);
        }

        return $validator instanceof BearerRejectionReason ? $validator : BearerRejectionReason::Unauthenticated;
    }

    private function isMissingScope(Response $response): bool
    {
        $exception = $response instanceof JsonResponse || $response instanceof IlluminateResponse ? $response->exception : null;
        if ($exception instanceof MissingScopeException || $exception instanceof MissingAbilityException) {
            return true;
        }
        if ($exception instanceof AuthorizationException) {
            return false;
        }

        return str_contains((string) $response->headers->get('WWW-Authenticate'), 'error="insufficient_scope"');
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $requestId) === 1 ? $requestId : null;
    }
}
