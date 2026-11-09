<?php

namespace App\Services\Billing\Autopay;

use App\Exceptions\AutopayVersionConflict;
use App\Mail\AutopayEnabledNoticeMail;
use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Payments\PaymentConnectionResolver;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentAction;
use App\Support\Billing\Autopay\AutopayConsentMethod;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use App\Support\Billing\Autopay\AutopayWording;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Turning autopay on, off, and changing its limit (#636). Every change appends
 * a consent record in the same transaction as the company's switch; nothing
 * here talks to Stripe. The company row is the only lock taken.
 *
 * A provider's turn-on is announced to the client's billing contacts by email,
 * sent in this request once the transaction has committed - never queued, and
 * never inside the transaction, because an email already gone cannot be rolled
 * back.
 */
final class AutopayConsentService
{
    /** Ten million in the company's currency: a typing slip is refused, not recorded. */
    public const int MAX_LIMIT = 1_000_000_000;

    public function __construct(
        private readonly AutopayReader $reader,
        private readonly PaymentConnectionResolver $connections,
        private readonly InvoiceEmailService $recipients,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** The authorization sentence for these facts, exactly as it is shown and stored. */
    public function authorizationWording(Workspace $workspace, ClientCompany $company, int $limit): ?string
    {
        $method = $this->reader->defaultMethod($workspace->id, $company->id);

        return $method === null ? null : AutopayWording::authorization(
            $workspace->name,
            $company->name,
            $this->reader->methodLabel($method),
            $limit,
            $this->reader->limitCurrency($workspace, $company),
        );
    }

    /**
     * Turn autopay on, or change its limit when it is already on. Asking for
     * the state it is already in changes and records nothing - except a
     * client authorizing, in their own words, the limit a provider turned on:
     * that records the client's consent, with no notice.
     *
     * @throws DomainException when the request is not one autopay can honour
     * @throws AutopayVersionConflict
     */
    public function turnOn(Workspace $workspace, ClientCompany $company, AutopayConsentRequest $request, ?string $expectedVersion = null): ClientAutopayConsent
    {
        $notice = null;
        $consent = DB::transaction(function () use ($workspace, $company, $request, $expectedVersion, &$notice): ClientAutopayConsent {
            $locked = $this->lock($workspace, $company, $expectedVersion);
            $this->assertMayConsent($request);
            if ($request->limit < 1 || $request->limit > self::MAX_LIMIT) {
                throw new DomainException('The autopay limit must be between 0.01 and '.number_format(self::MAX_LIMIT / 100, 2).'.');
            }
            if ($this->connections->stripe($workspace) === null) {
                throw new DomainException('Stripe is not connected for this workspace, so autopay cannot be turned on.');
            }
            $method = $this->reader->defaultMethod($workspace->id, $locked->id);
            if ($method === null) {
                throw new DomainException('Autopay needs a default saved payment method. Add one first.');
            }
            $label = $this->reader->methodLabel($method);
            $currency = $this->reader->limitCurrency($workspace, $locked);

            $wording = null;
            $attestation = null;
            if ($request->role === AutopayActorRole::Client) {
                $wording = AutopayWording::authorization($workspace->name, $locked->name, $label, $request->limit, $currency);
                if ($request->wording !== $wording) {
                    throw new DomainException('The authorization wording changed since it was shown. Reload and read it again.');
                }
            } else {
                $attestation = trim((string) $request->attestation);
                $length = mb_strlen($attestation);
                if ($length < AutopayWording::ATTESTATION_MIN || $length > AutopayWording::ATTESTATION_MAX) {
                    throw new DomainException('Say how the client agreed to autopay, in '.AutopayWording::ATTESTATION_MIN.' to '.AutopayWording::ATTESTATION_MAX.' characters.');
                }
                if ($this->recipients->suggestedRecipientsForCompany($locked) === []) {
                    throw new DomainException('The client has no billing address or portal contact to notify, so autopay cannot be turned on for them.');
                }
            }

            $on = $locked->autopay_enabled_at !== null && $locked->autopay_limit_amount !== null;
            $sameLimit = $on && (int) $locked->autopay_limit_amount === $request->limit;
            if ($sameLimit) {
                $standing = $this->latest($workspace, $locked) ?? throw new DomainException('Autopay is on without a consent record.');
                // A replay - or a provider asking for what already stands -
                // changes nothing. A client authorizing the limit a provider
                // turned on is not a replay: it is the client's own consent,
                // which a service package may require (ACC-4), so it is
                // recorded. The provider's turn-on was announced already.
                if ($request->role !== AutopayActorRole::Client || $standing->actor_role === AutopayActorRole::Client) {
                    return $standing;
                }
            }
            $action = $on && ! $sameLimit ? AutopayConsentAction::LimitChanged : AutopayConsentAction::Enabled;
            $now = $this->clock->now($workspace)->utc();
            $before = ['enabled' => $on, 'limit' => $locked->autopay_limit_amount];
            $locked->forceFill([
                'autopay_enabled_at' => $on ? $locked->autopay_enabled_at : $now,
                'autopay_limit_amount' => $request->limit,
            ])->save();

            $consent = ClientAutopayConsent::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $locked->id,
                'action' => $action,
                'actor_user_id' => $request->actor->id,
                'actor_name' => mb_substr((string) $request->actor->name, 0, 191),
                'actor_role' => $request->role,
                'method' => $request->role === AutopayActorRole::Client ? AutopayConsentMethod::PortalCheckbox : AutopayConsentMethod::ProviderAttestation,
                'limit_amount' => $request->limit,
                'currency' => $currency,
                'payment_method_label' => mb_substr($label, 0, 120),
                'wording' => $wording,
                'attestation' => $attestation,
                'occurred_at' => $now,
            ]);
            $this->activities->record($workspace, $locked, 'client.autopay_updated', $locked, [
                'before' => $before,
                'after' => ['enabled' => true, 'limit' => $request->limit],
                'role' => $request->role->value,
            ], $request->actor, $consent->public_id);

            if ($request->role === AutopayActorRole::Provider) {
                $notice = [$locked, $consent, $label, $currency, $attestation];
            }

            return $consent;
        });

        if ($notice !== null) {
            // After the commit, outside the transaction, and without letting a
            // mail failure undo a change that stands.
            DB::afterCommit(fn () => $this->announce($workspace, ...$notice));
        }

        return $consent;
    }

    /**
     * Turn autopay off. Either side may; SVC does it when the default method
     * goes. Already off changes and records nothing and returns null.
     */
    public function turnOff(Workspace $workspace, ClientCompany $company, AutopayActorRole $role, ?User $actor, ?string $reason = null, ?string $expectedVersion = null): ?ClientAutopayConsent
    {
        return DB::transaction(function () use ($workspace, $company, $role, $actor, $reason, $expectedVersion): ?ClientAutopayConsent {
            $locked = $this->lock($workspace, $company, $expectedVersion);
            if ($locked->autopay_enabled_at === null) {
                return null;
            }
            $limit = $locked->autopay_limit_amount;
            $locked->forceFill(['autopay_enabled_at' => null, 'autopay_limit_amount' => null])->save();
            $now = $this->clock->now($workspace)->utc();
            $consent = ClientAutopayConsent::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $locked->id,
                'action' => AutopayConsentAction::Disabled,
                'actor_user_id' => $actor?->id,
                'actor_name' => $actor === null ? null : mb_substr((string) $actor->name, 0, 191),
                'actor_role' => $role,
                'method' => $role === AutopayActorRole::System ? AutopayConsentMethod::Automatic : AutopayConsentMethod::Withdrawal,
                'limit_amount' => null,
                'currency' => $this->reader->limitCurrency($workspace, $locked),
                'reason' => $reason === null ? null : mb_substr($reason, 0, 191),
                'occurred_at' => $now,
            ]);
            $this->activities->record($workspace, $locked, 'client.autopay_updated', $locked, [
                'before' => ['enabled' => true, 'limit' => $limit],
                'after' => ['enabled' => false, 'limit' => null],
                'role' => $role->value,
            ], $actor, $consent->public_id);

            return $consent;
        });
    }

    /**
     * The default saved method was removed: autopay has nothing to charge, so
     * it is turned off and the record says why. Does nothing while a default
     * method still stands, or autopay is already off.
     */
    public function defaultMethodRemoved(Workspace $workspace, ClientCompany $company): ?ClientAutopayConsent
    {
        if ($company->workspace_id !== $workspace->id || $this->reader->defaultMethod($workspace->id, $company->id) !== null) {
            return null;
        }

        return $this->turnOff($workspace, $company, AutopayActorRole::System, null, 'Default method removed');
    }

    /**
     * Every record for the company, newest first.
     *
     * @return list<ClientAutopayConsent>
     */
    public function history(Workspace $workspace, ClientCompany $company, int $limit = 50): array
    {
        return array_values(ClientAutopayConsent::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all());
    }

    private function latest(Workspace $workspace, ClientCompany $company): ?ClientAutopayConsent
    {
        return ClientAutopayConsent::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->orderByDesc('id')
            ->first();
    }

    private function lock(Workspace $workspace, ClientCompany $company, ?string $expectedVersion): ClientCompany
    {
        $locked = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($company->id)
            ->tap(Locks::forUpdate())
            ->firstOrFail();
        if ($expectedVersion !== null && ! AgentApiVersion::matches($locked, $expectedVersion)) {
            throw new AutopayVersionConflict('The client has changed; read it and retry.');
        }

        return $locked;
    }

    private function assertMayConsent(AutopayConsentRequest $request): void
    {
        match ($request->role) {
            AutopayActorRole::Client, AutopayActorRole::Provider => null,
            AutopayActorRole::System => throw new DomainException('SVC does not turn autopay on.'),
        };
    }

    private function announce(Workspace $workspace, ClientCompany $company, ClientAutopayConsent $consent, string $label, string $currency, string $attestation): void
    {
        try {
            $addresses = array_column($this->recipients->suggestedRecipientsForCompany($company), 'email');
            if ($addresses === []) {
                return;
            }
            Mail::to($addresses)->send(new AutopayEnabledNoticeMail(
                $workspace->name,
                $company->name,
                $label,
                AutopayWording::money((int) $consent->limit_amount, $currency),
                $attestation,
                route('portal.billing', $company, absolute: true),
            ));
            $consent->forceFill(['notice_sent' => true])->save();
        } catch (Throwable $failure) {
            Log::warning('The autopay notice to the client could not be sent.', ['consent' => $consent->public_id, 'error' => $failure->getMessage()]);
        }
    }
}
