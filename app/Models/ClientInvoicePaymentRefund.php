<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Services\Billing\PaymentRefundLedger;
use App\Support\Billing\PaymentRefundKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One refund of a payment recorded by hand, or the reversal of one (#472).
 *
 * Immutable: a row is written once, by {@see PaymentRefundLedger} under the
 * payment's lock, and never updated or deleted. A refund recorded in error is
 * answered by a reversal naming it, so the history says both things happened.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_payment_id
 * @property PaymentRefundKind $kind
 * @property int $amount
 * @property string $reason
 * @property int|null $reverses_refund_id
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'client_invoice_payment_id', 'kind', 'amount', 'reason', 'reverses_refund_id', 'recorded_by_user_id'])]
#[Hidden(['id', 'workspace_id', 'client_invoice_payment_id', 'reverses_refund_id', 'recorded_by_user_id'])]
class ClientInvoicePaymentRefund extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('A recorded refund is never changed; record a reversal instead.'));
        static::deleting(static fn (): never => throw new LogicException('A recorded refund is never deleted; record a reversal instead.'));
    }

    protected function casts(): array
    {
        return [
            'kind' => PaymentRefundKind::class,
            'amount' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
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
