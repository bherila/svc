<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class EngagementController extends Controller
{
    /** @param array<string, mixed> $payload */
    protected function respond(
        Request $request,
        array $payload,
        string $route,
        string $message,
        int $status = 200,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json($payload, $status);
        }

        unset($route);

        return redirect()->back()->with('status', $message);
    }

    /**
     * Put a conflict where the caller can render it.
     *
     * The mutation service refuses with `abort(409)` for the conflicts an
     * operator can act on - a version read before someone else's edit, an
     * entry already carried by an invoice. Those messages are written for a
     * person, but a browser visit receiving a bare 409 renders an error page
     * instead: the dialog's error handler only ever sees validation errors.
     * A JSON caller keeps the status it is written against.
     */
    protected function reportConflict(Request $request, HttpExceptionInterface $exception): JsonResponse
    {
        if ($request->expectsJson() || $exception->getStatusCode() !== 409) {
            throw $exception;
        }

        return $this->reportFailure($request, new EngagementException($exception->getMessage()));
    }

    protected function reportFailure(Request $request, EngagementException $exception): JsonResponse
    {
        if (! $request->expectsJson()) {
            throw ValidationException::withMessages(['engagement' => $exception->getMessage()]);
        }

        return response()->json(['message' => $exception->getMessage()], 422);
    }
}
