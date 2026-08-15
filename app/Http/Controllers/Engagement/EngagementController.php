<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    protected function reportFailure(Request $request, EngagementException $exception): JsonResponse
    {
        if (! $request->expectsJson()) {
            throw ValidationException::withMessages(['engagement' => $exception->getMessage()]);
        }

        return response()->json(['message' => $exception->getMessage()], 422);
    }
}
