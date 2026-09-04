<?php

namespace App\Models\Concerns;

use App\Exceptions\UnscopableWorkspaceWrite;
use App\Exceptions\WorkspaceOwnershipImmutable;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToWorkspace
{
    public function workspaceId(): ?int
    {
        $workspaceId = $this->getAttribute('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }

    /**
     * Carry the workspace into every update and delete the row performs.
     *
     * Eloquent keys a save by primary key alone, so `save()` on a model read
     * through a workspace-scoped query still emits `where id = ?`. Each such
     * write in this application is defensible on its own - the id came from a
     * scoped read, often one holding `FOR UPDATE` in the same transaction - but
     * that is an argument about the caller, it has to be made again for every
     * caller added afterwards, and the rule this repository states is about the
     * statement: every tenant-owned write is workspace-scoped. Here that
     * becomes a property of the SQL rather than of the reasoning around it,
     * which is the difference between a guarantee and a paragraph.
     *
     * It lives on the trait rather than on the models because the behaviour it
     * corrects is the framework's, and therefore identical on all of them. One
     * model overriding this is a model that happened to be looked at; the trait
     * is every tenant-owned table, including the next one somebody adds.
     * `WorkspaceScopedWriteTest` holds that line by refusing a model with a
     * `workspace_id` column that does not use this trait.
     *
     * `setKeysForSaveQuery()` is the seam Laravel provides for exactly this,
     * and taking it keeps `save()`: casts, timestamps and model events all
     * still run, where hand-writing the update statements would have traded a
     * scoping guarantee for subtler ways to be wrong. It backs the update
     * `save()` issues, both delete paths, `restore()` and `increment()`, so
     * there is no further write path to remember.
     *
     * The predicate is the *stored* workspace, not the in-memory attribute, so
     * a model that merely claims a workspace - a hand-built instance keyed at a
     * row in a different one, which is what an unchecked id produces - matches
     * nothing, where the id-only predicate would have rewritten a stranger's
     * row.
     *
     * A model that declares {@see workspaceOwnershipIsImmutable()} refuses a
     * changed `workspace_id` outright, before the predicate is chosen - #229's
     * rule for expenses, kept where it was written rather than generalised.
     * Neither resolution is acceptable there: the stored value would match the
     * row and move it into another tenant, and the new value would match
     * nothing while `save()` still reported success.
     *
     * It is opt-in because ownership is *not* fixed everywhere, and this
     * application relies on that: `client_stripe_events` is inserted by the
     * webhook receiver before anything knows which tenant the event belongs to,
     * and the handler stamps the workspace once it has resolved one. Six tests
     * also move a row across workspaces on purpose, through
     * `WritesLegacyCrossTenantRows`, to prove the application refuses a row a
     * migrated database can still hold. A default of "immutable everywhere"
     * would have been a claim this codebase contradicts in both directions.
     *
     * The parent is called for its effect and `$query` is returned rather than
     * its result. Both are the same object - it configures the builder it was
     * handed and hands it back - but the analyser reads the parent's return as
     * `Builder<Model>` and loses the `static` this signature promises. Keeping
     * the parent call is still the point: the key predicate stays the
     * framework's to define, and this adds one clause to it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $stored = $this->storedWorkspaceKey();

        if ($this->workspaceOwnershipIsImmutable() && (int) $stored !== (int) $this->getAttribute('workspace_id')) {
            throw new WorkspaceOwnershipImmutable(sprintf(
                'A %s cannot be moved to another workspace: ownership is fixed when the row is created.',
                static::class,
            ));
        }

        parent::setKeysForSaveQuery($query);

        // A table keyed by the workspace itself is already scoped by the
        // clause the parent just added; a second identical one would only make
        // the statement harder to read. The shortcut is taken after the stored
        // key is resolved, never instead of it: skipping that resolution would
        // hand an unhydrated model to the parent, which writes `where
        // workspace_id is null` and matches nothing while `save()` reports
        // success - the exact failure this whole override refuses elsewhere.
        if ($this->getKeyName() === 'workspace_id') {
            return $query;
        }

        return $query->where('workspace_id', $stored);
    }

    /**
     * May a save move this row to another workspace?
     *
     * Off by default, so the trait states only what it can prove: the write is
     * scoped to the workspace the row is in. A model whose ownership really is
     * fixed at creation says so by overriding this, and gets the refusal.
     */
    protected function workspaceOwnershipIsImmutable(): bool
    {
        return false;
    }

    /**
     * The `workspace_id` as the database has it, for use as a write predicate.
     *
     * Original first, so an attribute rewritten in memory cannot redirect the
     * write; current attributes second, for a model that exists but was
     * hydrated outside a read - `forceFill()` on a fresh instance, say. A null
     * that is genuinely stored is answered as null and becomes `is null`, which
     * matches the row it came from; only a `workspace_id` that was never loaded
     * at all is refused, because that one is indistinguishable from a value and
     * would quietly match nothing.
     */
    private function storedWorkspaceKey(): mixed
    {
        // Union rather than a pair of lookups: for a duplicate key the
        // left-hand array wins, which is the precedence this needs.
        $attributes = $this->getRawOriginal() + $this->getAttributes();

        if (! array_key_exists('workspace_id', $attributes)) {
            throw new UnscopableWorkspaceWrite(sprintf(
                'A write to %s was refused because the model carries no workspace_id, so the statement could not be scoped to a workspace. Load the column before saving or deleting the row.',
                static::class,
            ));
        }

        return $attributes['workspace_id'];
    }
}
