<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PortalPaymentMethodService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A client contact adds, defaults and removes the company's saved payment
 * methods from the portal Billing page (#642).
 *
 * Who may is the question autopay asks of consent: a contact with company-wide
 * portal access, never workspace staff previewing the portal and never a
 * contact limited to some projects. The company is the route's, the workspace
 * is the company's, and a method is looked up inside both - another company's
 * is simply not found.
 */
final class PortalPaymentMethodController extends Controller
{
    public function __construct(
        private readonly PortalPaymentMethodService $service,
    ) {}

    /** Create the SetupIntent; the response is its client secret. */
    public function setup(Request $request, ClientCompany $clientCompany): JsonResponse
    {
        [$workspace] = $this->managing($request, $clientCompany, add: true);
        $key = $request->header('Idempotency-Key');

        try {
            $secret = $this->service->startSetup($workspace, $clientCompany, is_string($key) && $key !== '' ? mb_substr($key, 0, 200) : null);
        } catch (DomainException $refusal) {
            return response()->json(['message' => $refusal->getMessage()], 422);
        }

        return response()->json(['client_secret' => $secret], 201);
    }

    /** Read the SetupIntent back from Stripe and record its method if it succeeded. */
    public function confirm(Request $request, ClientCompany $clientCompany): JsonResponse
    {
        [$workspace, $viewer] = $this->managing($request, $clientCompany, add: true);
        $data = $request->validate(['setup_intent' => ['required', 'string', 'regex:/^seti_[A-Za-z0-9_]{1,200}$/D']]);

        try {
            $outcome = $this->service->completeSetup($workspace, $clientCompany, $viewer, $data['setup_intent']);
        } catch (DomainException $refusal) {
            return response()->json(['message' => $refusal->getMessage()], 422);
        }

        return response()->json(['outcome' => $outcome->value, 'message' => $outcome->message()]);
    }

    public function makeDefault(Request $request, ClientCompany $clientCompany, string $paymentMethod): RedirectResponse
    {
        [$workspace, $viewer] = $this->managing($request, $clientCompany, add: false);
        $saved = $this->saved($workspace, $clientCompany, $paymentMethod);

        try {
            $this->service->makeDefault($workspace, $clientCompany, $saved, $viewer);
        } catch (DomainException $refusal) {
            throw ValidationException::withMessages(['payment_method' => $refusal->getMessage()]);
        }

        return redirect()->route('portal.billing', $clientCompany)->with('payment_method_result', 'default');
    }

    public function destroy(Request $request, ClientCompany $clientCompany, string $paymentMethod): RedirectResponse
    {
        [$workspace, $viewer] = $this->managing($request, $clientCompany, add: false);
        $saved = $this->saved($workspace, $clientCompany, $paymentMethod);

        try {
            $this->service->remove($workspace, $clientCompany, $saved, $viewer);
        } catch (DomainException $refusal) {
            throw ValidationException::withMessages(['payment_method' => $refusal->getMessage()]);
        }

        return redirect()->route('portal.billing', $clientCompany)->with('payment_method_result', 'removed');
    }

    /** @return array{0: Workspace, 1: User} */
    private function managing(Request $request, ClientCompany $clientCompany, bool $add): array
    {
        Gate::authorize('viewPortal', $clientCompany);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);
        $workspace = $clientCompany->workspace;
        $management = $this->service->access($workspace, $clientCompany, $viewer)['management'];
        abort_unless($add ? $management->mayAdd() : $management->mayChange(), 403);

        return [$workspace, $viewer];
    }

    private function saved(Workspace $workspace, ClientCompany $clientCompany, string $publicId): ClientStripePaymentMethod
    {
        $saved = $this->service->find($workspace, $clientCompany, $publicId);
        abort_if($saved === null, 404);

        return $saved;
    }
}
