<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * What the engagement screens' writes answer.
 *
 * Each answers the page that made it, and nothing else: there is no JSON shape
 * of its own beside the Inertia props and the versioned API's presenters
 * (#454). A program writes agreements and proposals through `/api/v1`.
 */
abstract class EngagementController extends Controller
{
    protected function respond(string $message): RedirectResponse
    {
        return redirect()->back()->with('status', $message);
    }

    /** Put a refusal where the form shows its errors. */
    protected function reportFailure(EngagementException $exception): never
    {
        throw ValidationException::withMessages(['engagement' => $exception->getMessage()]);
    }
}
