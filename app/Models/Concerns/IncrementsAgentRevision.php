<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Keeps opaque Agent API revisions current for every Eloquent model update. */
trait IncrementsAgentRevision
{
    protected static function bootIncrementsAgentRevision(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('lock_version') === null) {
                $model->setAttribute('lock_version', 1);
            }
        });
        static::updating(function (Model $model): void {
            if ($model->isDirty() && ! $model->isDirty('lock_version')) {
                $model->setAttribute('lock_version', ((int) $model->getOriginal('lock_version')) + 1);
            }
        });
    }

    public function advanceAgentRevision(): void
    {
        static::query()->whereKey($this->getKey())->update([
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => now(),
        ]);
        $this->refresh();
    }
}
