<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentSharingGate;
use App\Services\Files\AttachmentUploadPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $lock_version
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $record_type
 * @property string $record_public_id
 * @property string $object_key
 * @property string|null $staged_object_key
 * @property string $original_filename
 * @property string $media_type
 * @property bool $is_visible_to_client
 * @property int $bytes
 * @property string $sha256
 * @property int|null $uploader_id
 * @property string $lifecycle_state
 * @property CarbonImmutable|null $available_at
 * @property CarbonImmutable|null $deleted_at
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
    'is_visible_to_client',
    'sha256',
    'uploader_id',
    'lifecycle_state',
    'available_at',
    'deleted_at',
])]
#[Hidden(['id', 'workspace_id', 'object_key', 'staged_object_key', 'uploader_id'])]
class ClientAttachment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    public const STATE_STAGED = 'staged';

    public const STATE_AVAILABLE = 'available';

    public const STATE_DELETING = 'deleting';

    public const STATE_DELETED = 'deleted';

    public const STATE_CORRUPT = 'corrupt';

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            $defaultVisibility = AttachmentUploadPolicy::defaultVisibility();
            if (! $defaultVisibility || ! array_key_exists('is_visible_to_client', $attachment->getAttributes())) {
                $attachment->is_visible_to_client = $defaultVisibility;
            }
            if (! $attachment->is_visible_to_client && $defaultVisibility) {
                throw new LogicException('The private upload default has not been enabled.');
            }
            if (! in_array($attachment->record_type, AttachmentRecordResolver::uploadableTypes(), true)) {
                if (! in_array($attachment->record_type, AttachmentRecordResolver::collaborationUploadTypes(), true)) {
                    throw new LogicException('This attachment parent does not support uploads.');
                }
                AttachmentSharingGate::assertEnabled();
            }
        });
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
                'is_visible_to_client',
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
            'is_visible_to_client' => 'boolean',
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
