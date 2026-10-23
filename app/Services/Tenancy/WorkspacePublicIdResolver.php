<?php

namespace App\Services\Tenancy;

use App\Models\Workspace;
use Illuminate\Support\Str;

/** The shared public-ID boundary for operator commands and configured adapters. */
final readonly class WorkspacePublicIdResolver
{
    public function find(string $publicId): ?Workspace
    {
        return Str::isUuid($publicId)
            ? Workspace::query()->where('public_id', $publicId)->first()
            : null;
    }
}
