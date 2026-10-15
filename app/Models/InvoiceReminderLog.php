<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\EmailAddressList;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Billing\Reminders\PaymentReminderOrigin;
use App\Support\Billing\Reminders\PaymentReminderPayLink;
use App\Support\Billing\Reminders\PaymentReminderStatus;
use App\Support\Billing\Reminders\PaymentReminderStep;
use App\Support\Billing\Reminders\PaymentReminderTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment reminder about an invoice (#438): who or what asked for it,
 * which cadence step it was, who it went to, the pay link it carried and what
 * became of it. Written before anything is sent, so it is also the durable
 * claim that stops a step being sent twice.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property PaymentReminderOrigin $origin
 * @property PaymentReminderStep|null $cadence_step
 * @property string $channel
 * @property PaymentReminderPayLink $pay_link
 * @property string|null $square_invoice_number
 * @property list<string> $recipients
 * @property string $subject
 * @property string $currency
 * @property int $amount_due
 * @property CarbonImmutable|null $due_on
 * @property PaymentReminderStatus $status
 * @property int $attempt_number
 * @property PaymentReminderTrigger $triggered_by
 * @property int|null $triggered_by_user_id
 * @property string|null $oauth_client_id
 * @property string|null $provider_message_reference
 * @property string|null $error_summary
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property-read User|null $triggeredByUser
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'origin', 'cadence_step', 'channel', 'pay_link', 'square_invoice_number',
    'recipients', 'subject', 'currency', 'amount_due', 'due_on', 'status', 'attempt_number', 'triggered_by',
    'triggered_by_user_id', 'oauth_client_id', 'provider_message_reference', 'error_summary', 'queued_at',
    'claimed_at', 'sent_at', 'failed_at', 'next_attempt_at',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'triggered_by_user_id', 'oauth_client_id', 'provider_message_reference'])]
class InvoiceReminderLog extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    public const string CHANNEL_EMAIL = 'email';

    protected function casts(): array
    {
        return [
            'origin' => PaymentReminderOrigin::class,
            'cadence_step' => PaymentReminderStep::class,
            'pay_link' => PaymentReminderPayLink::class,
            'recipients' => EmailAddressList::class,
            'amount_due' => 'integer',
            'due_on' => DateOnly::class,
            'status' => PaymentReminderStatus::class,
            'attempt_number' => 'integer',
            'triggered_by' => PaymentReminderTrigger::class,
            'queued_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
