<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'legacy_migration_item_id',
    'workspace_id',
    'client_attachment_id',
    'source_path_hash',
    'source_sha256',
    'source_bytes',
    'destination_object_key_hash',
    'copied_at',
])]
#[Hidden([
    'id',
    'legacy_migration_item_id',
    'workspace_id',
    'client_attachment_id',
    'source_path_hash',
    'source_sha256',
    'destination_object_key_hash',
])]
class LegacyAttachmentCopy extends Model
{
    protected function casts(): array
    {
        return [
            'source_bytes' => 'integer',
            'copied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<LegacyMigrationItem, $this> */
    public function migrationItem(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationItem::class, 'legacy_migration_item_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientAttachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ClientAttachment::class, 'client_attachment_id');
    }
}
