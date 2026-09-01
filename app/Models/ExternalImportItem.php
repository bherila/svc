<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'external_import_run_id', 'source_connection', 'source_identity_hash', 'source_table', 'source_key',
    'target_type', 'target_public_id', 'source_fingerprint', 'status', 'reason_code',
])]
#[Hidden(['id', 'external_import_run_id', 'source_connection', 'source_identity_hash', 'source_key'])]
class ExternalImportItem extends Model implements WorkspaceOwned
{
    public function workspaceId(): ?int
    {
        return $this->run?->workspaceId();
    }

    /** @return BelongsTo<ExternalImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ExternalImportRun::class, 'external_import_run_id');
    }

    /** @return HasOne<ExternalImportAttachmentCopy, $this> */
    public function copy(): HasOne
    {
        return $this->hasOne(ExternalImportAttachmentCopy::class, 'external_import_item_id');
    }
}
