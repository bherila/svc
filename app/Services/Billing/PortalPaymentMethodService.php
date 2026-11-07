<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\PortalAutopayAuthority;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\Billing\PaymentMethods\PaymentMethodManagement;
use App\Support\Billing\PaymentMethods\SetupIntentStatus;
use App\Support\Billing\PaymentMethods\SetupOutcome;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A client contact manages the company's saved payment methods from the portal
 * (#642): add one, choose the default, remove one.
 *
 * Stripe is called first and never inside a transaction - none of its answers
 * can be rolled back - and the local record follows, in its own short
 * transaction, through the same {@see StripePaymentMethodService} the webhook
 * uses. So the webhook for the same change is a no-op rather than a second
 * write, and if this process dies between the two, the webhook still brings
 * the record in line. The caller has already established that the viewer may
 * act for the company; every read and write here names the workspace.
 */
final class PortalPaymentMethodService
{
    public function __construct(
        private readonly StripePaymentMethodGateway $stripe,
        private readonly StripePaymentMethodService $methods,
        private readonly PortalAutopayAuthority $authority,
        private readonly StripeGateway $connection,
        private readonly PaymentConnectionResolver $connections,
    ) {}

    /**
     * What this viewer may do to this company's methods, and the publishable key
     * of the workspace's Stripe account for the browser's form. One decision for
     * the page and the routes alike.
     *
     * @return array{management: PaymentMethodManagement, publishable_key: string|null}
     */
    public function access(Workspace $workspace, ClientCompany $company, User $viewer): array
    {
        $key = $this->connections->stripePublishableKey($workspace);

        return [
            'management' => PaymentMethodManagement::decide(
                $this->authority->may($company, $viewer),
                $this->connection->access($workspace) !== null,
                $key,
            ),
            'publishable_key' => $key,
        ];
    }

    /**
     * Begin adding a method: make sure the company has a Stripe customer, then
     * create the SetupIntent. Returns the client secret and nothing else.
     *
     * @param  string|null  $attempt  the browser's idempotency key, so a retried request gets the same SetupIntent
     */
    public function startSetup(Workspace $workspace, ClientCompany $company, ?string $attempt): string
    {
        $customer = $this->customer($workspace, $company);

        return $this->stripe->createSetupIntent($workspace, $company, $customer->stripe_customer_id, $attempt);
    }

    /**
     * Read a SetupIntent back from Stripe and, if it succeeded, record its
     * method now rather than waiting for the webhook. Idempotent with the
     * webhook: both go through `StripePaymentMethodService::attach`.
     *
     * A SetupIntent of any other customer - another company's, in this same
     * Stripe account - is refused as not found.
     */
    public function completeSetup(Workspace $workspace, ClientCompany $company, User $actor, string $setupIntentId): SetupOutcome
    {
        $customer = $this->existingCustomer($workspace, $company);
        $intent = $this->stripe->setupIntent($workspace, $setupIntentId);
        if ($customer === null || $intent['customer'] !== $customer->stripe_customer_id) {
            throw new DomainException('That payment method setup was not found for this company.');
        }

        $outcome = SetupIntentStatus::outcomeOf($intent['status']);
        if ($outcome !== SetupOutcome::Saved) {
            return $outcome;
        }
        if ($intent['payment_method'] === null) {
            return SetupOutcome::Pending;
        }

        $object = $this->stripe->paymentMethod($workspace, $intent['payment_method']);
        if (($object['customer'] ?? null) !== $customer->stripe_customer_id) {
            throw new DomainException('That payment method setup was not found for this company.');
        }
        $createdAt = is_int($object['created'] ?? null) ? max(0, $object['created']) : 0;
        $saved = DB::transaction(fn (): ?ClientStripePaymentMethod => $this->methods->attach($object, 'portal-setup:'.$setupIntentId, $createdAt));
        $saved ??= $this->saved($workspace, $company, $intent['payment_method']);
        if ($saved === null) {
            // Stripe's own event for this method was applied first and a detach
            // since removed it: there is nothing to show as saved.
            return SetupOutcome::Failed;
        }

        $this->defaultIfFirst($workspace, $company, $customer, $saved, $actor);

        return SetupOutcome::Saved;
    }

    /** Make a saved method the company's default at Stripe, then here. */
    public function makeDefault(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $saved, User $actor): void
    {
        $customer = $this->customerOf($workspace, $company, $saved);
        $this->stripe->makeDefault($workspace, $customer->stripe_customer_id, $saved->stripe_payment_method_id);
        $this->mirrorDefault($workspace, $company, $saved, $actor);
    }

    /**
     * Detach a saved method at Stripe, then forget it here. Removing the default
     * turns autopay off, once the removal has committed.
     */
    public function remove(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $saved, User $actor): void
    {
        $this->customerOf($workspace, $company, $saved);
        $this->stripe->detach($workspace, $saved->stripe_payment_method_id);
        DB::transaction(fn (): bool => $this->methods->forget($workspace, $company, $saved, $actor, 'portal-remove:'.Str::uuid()));
    }

    /** A saved method of this company, or null: another company's or workspace's is never found. */
    public function find(Workspace $workspace, ClientCompany $company, string $publicId): ?ClientStripePaymentMethod
    {
        return ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * The first method a company saves becomes its default: autopay charges the
     * default, and a company with one method and no default cannot use it.
     */
    private function defaultIfFirst(Workspace $workspace, ClientCompany $company, ClientStripeCustomer $customer, ClientStripePaymentMethod $saved, User $actor): void
    {
        $hasDefault = ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('is_default', true)
            ->exists();
        if ($hasDefault) {
            return;
        }

        $this->stripe->makeDefault($workspace, $customer->stripe_customer_id, $saved->stripe_payment_method_id);
        $this->mirrorDefault($workspace, $company, $saved, $actor);
    }

    private function mirrorDefault(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $saved, User $actor): void
    {
        $adopted = DB::transaction(fn (): bool => $this->methods->adoptDefault($workspace, $company, $saved, $actor, 'portal-default:'.Str::uuid()));
        if (! $adopted) {
            throw new DomainException('That payment method is no longer saved.');
        }
    }

    private function saved(Workspace $workspace, ClientCompany $company, string $providerId): ?ClientStripePaymentMethod
    {
        return ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('stripe_payment_method_id', $providerId)
            ->first();
    }

    private function existingCustomer(Workspace $workspace, ClientCompany $company): ?ClientStripeCustomer
    {
        return ClientStripeCustomer::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->first();
    }

    private function customerOf(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $saved): ClientStripeCustomer
    {
        $customer = ClientStripeCustomer::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereKey($saved->client_stripe_customer_id)
            ->first();
        if (! $customer instanceof ClientStripeCustomer || $saved->workspace_id !== $workspace->id || $saved->client_company_id !== $company->id) {
            throw new DomainException('That payment method does not belong to this company.');
        }

        return $customer;
    }

    /**
     * The company's Stripe customer, created at Stripe and recorded here on
     * first use. The Stripe call carries an idempotency key derived from the
     * pair and the local row is unique per company, so a repeat or a race ends
     * with one customer.
     */
    private function customer(Workspace $workspace, ClientCompany $company): ClientStripeCustomer
    {
        $existing = $this->existingCustomer($workspace, $company);
        if ($existing instanceof ClientStripeCustomer) {
            return $existing;
        }

        $providerId = $this->stripe->createCustomer($workspace, $company);
        try {
            return ClientStripeCustomer::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'stripe_customer_id' => $providerId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existingCustomer($workspace, $company)
                ?? throw new DomainException('That Stripe customer already belongs to another client.');
        }
    }
}
