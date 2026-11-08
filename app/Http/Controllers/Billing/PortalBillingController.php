<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\Autopay\AutopayReader;
use App\Services\Billing\Autopay\PortalAutopayAuthority;
use App\Services\Billing\PortalPaymentMethodService;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use App\Support\AgentApi\Presenters\AutopayPresenter;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use App\Support\Billing\InvoiceStatus;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client's billing page in the portal (#636): the saved payment methods and
 * autopay, with the one place a client contact consents to it.
 *
 * Consent is the client's own act. Only a contact of the company with
 * company-wide portal access can give or withdraw it; workspace staff who are
 * previewing the portal read the page and nothing more - they have their own,
 * attested, route on the client's settings.
 */
final class PortalBillingController extends Controller
{
    public function __construct(
        private readonly PortalAutopayAuthority $authority,
        private readonly AutopayConsentService $consents,
        private readonly AutopayReader $reader,
        private readonly AutopayPresenter $presenter,
        private readonly PaymentConnectionResolver $connections,
        private readonly PortalPaymentMethodService $methodManagement,
    ) {}

    public function show(Request $request, ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        $workspace = $clientCompany->workspace;
        $may = $this->authority->may($clientCompany, $viewer);
        $autopay = $this->presenter->company($workspace, $clientCompany);
        $largest = (int) ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('is_visible_to_client', true)
            ->whereIn('status', InvoiceStatus::charged())
            ->max('total_amount');
        $method = $this->reader->defaultMethod($workspace->id, $clientCompany->id);
        ['management' => $management, 'publishable_key' => $publishableKey] = $this->methodManagement->access($workspace, $clientCompany, $viewer);

        return Inertia::render('portal/billing', [
            'company' => AgentClientPresenter::identity($clientCompany),
            'provider_name' => $workspace->name,
            'home_href' => route('portal.show', $clientCompany, absolute: false),
            // Autopay needs the provider's own Stripe account to charge.
            'autopay_available' => $this->connections->stripe($workspace) !== null,
            'autopay' => [
                'enabled' => $autopay['enabled'],
                'enabled_at' => $autopay['enabled_at'],
                'limit_amount' => $autopay['limit_amount'],
                'currency' => $autopay['currency'],
                'method' => $autopay['method'],
                'upcoming' => $autopay['upcoming'],
                'consents' => $autopay['consents'],
                // Finished destinations and the wording's parts, or null for a
                // viewer who may not consent: the browser interprets no flag.
                'enable_href' => $may ? route('portal.autopay.enable', $clientCompany, absolute: false) : null,
                'disable_href' => $may && $autopay['enabled'] ? route('portal.autopay.disable', $clientCompany, absolute: false) : null,
                'wording' => $method === null ? null : [
                    'provider' => $workspace->name,
                    'company' => $clientCompany->name,
                    'method' => $this->reader->methodLabel($method),
                    'currency' => $autopay['currency'],
                ],
                'suggested_limit' => $this->suggestedLimit($largest),
                'largest_invoice' => $largest > 0 ? $largest : null,
            ],
            // Finished destinations, or null for a viewer who may not use them.
            'payment_method_setup' => $management->mayAdd() && $publishableKey !== null ? [
                'setup_href' => route('portal.payment-methods.setup', $clientCompany, absolute: false),
                'confirm_href' => route('portal.payment-methods.confirm', $clientCompany, absolute: false),
                'publishable_key' => $publishableKey,
            ] : null,
            'payment_methods_notice' => $management->notice($workspace->name),
            'payment_methods' => ClientStripePaymentMethod::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $clientCompany->id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->limit(25)
                ->get()
                ->map(fn (ClientStripePaymentMethod $saved): array => [
                    'id' => $saved->public_id,
                    'label' => $this->reader->methodLabel($saved),
                    'type' => $saved->type,
                    'exp_month' => $saved->exp_month,
                    'exp_year' => $saved->exp_year,
                    'is_default' => $saved->is_default,
                    'default_href' => $management->mayChange() && ! $saved->is_default
                        ? route('portal.payment-methods.default', [$clientCompany, $saved->public_id], absolute: false) : null,
                    'remove_href' => $management->mayChange()
                        ? route('portal.payment-methods.destroy', [$clientCompany, $saved->public_id], absolute: false) : null,
                ])->values()->all(),
            'method_result' => session('payment_method_result'),
            'result' => session('autopay_result'),
        ]);
    }

    public function enable(Request $request, ClientCompany $clientCompany): RedirectResponse
    {
        $viewer = $this->consenting($request, $clientCompany);
        $data = $request->validate([
            'limit_amount' => ['required', 'integer', 'min:1', 'max:'.AutopayConsentService::MAX_LIMIT],
            'wording' => ['required', 'string', 'max:1000'],
            'authorized' => ['required', 'accepted'],
        ]);

        try {
            $this->consents->turnOn($clientCompany->workspace, $clientCompany, new AutopayConsentRequest(
                AutopayActorRole::Client,
                $viewer,
                (int) $data['limit_amount'],
                wording: $data['wording'],
            ));
        } catch (DomainException $refusal) {
            throw ValidationException::withMessages(['authorized' => $refusal->getMessage()]);
        }

        return redirect()->route('portal.billing', $clientCompany)->with('autopay_result', 'on');
    }

    public function disable(Request $request, ClientCompany $clientCompany): RedirectResponse
    {
        $viewer = $this->consenting($request, $clientCompany);
        $this->consents->turnOff($clientCompany->workspace, $clientCompany, AutopayActorRole::Client, $viewer);

        return redirect()->route('portal.billing', $clientCompany)->with('autopay_result', 'off');
    }

    private function consenting(Request $request, ClientCompany $clientCompany): User
    {
        Gate::authorize('viewPortal', $clientCompany);
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);
        abort_unless($this->authority->may($clientCompany, $viewer), 403);

        return $viewer;
    }

    /** Room above the largest invoice so the next one is not left over the limit: the next $500, at least $1,000. */
    private function suggestedLimit(int $largest): int
    {
        return max(100_000, (int) (ceil($largest / 50_000) * 50_000));
    }
}
