<?php

namespace App\Support\AgentApi;

use Illuminate\Database\Eloquent\Model;

final class AgentApiVersion
{
    public static function for(Model $model): string
    {
        $id = (string) $model->getKey();
        $version = (string) ($model->getAttribute('lock_version') ?? 1);
        $type = str_replace('App\\Models\\', '', $model::class);

        return hash_hmac('sha256', "{$type}:{$id}:{$version}", (string) config('app.key'));
    }
}
