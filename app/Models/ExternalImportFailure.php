<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'external_import_run_id', 'external_import_item_id', 'source_connection',
    'source_table', 'source_key_hash', 'reason_code', 'redacted_context',
    'failure_fingerprint',
])]
class ExternalImportFailure extends Model implements WorkspaceOwned
{
    public function workspaceId(): ?int
    {
        return $this->run?->workspaceId();
    }

    protected function casts(): array
    {
        return ['redacted_context' => 'array'];
    }

    /** @return BelongsTo<ExternalImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ExternalImportRun::class, 'external_import_run_id');
    }

    /** @return BelongsTo<ExternalImportItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ExternalImportItem::class, 'external_import_item_id');
    }
}
