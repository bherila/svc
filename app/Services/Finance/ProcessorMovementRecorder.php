<?php

namespace App\Services\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Support\Concurrency\Locks;
use App\Support\Finance\ProcessorMovementFacts;
use App\Support\Finance\ProcessorMovementRecordOutcome;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/** No provider IO: resolve the payment under its tenant lock, then record facts. */
final class ProcessorMovementRecorder
{
    public function __construct(private readonly WorkspaceClock $clock) {}

    public function record(Workspace $workspace, int $paymentId, ProcessorMovementFacts $facts, #[SensitiveParameter] string $accountKey, bool $apply = false): ProcessorMovementRecordOutcome
    {
        if ($accountKey === '' || strlen($accountKey) > 255 || preg_match('/[\x00-\x20\x7f]/', $accountKey) === 1) {
            throw new DomainException('The processor movement account namespace is unavailable.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $facts->identifier) === 1) {
            throw new DomainException('The processor movement entry identity is unavailable.');
        }

        self::assertDateRange($facts->occurredAt);
        if ($facts->availableOn !== null) {
            self::assertDateRange($facts->availableOn);
        }

        return DB::transaction(function () use ($workspace, $paymentId, $facts, $accountKey, $apply): ProcessorMovementRecordOutcome {
            BillingMaintenance::share($workspace->id);
            $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
                ->whereKey($paymentId)->where('provider', $facts->provider)
                ->tap(Locks::forUpdate())->first();
            if ($payment === null || $payment->provider !== $facts->provider
                || ! in_array($payment->provider_payment_identifier, $facts->paymentIdentifiers, true)) {
                throw new DomainException('The processor movement payment is unavailable.');
            }
            $query = ProcessorMovement::query()->where('workspace_id', $workspace->id)->where('provider', $facts->provider)
                ->where('account_key', $accountKey)->where('provider_entry_identifier', $facts->identifier);
            $stored = (clone $query)->tap(Locks::forUpdate())->first();
            if ($stored === null) {
                $timezone = Workspace::query()->whereKey($workspace->id)->value('timezone');
                if (! is_string($timezone) || strlen($timezone) > 64
                    || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                    throw new DomainException('The processor movement workspace timezone is unavailable.');
                }
                $localOccurrence = $facts->occurredAt->setTimezone($timezone);
                self::assertDateRange($localOccurrence);
                if (! $apply) {
                    return ProcessorMovementRecordOutcome::Created;
                }
                $now = $this->clock->now()->utc()->format('Y-m-d H:i:s.u');
                $inserted = DB::table('processor_movements')->insertOrIgnore([
                    'public_id' => (string) Str::uuid(), 'workspace_id' => $workspace->id,
                    'client_invoice_payment_id' => $payment->id, 'provider' => $facts->provider,
                    'account_key' => $accountKey, 'provider_entry_identifier' => $facts->identifier,
                    ...$this->immutableColumns($facts), ...$this->optionalColumns($facts),
                    'occurred_on' => $localOccurrence->toDateString(),
                    'occurred_timezone' => $timezone, 'needs_review' => false, 'review_reason' => null,
                    'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($inserted === 1) {
                    return ProcessorMovementRecordOutcome::Created;
                }
                // A unique-key winner is read as current state, even when an
                // outer transaction has already established a consistent snapshot.
                $stored = (clone $query)->tap(Locks::forUpdate())->first();
                if ($stored === null) {
                    throw new LogicException('The processor movement insert could not be confirmed.');
                }
            }

            $immutable = $this->immutableColumns($facts);
            $conflict = $stored->client_invoice_payment_id !== $payment->id;
            foreach ($immutable as $column => $value) {
                $old = $stored->getRawOriginal($column);
                $old = in_array($column, ['principal_minor', 'fee_minor', 'net_minor'], true) ? (int) $old : $old;
                $conflict = $conflict || $old !== $value;
            }
            $enrichment = [];
            foreach ($this->optionalColumns($facts) as $column => $value) {
                $old = $stored->getRawOriginal($column);
                if ($value === null) {
                    continue;
                }
                if ($old === null) {
                    $enrichment[$column] = $value;
                } elseif ($old !== $value) {
                    $conflict = true;
                }
            }
            if ($conflict || $stored->needs_review) {
                if ($apply && ! $stored->needs_review) {
                    $stored->forceFill(['needs_review' => true, 'review_reason' => 'immutable_facts_changed'])->save();
                }

                return ProcessorMovementRecordOutcome::Conflict;
            }
            if ($enrichment !== []) {
                if ($apply) {
                    $stored->forceFill($enrichment)->save();
                }

                return ProcessorMovementRecordOutcome::Enriched;
            }

            return ProcessorMovementRecordOutcome::Unchanged;
        }, 3);
    }

    /** Match the supported MariaDB DATETIME/DATE years before even a preview. */
    private static function assertDateRange(CarbonImmutable $date): void
    {
        if ($date->year < 1000 || $date->year > 9999) {
            throw new DomainException('The processor movement date is outside the supported range.');
        }
    }

    /** @return array{kind: string, principal_minor: int, fee_minor: int, net_minor: int, currency: string, provider_occurred_at: string, date_basis: string} */
    private function immutableColumns(ProcessorMovementFacts $facts): array
    {
        return [
            'kind' => $facts->kind->value, 'principal_minor' => $facts->principalMinor,
            'fee_minor' => $facts->feeMinor, 'net_minor' => $facts->netMinor,
            'currency' => $facts->currency, 'provider_occurred_at' => $facts->occurredAt->format('Y-m-d H:i:s.u'),
            'date_basis' => $facts->dateBasis,
        ];
    }

    /** @return array{source_identifier: ?string, provider_payout_identifier: ?string, available_on: ?string} */
    private function optionalColumns(ProcessorMovementFacts $facts): array
    {
        return [
            'source_identifier' => $facts->sourceIdentifier,
            'provider_payout_identifier' => $facts->payoutIdentifier,
            'available_on' => $facts->availableOn?->format('Y-m-d H:i:s.u'),
        ];
    }
}
