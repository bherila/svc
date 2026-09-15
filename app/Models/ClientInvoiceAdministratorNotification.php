<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property int|null $recipient_user_id
 * @property int $invoice_revision
 * @property string|null $recipient
 * @property string $subject
 * @property string $open_url
 * @property string $client_name
 * @property string $invoice_number
 * @property string $currency
 * @property int $total_amount
 * @property string|null $pdf_filename
 * @property string|null $pdf_content_base64
 * @property string $status
 * @property int $attempt_count
 * @property list<array<string, mixed>>|null $attempt_history
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $provider_message_reference
 * @property string|null $error_summary
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'recipient_user_id', 'invoice_revision', 'recipient',
    'subject', 'open_url', 'client_name', 'invoice_number', 'currency', 'total_amount',
    'pdf_filename', 'pdf_content_base64', 'status', 'attempt_count', 'attempt_history',
    'next_attempt_at', 'claimed_at', 'sent_at', 'failed_at', 'provider_message_reference',
    'error_summary',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'recipient_user_id', 'pdf_content_base64', 'provider_message_reference'])]
class ClientInvoiceAdministratorNotification extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'attempt_history' => 'array',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
