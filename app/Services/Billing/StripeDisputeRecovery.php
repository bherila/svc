<?php

namespace App\Services\Billing;

use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\StripeDisputeSnapshot;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Concurrency\Locks;
use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorReadBudget;
use DomainException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Stripe\Exception\ApiErrorException;

/**
 * Current filtered dispute groups: a webhook is only a hint, not ordering authority.
 *
 * @see https://docs.stripe.com/api/disputes/list
 * @see https://docs.stripe.com/api/disputes/object
 */
final class StripeDisputeRecovery
{
    private const int MAX_PAGES = 100;

    private const int PAGE_SIZE = 100;

    /** Existing policy: unresolved inquiries and chargebacks hold their contested amount. */
    private const array RESOLVED = [
        'warning_needs_response' => false,
        'warning_under_review' => false,
        'warning_closed' => true,
        'needs_response' => false,
        'under_review' => false,
        'won' => true,
        'lost' => false,
        'prevented' => true,
    ];

    public function __construct(private readonly StripeGateway $gateway, private readonly InvoiceLifecycleService $invoices) {}

    public function recover(Workspace $workspace, int $paymentId, bool $apply = false, ?ProcessorReadBudget $budget = null): StripeDisputeSnapshot
    {
        $snapshot = $this->read($workspace, $paymentId, $budget);
        $this->apply($workspace, $snapshot, $apply);

        return $snapshot;
    }

    /** No database transaction is held across provider calls. */
    public function read(Workspace $workspace, int $paymentId, ?ProcessorReadBudget $budget = null): StripeDisputeSnapshot
    {
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
            ->whereKey($paymentId)->where('provider', 'stripe')->first();
        $identifier = $payment?->provider_payment_identifier;
        if ($payment === null || $payment->provider !== 'stripe' || ! is_string($identifier)
            || preg_match('/^(pi|ch)_[A-Za-z0-9_]{1,252}$/D', $identifier) !== 1
            || $payment->amount < 1 || $payment->amount > ProcessorMovementFacts::MAX_MINOR
            || ! is_string($payment->currency) || preg_match('/^[A-Z]{3}$/D', $payment->currency) !== 1) {
            throw new DomainException('The Stripe dispute payment is unavailable.');
        }
        $version = $payment->lock_version;
        $paymentAmount = $payment->amount;
        $currency = $payment->currency;
        unset($payment);
        $filter = str_starts_with($identifier, 'pi_') ? 'payment_intent' : 'charge';
        $params = [$filter => $identifier, 'limit' => self::PAGE_SIZE];
        $disputes = [];
        $seen = [];
        $withheld = 0;
        try {
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $budget?->consume();
                $response = $this->gateway->client()->disputes->all($params)->toArray();
                $records = $response['data'] ?? null;
                if (! is_array($records) || ! array_is_list($records) || count($records) > self::PAGE_SIZE
                    || ! is_bool($response['has_more'] ?? null)) {
                    throw self::unreadable();
                }
                $last = null;
                foreach ($records as $record) {
                    if (! is_array($record) || ($record['object'] ?? null) !== 'dispute') {
                        throw self::unreadable();
                    }
                    $id = self::reference($record['id'] ?? null);
                    $status = $record['status'] ?? null;
                    $recordCurrency = $record['currency'] ?? null;
                    $amount = $record['amount'] ?? null;
                    $intent = self::reference($record['payment_intent'] ?? null);
                    $charge = self::reference($record['charge'] ?? null);
                    $nestedIntent = is_array($record['charge'] ?? null) ? self::reference($record['charge']['payment_intent'] ?? null) : null;
                    if ($id === null || isset($seen[$id]) || $charge === null
                        || ! is_string($status) || ! array_key_exists($status, self::RESOLVED)
                        || ! is_string($recordCurrency) || preg_match('/^[A-Za-z]{3}$/D', $recordCurrency) !== 1 || strtoupper($recordCurrency) !== $currency
                        || ! is_int($amount) || $amount < 1 || $amount > $paymentAmount
                        || ($intent !== null && $nestedIntent !== null && $intent !== $nestedIntent)
                        || ($filter === 'payment_intent' ? ($intent ?? $nestedIntent) !== $identifier : $charge !== $identifier)) {
                        throw self::unreadable();
                    }
                    if (! self::RESOLVED[$status]) {
                        if ($amount > ProcessorMovementFacts::MAX_MINOR - $withheld) {
                            throw self::unreadable();
                        }
                        $withheld += $amount;
                        if ($withheld > $paymentAmount) {
                            throw self::unreadable();
                        }
                    }
                    $seen[$id] = true;
                    $last = $id;
                    $disputes[] = $record;
                }
                if (! $response['has_more']) {
                    return new StripeDisputeSnapshot($paymentId, $workspace->id, $identifier, $version,
                        $currency, $paymentAmount, $withheld, $disputes);
                }
                if ($last === null) {
                    throw self::unreadable();
                }
                $params['starting_after'] = $last;
            }
        } catch (ApiErrorException|\RuntimeException) {
            throw self::unreadable();
        }

        throw self::unreadable();
    }

    /** Apply a previously read snapshot once all other required provider reads have completed. */
    public function apply(Workspace $workspace, #[SensitiveParameter] StripeDisputeSnapshot $snapshot, bool $apply = false): void
    {
        if ($workspace->id !== $snapshot->workspaceId) {
            throw new DomainException('The Stripe dispute payment is unavailable.');
        }
        if (! $apply) {
            return;
        }
        DB::transaction(function () use ($workspace, $snapshot): void {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->whereKey($snapshot->paymentId)->where('provider', 'stripe')->tap(Locks::forUpdate())->first();
            if ($payment === null || $payment->provider !== 'stripe' || $payment->provider_payment_identifier !== $snapshot->providerIdentifier
                || $payment->lock_version !== $snapshot->version || $payment->amount !== $snapshot->paymentAmount || $payment->currency !== $snapshot->currency) {
                throw self::unreadable();
            }
            // Absence is not a resolved dispute; never infer a win from an empty list.
            if ($snapshot->disputes === []) {
                return;
            }
            $current = InvoicePaymentStatus::tryFrom((string) $payment->status);
            if ($snapshot->withheldMinor === 0) {
                if ($current === InvoicePaymentStatus::Disputed) {
                    $this->invoices->setPaymentStatus($payment, InvoicePaymentStatus::Succeeded->value, $workspace);
                }
            } else {
                $holds = match ($current) {
                    InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Disputed => true,
                    InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled,
                    InvoicePaymentStatus::Refunded, null => false,
                };
                if ($holds) {
                    $this->invoices->setPaymentDisputed($payment, $snapshot->withheldMinor, $workspace);
                }
            }
            if (in_array($current, [InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Disputed], true)) {
                // Accepting an unchanged current group must also invalidate an
                // older overlapping read. A lifecycle change already advanced
                // the revision; only the no-op path still matches this value.
                ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->whereKey($payment->id)
                    ->where('lock_version', $snapshot->version)->update([
                        'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
                    ]);
            }
        });
    }

    private static function reference(#[SensitiveParameter] mixed $value): ?string
    {
        $value = is_array($value) ? ($value['id'] ?? null) : $value;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_]{1,255}$/D', $value) !== 1) {
            throw self::unreadable();
        }

        return $value;
    }

    private static function unreadable(): StripeEventMustBeRetried
    {
        return new StripeEventMustBeRetried('Stripe disputes could not be read completely; retry the payment.');
    }
}
