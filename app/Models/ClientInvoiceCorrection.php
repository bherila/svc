<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property int $from_revision
 * @property int $to_revision
 * @property string $reason
 * @property string|null $previous_due_date
 * @property string|null $due_date
 * @property list<array<string, mixed>> $line_diffs
 */
#[Fillable(['workspace_id', 'client_invoice_id', 'from_revision', 'to_revision', 'reason', 'previous_due_date', 'due_date', 'line_diffs'])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id'])]
class ClientInvoiceCorrection extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['line_diffs' => 'array'];
    }
}
