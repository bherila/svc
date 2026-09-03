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
 * @property string $public_id
 * @property string $status
 * @property list<string> $recipients
 * @property list<string>|null $bcc
 * @property string $subject
 * @property string|null $body
 * @property string|null $provider_message_reference
 * @property string|null $provider_status
 * @property string|null $error_summary
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $provider_status_at
 * @property-read Workspace|null $workspace
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'recipients', 'bcc', 'subject', 'body', 'status',
    'provider_message_reference', 'provider_status', 'provider_status_at',
    'error_summary', 'queued_at', 'sent_at', 'failed_at',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'provider_message_reference', 'error_summary'])]
class ClientInvoiceEmailDelivery extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'bcc' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'provider_status_at' => 'datetime',
        ];
    }

    /**
     * The tenant this delivery belongs to.
     *
     * Declared here rather than left to `BelongsToWorkspace`, which only
     * exposes the id: the clock that stamps these rows takes the workspace
     * itself, because a workspace keeps its own calendar.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }
}
