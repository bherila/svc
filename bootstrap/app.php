<?php

use App\Exceptions\InvalidAgentApiCursor;
use App\Http\Middleware\EnforceAgentMcpOrigin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectMcpQueryCredentials;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RejectMcpQueryCredentials::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceAgentMcpOrigin::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ExpectOAuthResource::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
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
                        url('/.well-known/oauth-protected-resource/api/v1/mcp'),
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

            return response()->json(['message' => $exception->getMessage()], 422);
        });
    })->create();
