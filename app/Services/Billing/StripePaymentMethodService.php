<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class StripePaymentMethodService
{
    public function __construct(
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param array<string, mixed> $object */
    public function attach(array $object, string $occurrence, int $providerCreatedAt): ?ClientStripePaymentMethod
    {
        $providerId = $this->string($object['id'] ?? null);
        $providerCustomerId = $this->string($object['customer'] ?? null);
        if ($providerId === null || $providerCustomerId === null) {
            return null;
        }

        $state = $this->lockState($providerId);
        if ($this->eventIsOlder($state, $providerCreatedAt, 'attached')) {
            return null;
        }

        $tenant = $this->tenant($providerCustomerId);
        if ($tenant === null) {
            $this->updateState($state, 'attached', $providerCreatedAt, $occurrence);

            return null;
        }
        [$workspace, $company, $customer] = $tenant;
        $existing = ClientStripePaymentMethod::query()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->where('stripe_payment_method_id', $providerId)
            ->tap(Locks::forUpdate())
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

        $this->updateState(
            $state,
            'attached',
            $providerCreatedAt,
            $occurrence,
            $workspace->id,
            $company->id,
            $customer->id,
        );

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

    public function detach(string $providerId, string $occurrence, int $providerCreatedAt): ?int
    {
        $state = $this->lockState($providerId);
        if ($this->eventIsOlder($state, $providerCreatedAt, 'detached')) {
            return $state->workspaceId;
        }

        $workspaceId = $state->workspaceId;
        $companyId = $state->companyId;
        $customerId = $state->customerId;
        if ($workspaceId === null || $companyId === null || $customerId === null) {
            $this->updateState($state, 'detached', $providerCreatedAt, $occurrence);

            return null;
        }

        $workspace = Workspace::query()->find($workspaceId);
        $company = $workspace instanceof Workspace
            ? ClientCompany::query()->where('workspace_id', $workspace->id)->find($companyId)
            : null;
        $customerMatches = $workspace instanceof Workspace && $company instanceof ClientCompany
            && ClientStripeCustomer::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->whereKey($customerId)
                ->exists();
        $method = $workspace instanceof Workspace && $company instanceof ClientCompany && $customerMatches
            ? ClientStripePaymentMethod::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->where('client_stripe_customer_id', $customerId)
                ->where('stripe_payment_method_id', $providerId)
                ->tap(Locks::forUpdate())
                ->first()
            : null;

        if ($method instanceof ClientStripePaymentMethod) {
            $this->forgetLocked($workspace, $company, $method, $occurrence, null);
        }

        $this->updateState(
            $state,
            'detached',
            $providerCreatedAt,
            $occurrence,
            $workspaceId,
            $companyId,
            $customerId,
        );

        return $workspaceId;
    }

    /**
     * Record that the client removed a saved method in the portal and Stripe has
     * detached it. The same local effect as `payment_method.detached`, reached
     * with the tenant already known, so a method that never had a state row is
     * removed too. Run inside a transaction: removing the default turns autopay
     * off, deferred to commit.
     *
     * The state row only ever moves forward, so a late attach event for this
     * method cannot bring it back.
     */
    public function forget(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $saved, User $actor, string $occurrence): bool
    {
        $providerId = $saved->stripe_payment_method_id;
        $state = $this->lockState($providerId);
        $method = ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereKey($saved->id)
            ->tap(Locks::forUpdate())
            ->first();
        if (! $method instanceof ClientStripePaymentMethod) {
            return false;
        }
        $this->forgetLocked($workspace, $company, $method, $occurrence, $actor);
        $this->updateState(
            $state,
            'detached',
            max($state->providerCreatedAt, $this->clock->now($workspace)->getTimestamp()),
            $occurrence,
            $workspace->id,
            $company->id,
            $method->client_stripe_customer_id,
        );

        return true;
    }

    /**
     * Make one saved method the company's only default, mirroring a change
     * already made at Stripe. It does not touch the ordering marker that
     * `customer.updated` events use, so Stripe's own event for this change - or
     * an older one still in flight - is judged on its merits and converges on
     * the same state. Run inside a transaction.
     */
    public function adoptDefault(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $saved, User $actor, string $occurrence): bool
    {
        $customer = ClientStripeCustomer::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereKey($saved->client_stripe_customer_id)
            ->tap(Locks::forUpdate())
            ->first();
        if (! $customer instanceof ClientStripeCustomer) {
            return false;
        }
        $methods = ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->tap(Locks::forUpdate())
            ->get();
        $next = $methods->firstWhere('id', $saved->id);
        if (! $next instanceof ClientStripePaymentMethod) {
            return false;
        }
        if ($methods->firstWhere('is_default', true)?->id === $next->id) {
            return true;
        }

        ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => $this->clock->now($workspace)]);
        $next->forceFill(['is_default' => true])->save();
        $this->activities->record($workspace, $company, 'payment_method.default_changed', $next, [
            'type' => $next->type,
            'brand' => $next->brand,
            'last4' => $next->last4,
            'is_default' => true,
        ], $actor, $occurrence);

        return true;
    }

    private function forgetLocked(Workspace $workspace, ClientCompany $company, ClientStripePaymentMethod $method, string $occurrence, ?User $actor): void
    {
        if ($method->is_default) {
            $method->forceFill(['is_default' => false])->save();
            $this->autopayLosesItsMethod($workspace, $company);
        }
        $this->activities->record($workspace, $company, 'payment_method.removed', $method, [
            'type' => $method->type,
            'brand' => $method->brand,
            'last4' => $method->last4,
        ], $actor, $occurrence);
        $method->delete();
    }

    public function changeDefault(
        string $providerCustomerId,
        ?string $providerMethodId,
        string $occurrence,
        int $providerCreatedAt,
    ): ?int {
        $tenant = $this->tenant($providerCustomerId);
        if ($tenant === null) {
            return null;
        }
        [$workspace, $company, $customer] = $tenant;
        $lastCreatedAt = $customer->default_payment_method_event_created_at;
        if ($lastCreatedAt !== null && ($lastCreatedAt > $providerCreatedAt
            || ($lastCreatedAt === $providerCreatedAt
                && $customer->default_payment_method_event_id !== null
                && $customer->default_payment_method_event_id !== $occurrence))) {
            return $workspace->id;
        }
        $methods = ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->tap(Locks::forUpdate())
            ->get();
        $current = $methods->firstWhere('is_default', true);
        $next = $providerMethodId === null
            ? null
            : $methods->firstWhere('stripe_payment_method_id', $providerMethodId);
        if ($providerMethodId !== null && ! $next instanceof ClientStripePaymentMethod) {
            throw new RuntimeException('The new default Stripe payment method has not been synchronized yet.');
        }
        if ($current?->id === $next?->id) {
            $this->recordDefaultEvent($customer, $providerCreatedAt, $occurrence);

            return $workspace->id;
        }

        ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('client_stripe_customer_id', $customer->id)
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => $this->clock->now($workspace)]);
        if ($next instanceof ClientStripePaymentMethod) {
            $next->forceFill(['is_default' => true])->save();
        }
        $this->recordDefaultEvent($customer, $providerCreatedAt, $occurrence);
        if ($next === null) {
            $this->autopayLosesItsMethod($workspace, $company);
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

    /**
     * Autopay always charges the default method, so removing it turns autopay
     * off (#636). Deferred to commit: this runs inside the webhook's
     * transaction, and the consent write takes its own lock and records its own
     * history, which must not become a new lock inside the payment transaction.
     */
    private function autopayLosesItsMethod(Workspace $workspace, ClientCompany $company): void
    {
        DB::afterCommit(static function () use ($workspace, $company): void {
            try {
                app(AutopayConsentService::class)->defaultMethodRemoved($workspace, $company);
            } catch (\Throwable $failure) {
                // The method is already gone, and eligibility refuses a charge
                // with no default method whether or not this was written down.
                Log::warning('Autopay could not record the removal of its default method.', ['company' => $company->public_id, 'error' => $failure->getMessage()]);
            }
        });
    }

    private function recordDefaultEvent(
        ClientStripeCustomer $customer,
        int $providerCreatedAt,
        string $eventId,
    ): void {
        $customer->forceFill([
            'default_payment_method_event_created_at' => max(0, $providerCreatedAt),
            'default_payment_method_event_id' => $eventId,
        ])->save();
    }

    /** @return array{Workspace, ClientCompany, ClientStripeCustomer}|null */
    private function tenant(string $providerCustomerId): ?array
    {
        $customer = ClientStripeCustomer::query()
            ->where('stripe_customer_id', $providerCustomerId)
            ->tap(Locks::forUpdate())
            ->first();
        if (! $customer instanceof ClientStripeCustomer) {
            return null;
        }
        $workspace = Workspace::query()->findOrFail($customer->workspace_id);
        $company = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($customer->client_company_id);

        return [$workspace, $company, $customer];
    }

    private function lockState(string $providerId): StripePaymentMethodState
    {
        $providerHash = hash('sha256', $providerId);
        $now = $this->clock->now();
        DB::table('stripe_payment_method_states')->insertOrIgnore([
            'provider_id_hash' => $providerHash,
            'state' => 'unknown',
            'provider_created_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StripePaymentMethodState::fromDatabaseRow(
            DB::table('stripe_payment_method_states')
                ->where('provider_id_hash', $providerHash)
                ->tap(Locks::forUpdate())
                ->first(),
        );
    }

    private function eventIsOlder(StripePaymentMethodState $state, int $providerCreatedAt, string $incomingState): bool
    {
        if ($state->providerCreatedAt > $providerCreatedAt) {
            return true;
        }

        // Stripe timestamps have one-second precision. If attach and detach
        // collide in that second, detached is the fail-closed result: Stripe
        // documents detached methods as no longer chargeable.
        return $state->providerCreatedAt === $providerCreatedAt
            && $state->state === 'detached'
            && $incomingState === 'attached';
    }

    /**
     * Move a state row, naming the workspace it is moving *from*.
     *
     * This is the one tenant-owned write in the codebase whose workspace can
     * legitimately be null: the webhook receiver inserts a state row before
     * anything knows which tenant the payment method belongs to, and this is
     * the statement that stamps one once it resolves. So the predicate cannot
     * name the destination - it would match nothing on the very transition
     * that matters - and it must not name nothing either. It names the
     * workspace the row currently carries, which is null exactly while the
     * tenant is unresolved.
     *
     * `$current` comes from `lockState()`, which holds the row `FOR UPDATE`
     * for the rest of the transaction, so the stored value it read is still
     * the stored value here.
     */
    private function updateState(
        StripePaymentMethodState $current,
        string $state,
        int $providerCreatedAt,
        string $eventId,
        ?int $workspaceId = null,
        ?int $companyId = null,
        ?int $customerId = null,
    ): void {
        $row = DB::table('stripe_payment_method_states')->where('id', $current->id);

        if ($current->workspaceId === null) {
            $row->whereNull('workspace_id');
        } else {
            $row->where('workspace_id', $current->workspaceId);
        }

        $row->update([
            'workspace_id' => $workspaceId,
            'client_company_id' => $companyId,
            'client_stripe_customer_id' => $customerId,
            'state' => $state,
            'provider_created_at' => max(0, $providerCreatedAt),
            'stripe_event_id' => $eventId,
            'updated_at' => $this->clock->now(),
        ]);
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
