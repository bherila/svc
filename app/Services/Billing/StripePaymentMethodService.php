<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

final class StripePaymentMethodService
{
    public function __construct(private readonly ClientActivityRecorder $activities) {}

    /** @param array<string, mixed> $object */
    public function attach(array $object, string $occurrence): ?ClientStripePaymentMethod
    {
        $providerId = $this->string($object['id'] ?? null);
        $providerCustomerId = $this->string($object['customer'] ?? null);
        if ($providerId === null || $providerCustomerId === null) {
            return null;
        }

        [$workspace, $company, $customer] = $this->tenant($providerCustomerId);
        $existing = ClientStripePaymentMethod::query()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->where('stripe_payment_method_id', $providerId)
            ->lockForUpdate()
            ->first();

        $type = $this->string($object['type'] ?? null) ?? 'unknown';
        $details = is_array($object[$type] ?? null) ? $object[$type] : [];
        $method = $existing ?? new ClientStripePaymentMethod;
        $wasAdded = ! $method->exists || $method->trashed();
        if ($method->trashed()) {
            $method->restore();
        }
        try {
            $method->forceFill([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_stripe_customer_id' => $customer->id,
                'stripe_payment_method_id' => $providerId,
                'type' => mb_substr($type, 0, 40),
                'brand' => $this->limited($details['brand'] ?? $details['bank_name'] ?? null, 40),
                'last4' => $this->lastFour($details['last4'] ?? null),
                'exp_month' => $this->boundedInteger($details['exp_month'] ?? null, 1, 12),
                'exp_year' => $this->boundedInteger($details['exp_year'] ?? null, 2000, 9999),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('A Stripe payment method cannot cross client or workspace scope.');
        }

        if ($wasAdded) {
            $this->activities->record($workspace, $company, 'payment_method.added', $method, [
                'type' => $method->type,
                'brand' => $method->brand,
                'last4' => $method->last4,
                'exp_month' => $method->exp_month,
                'exp_year' => $method->exp_year,
            ], occurrence: $occurrence);
        }

        return $method;
    }

    public function detach(string $providerId, string $occurrence): ?int
    {
        $method = ClientStripePaymentMethod::query()
            ->where('stripe_payment_method_id', $providerId)
            ->lockForUpdate()
            ->first();
        if ($method === null) {
            return null;
        }

        [$workspace, $company] = $this->tenantForMethod($method);
        if ($method->is_default) {
            $method->forceFill(['is_default' => false])->save();
        }
        $this->activities->record($workspace, $company, 'payment_method.removed', $method, [
            'type' => $method->type,
            'brand' => $method->brand,
            'last4' => $method->last4,
        ], occurrence: $occurrence);
        $method->delete();

        return $workspace->id;
    }

    public function changeDefault(string $providerCustomerId, ?string $providerMethodId, string $occurrence): int
    {
        [$workspace, $company, $customer] = $this->tenant($providerCustomerId);
        $methods = ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->lockForUpdate()
            ->get();
        $current = $methods->firstWhere('is_default', true);
        $next = $providerMethodId === null
            ? null
            : $methods->firstWhere('stripe_payment_method_id', $providerMethodId);
        if ($providerMethodId !== null && ! $next instanceof ClientStripePaymentMethod) {
            throw new RuntimeException('The new default Stripe payment method has not been synchronized yet.');
        }
        if ($current?->id === $next?->id) {
            return $workspace->id;
        }

        ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => now()]);
        if ($next instanceof ClientStripePaymentMethod) {
            $next->forceFill(['is_default' => true])->save();
        }

        $subject = $next ?? $current;
        if (! $subject instanceof ClientStripePaymentMethod) {
            return $workspace->id;
        }
        $this->activities->record($workspace, $company, 'payment_method.default_changed', $subject, [
            'type' => $subject->type,
            'brand' => $subject->brand,
            'last4' => $subject->last4,
            'is_default' => $next instanceof ClientStripePaymentMethod,
        ], occurrence: $occurrence);

        return $workspace->id;
    }

    /** @return array{Workspace, ClientCompany, ClientStripeCustomer} */
    private function tenant(string $providerCustomerId): array
    {
        $customer = ClientStripeCustomer::query()
            ->where('stripe_customer_id', $providerCustomerId)
            ->lockForUpdate()
            ->first();
        if (! $customer instanceof ClientStripeCustomer) {
            throw new RuntimeException('The Stripe customer has not been synchronized yet.');
        }
        $workspace = Workspace::query()->findOrFail($customer->workspace_id);
        $company = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($customer->client_company_id);

        return [$workspace, $company, $customer];
    }

    /** @return array{Workspace, ClientCompany} */
    private function tenantForMethod(ClientStripePaymentMethod $method): array
    {
        $workspace = Workspace::query()->findOrFail($method->workspace_id);
        $company = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($method->client_company_id);
        $customerMatches = ClientStripeCustomer::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereKey($method->client_stripe_customer_id)
            ->exists();
        if (! $customerMatches) {
            throw new DomainException('A Stripe payment method cannot cross client or workspace scope.');
        }

        return [$workspace, $company];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function limited(mixed $value, int $length): ?string
    {
        $value = $this->string($value);

        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function lastFour(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value !== null && preg_match('/^[A-Za-z0-9]{4}$/', $value) === 1 ? $value : null;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $value = (int) $value;

        return $value >= $minimum && $value <= $maximum ? $value : null;
    }
}
