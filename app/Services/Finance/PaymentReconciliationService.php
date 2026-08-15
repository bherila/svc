<?php

namespace App\Services\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\MoneyService;
use App\Services\WorkspaceAuthorization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PaymentReconciliationService
{
    public function __construct(private readonly WorkspaceAuthorization $workspaceAuthorization) {}

    /**
     * Create or update one allocation identified by payment, external system,
     * and external transaction UUID.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(
        Workspace $workspace,
        ClientInvoicePayment $payment,
        User $creator,
        array $attributes,
    ): PaymentReconciliation {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $payment);
        $this->assertCreatorBelongsToWorkspace($workspace, $creator);

        $systemSlug = $this->systemSlug($attributes['external_system_slug'] ?? null);
        $transactionUuid = $this->transactionUuid($attributes['external_transaction_uuid'] ?? null);
        $allocatedAmount = MoneyService::nonNegativeInteger($attributes['allocated_amount'] ?? null, 'allocated_amount');
        if ($allocatedAmount === 0) {
            throw new InvalidArgumentException('allocated_amount must be greater than zero.');
        }
        $currency = MoneyService::currency($attributes['currency'] ?? null);
        $reconciledOn = $this->reconciliationDate($attributes['reconciled_on'] ?? null);
        $isActive = $this->booleanAttribute($attributes['is_active'] ?? true, 'is_active');

        return DB::transaction(function () use (
            $workspace,
            $payment,
            $creator,
            $systemSlug,
            $transactionUuid,
            $allocatedAmount,
            $currency,
            $reconciledOn,
            $isActive,
        ): PaymentReconciliation {
            $lockedPayment = ClientInvoicePayment::query()
                ->whereKey($payment->id)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status !== 'succeeded') {
                throw new DomainException('Only successful payments can be reconciled.');
            }
            if ($lockedPayment->currency !== $currency) {
                throw new DomainException('Reconciliation currency must match the payment currency.');
            }

            $existing = PaymentReconciliation::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_invoice_payment_id', $lockedPayment->id)
                ->where('external_system_slug', $systemSlug)
                ->where('external_transaction_uuid', $transactionUuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->workspaceAuthorization->assertOwnedBy($workspace, $existing);
            }

            if ($isActive) {
                $activeAllocated = (int) PaymentReconciliation::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('client_invoice_payment_id', $lockedPayment->id)
                    ->where('is_active', true)
                    ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                    ->lockForUpdate()
                    ->sum('allocated_amount');
                $netAmount = max(0, (int) $lockedPayment->amount - (int) $lockedPayment->refunded_amount);

                if ($activeAllocated > $netAmount || $allocatedAmount > $netAmount - $activeAllocated) {
                    throw new DomainException('Active reconciliations cannot exceed the payment amount net of refunds.');
                }
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'external_system_slug' => $systemSlug,
                    'external_transaction_uuid' => $transactionUuid,
                    'allocated_amount' => $allocatedAmount,
                    'currency' => $currency,
                    'reconciled_on' => $reconciledOn,
                    'is_active' => $isActive,
                ])->save();

                return $existing->fresh();
            }

            return PaymentReconciliation::query()->create([
                'workspace_id' => $workspace->id,
                'client_invoice_payment_id' => $lockedPayment->id,
                'external_system_slug' => $systemSlug,
                'external_transaction_uuid' => $transactionUuid,
                'allocated_amount' => $allocatedAmount,
                'currency' => $currency,
                'reconciled_on' => $reconciledOn,
                'created_by_user_id' => $creator->id,
                'is_active' => $isActive,
            ]);
        });
    }

    public function assertTenant(Workspace $workspace, PaymentReconciliation $reconciliation): void
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $reconciliation);
    }

    private function assertCreatorBelongsToWorkspace(Workspace $workspace, User $creator): void
    {
        if (! $workspace->memberships()->where('user_id', $creator->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$creator->getKey()]);
        }
    }

    private function systemSlug(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('external_system_slug is required.');
        }

        $slug = strtolower(trim($value));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80) {
            throw new InvalidArgumentException('external_system_slug must be a lowercase slug of at most 80 characters.');
        }

        return $slug;
    }

    private function transactionUuid(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new InvalidArgumentException('external_transaction_uuid must be a valid UUID.');
        }

        return strtolower($value);
    }

    private function reconciliationDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('reconciled_on must be a YYYY-MM-DD date.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            if ($date->toDateString() !== $value) {
                throw new InvalidArgumentException('reconciled_on must be a valid YYYY-MM-DD date.');
            }

            return $date->toDateString();
        } catch (\Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }

            throw new InvalidArgumentException('reconciled_on must be a valid YYYY-MM-DD date.', previous: $exception);
        }
    }

    private function booleanAttribute(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new InvalidArgumentException("{$name} must be a boolean.");
    }
}
