<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
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
