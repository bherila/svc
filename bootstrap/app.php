<?php

use App\Exceptions\InvalidAgentApiCursor;
use App\Http\Middleware\AuthenticateFirstPartySession;
use App\Http\Middleware\EnsureAgentWorkspaceVisible;
use App\Http\Middleware\EnsureOperationDeployed;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogRejectedBearer;
use App\Support\Billing\RetryableConflict;
use Bherila\McpLaravelBridge\Http\GateOperation;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use BWH\Auth\Http\Middleware\RequireActiveProviderSession;
use BWH\Auth\OAuth\Server\OAuthProtectedResource;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use League\OAuth2\Server\Exception\OAuthServerException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies (only X-Forwarded-For and its scheme, only from the
        // configured proxies) are applied by the auth package from
        // bherila-auth.trusted_proxies.
        // First on every API route, ahead of anything that can refuse a
        // bearer: one log line per refusal, with a safe fingerprint (#441).
        $middleware->api(prepend: [LogRejectedBearer::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, McpHttpSecurityMiddleware::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ExpectOAuthResource::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AuthenticateFirstPartySession::class);
        // An agent operation's route checks run in this order: a workspace
        // the caller cannot see answers 404 exactly as a missing one does,
        // then a switched-off cutover answers 404, and only then does the
        // registry's gate check scopes (403) - so neither a hidden workspace
        // nor the deployment's switches are disclosed to an under-scoped
        // caller (#408).
        $middleware->appendToPriorityList(SubstituteBindings::class, EnsureAgentWorkspaceVisible::class);
        $middleware->appendToPriorityList(EnsureAgentWorkspaceVisible::class, EnsureOperationDeployed::class);
        $middleware->appendToPriorityList(EnsureOperationDeployed::class, GateOperation::class);
        // Ends a signed-in browser session whose provider identity was
        // disabled, deleted, reset or ungranted (a no-op until provider
        // identity enforcement is enabled). Inside Inertia's middleware, so an
        // ended session's redirect after a PUT or DELETE becomes a 303.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RequireActiveProviderSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A repository remote is the one ordinary field an operator pastes that
        // can carry a secret: `https://user:token@host/owner/name.git` is what a
        // machine with stored credentials prints. The normalizer strips the
        // credential before anything is saved, but a refused save never reaches
        // the normalizer - Laravel flashes the raw input on the redirect back,
        // and this deployment stores sessions in the database, so the token
        // would outlive the request in the `sessions` table. Merged with the
        // framework's own password entries rather than replacing them.
        //
        // The form does not need it flashed: Inertia's `useForm` holds the
        // operator's edits in the browser, so the field survives the round trip
        // without the server echoing it back.
        $exceptions->dontFlash(['repository']);

        // Passport reports why its validator refused a bearer (malformed,
        // expired, bad signature, revoked) and then answers a bare 401. On an
        // API route the reason goes into that request's rejection log line,
        // which replaces the report and its token-bearing stack trace.
        $exceptions->report(static function (OAuthServerException $exception): ?bool {
            return LogRejectedBearer::recordValidatorFailure($exception) ? false : null;
        });

        // A route that declared its protected resource (ExpectOAuthResource:<name>)
        // challenges with that resource's own metadata document, from
        // configuration rather than the request's host (RFC 9728 section 5.1).
        // Anything else under /api/v1 keeps the plain JSON 401, pointing at
        // the REST resource it sits under. Every route there names its
        // resource today, the finance reconciliation routes included (#457).
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            $challenge = OAuthProtectedResource::unauthenticated($request);
            if ($challenge !== null) {
                return $challenge;
            }
            if (! $request->is('api/v1/*')) {
                return null;
            }

            return response()->json(
                ['message' => 'Unauthenticated.'],
                401,
                [
                    'Cache-Control' => 'private, no-store',
                    'WWW-Authenticate' => sprintf(
                        'Bearer resource_metadata="%s"',
                        OAuthProtectedResource::metadataUrl(OAuthResourceIndicator::defaultName()),
                    ),
                ],
            );
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (InvalidAgentApiCursor $exception, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The pagination cursor is not valid for this request.',
            ], 422);
        });
        // Billing services signal user-correctable preconditions (over-balance
        // payment, double-issue, voiding with pending payments) as DomainException;
        // render them as 422s like EngagementController::reportFailure does, not 500s.
        // The Stripe webhook controller catches DomainException first and keeps its
        // own conflict semantics.
        $exceptions->render(function (DomainException $exception, Request $request) {
            if (! $request->expectsJson()) {
                throw ValidationException::withMessages([
                    'billing' => $exception->getMessage(),
                ]);
            }

            // State moved under the request and nothing was written: the same
            // request made again is checked against current state. 409 is what
            // the agent contract means by "re-read and retry".
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception instanceof RetryableConflict ? 409 : 422,
            );
        });
    })->create();
