<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'legacy_migration_run_id', 'source_connection', 'source_identity_hash', 'source_table', 'source_key',
    'target_type', 'target_public_id', 'source_fingerprint', 'status', 'reason_code',
])]
#[Hidden(['id', 'legacy_migration_run_id', 'source_connection', 'source_identity_hash', 'source_key'])]
class LegacyMigrationItem extends Model
{
    /** @return BelongsTo<LegacyMigrationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationRun::class, 'legacy_migration_run_id');
    }
}
