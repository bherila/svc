<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable signed processor balance entry, bound to an owned payment.
 * Unknown references may be filled; conflicting reports hold the original row.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_payment_id
 * @property string $provider
 * @property string $account_key
 * @property string $provider_entry_identifier
 * @property string|null $source_identifier
 * @property string|null $provider_payout_identifier
 * @property ProcessorMovementKind $kind
 * @property int $principal_minor
 * @property int $fee_minor
 * @property int $net_minor
 * @property string $currency
 * @property CarbonImmutable $provider_occurred_at
 * @property CarbonImmutable $occurred_on
 * @property string $occurred_timezone
 * @property string $date_basis
 * @property CarbonImmutable|null $available_on
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property int $lock_version
 */
#[Fillable([])]
#[Hidden(['id', 'workspace_id', 'client_invoice_payment_id', 'account_key', 'provider_entry_identifier', 'source_identifier', 'provider_payout_identifier', 'lock_version'])]
class ProcessorMovement extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(static function (self $movement): void {
            $mutable = ['source_identifier', 'provider_payout_identifier', 'available_on', 'needs_review', 'review_reason', 'lock_version', 'updated_at', 'workspace_id'];
            foreach (array_keys($movement->getDirty()) as $column) {
                if (! in_array($column, $mutable, true)) {
                    throw new LogicException('A recorded processor movement cannot replace its original facts.');
                }
            }
            foreach (['source_identifier', 'provider_payout_identifier', 'available_on'] as $column) {
                if ($movement->isDirty($column) && $movement->getRawOriginal($column) !== null) {
                    throw new LogicException('Known processor movement references cannot be replaced.');
                }
            }
            if ($movement->getRawOriginal('needs_review') && ! $movement->needs_review) {
                throw new LogicException('A held processor movement requires explicit review.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'client_invoice_payment_id' => 'integer',
            'kind' => ProcessorMovementKind::class,
            'principal_minor' => 'integer',
            'fee_minor' => 'integer',
            'net_minor' => 'integer',
            'provider_occurred_at' => 'immutable_datetime',
            'occurred_on' => DateOnly::class,
            'available_on' => 'immutable_datetime',
            'needs_review' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientInvoicePayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ClientInvoicePayment::class, 'client_invoice_payment_id');
    }
}
