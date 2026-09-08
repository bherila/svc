<?php

namespace App\Models\Concerns;

use App\Contracts\WorkspaceOwned;
use App\Support\Tenancy\TenantRouteBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (! is_string($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::uuid());
            }
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($this instanceof WorkspaceOwned) {
            return app(TenantRouteBinding::class)->resolve($this, $value, $field);
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        if ($this instanceof WorkspaceOwned) {
            return app(TenantRouteBinding::class)->resolve($this, $value, $field, withTrashed: true);
        }

        return parent::resolveSoftDeletableRouteBinding($value, $field);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
