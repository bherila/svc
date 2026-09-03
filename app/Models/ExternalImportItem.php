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
/**
 * Which destination row came from which source row, for the one-time onboarding
 * import.
 *
 * **Nothing writes this any more.** The external importer was retired once the
 * import it existed for had completed and its damage had been repaired, and this
 * ledger was deliberately kept when the code around it was deleted.
 *
 * It was kept because it is the only place the mapping exists. A destination row
 * carries no memory of where it came from, and cannot tell a superseded source
 * revision from a current one - which is precisely the information the broken
 * import discarded. When production turned out to be holding 49 invoices and 764
 * lines taken from source rows the predecessor had soft-deleted, the repair was
 * possible only because this table could still name them. Any future question of
 * that shape is answerable only while these rows survive, so no migration should
 * drop them and no cleanup should treat them as orphaned by the missing code.
 *
 * Read-only history, in other words, not a dormant feature. See
 * {@see ExternalImportRun} for the run that wrote a batch of these.
 */
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
