<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkspace
{
    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = $this->createWithUniqueSlug($name);

            $workspace->memberships()->create([
                'user_id' => $owner->id,
                'role' => 'owner',
            ]);

            return $workspace;
        });
    }

    private function createWithUniqueSlug(string $name): Workspace
    {
        $base = Str::slug($name) ?: 'workspace';
        $suffix = 1;

        while (true) {
            $slug = $suffix === 1 ? $base : $base.'-'.$suffix;
            $suffix++;
            if (Workspace::query()->where('slug', $slug)->exists()) {
                continue;
            }
            try {
                // A savepoint keeps a uniqueness failure from poisoning the
                // surrounding reservation/receipt transaction. Advance the
                // candidate even if an older snapshot cannot see the winner.
                return DB::transaction(fn (): Workspace => Workspace::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $constraint = $exception->errorInfo[2] ?? null;
                if (! is_string($constraint) || (! str_contains($constraint, 'workspaces_slug_unique') && ! str_contains($constraint, 'workspaces.slug'))) {
                    throw $exception;
                }
            }
        }
    }
}
