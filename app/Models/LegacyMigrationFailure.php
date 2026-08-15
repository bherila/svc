<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'legacy_migration_run_id', 'legacy_migration_item_id', 'source_connection',
    'source_table', 'source_key_hash', 'reason_code', 'redacted_context',
    'failure_fingerprint',
])]
class LegacyMigrationFailure extends Model
{
    protected function casts(): array
    {
        return ['redacted_context' => 'array'];
    }

    /** @return BelongsTo<LegacyMigrationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationRun::class, 'legacy_migration_run_id');
    }

    /** @return BelongsTo<LegacyMigrationItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationItem::class, 'legacy_migration_item_id');
    }
}
