<?php

namespace App\Support\AgentApi;

use App\Models\ClientTimeEntry;
use Illuminate\Database\Eloquent\Model;

final class AgentApiVersion
{
    public static function for(Model $model): string
    {
        $id = (string) $model->getKey();
        $version = (string) ($model->getAttribute('lock_version') ?? 1);
        $type = str_replace('App\\Models\\', '', $model::class);

        if ($model instanceof ClientTimeEntry) {
            // Allocating time intentionally preserves its row revision. Bind
            // the public token to the allocation fields the reader sees too.
            // Page and batch boundaries eager-load these scoped relationships.
            $allocation = TimeEntryAllocation::forVersion($model);
            $version .= ":{$allocation->invoiceId}:{$allocation->state}";
        }

        return hash_hmac('sha256', "{$type}:{$id}:{$version}", (string) config('app.key'));
    }

    public static function matches(Model $model, string $version): bool
    {
        return hash_equals(self::for($model), $version);
    }
}
