<?php

namespace App\Services\Files;

use Illuminate\Database\Eloquent\Model;

/** Facts resolved once for an authorized, bounded parent page. */
final readonly class AttachmentSharingView
{
    public function __construct(public AttachmentParentReference $reference, public Model $parent,
        public bool $parentVisibleToClient, public bool $canPublish) {}
}
