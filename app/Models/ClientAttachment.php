<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $record_type
 * @property string $record_public_id
 * @property string $object_key
 * @property string|null $staged_object_key
 * @property string $original_filename
 * @property string $media_type
 * @property int $bytes
 * @property string $sha256
 * @property int|null $uploader_id
 * @property string $lifecycle_state
 */
#[Fillable([
    'public_id',
    'workspace_id',
    'record_type',
    'record_public_id',
    'object_key',
    'staged_object_key',
    'original_filename',
    'media_type',
    'bytes',
    'sha256',
    'uploader_id',
    'lifecycle_state',
    'available_at',
    'deleted_at',
])]
class ClientAttachment extends Model
{
    use HasPublicId;

    public const STATE_STAGED = 'staged';

    public const STATE_AVAILABLE = 'available';

    public const STATE_DELETING = 'deleting';

    public const STATE_DELETED = 'deleted';

    public const STATE_CORRUPT = 'corrupt';

    protected static function booted(): void
    {
        static::updating(function (self $attachment): void {
            foreach ([
                'public_id',
                'workspace_id',
                'record_type',
                'record_public_id',
                'object_key',
                'original_filename',
                'media_type',
                'bytes',
                'sha256',
                'uploader_id',
            ] as $attribute) {
                if ($attachment->isDirty($attribute)) {
                    throw new LogicException("Attachment attribute [{$attribute}] is immutable.");
                }
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'original_filename' => 'encrypted',
            'bytes' => 'integer',
            'available_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
