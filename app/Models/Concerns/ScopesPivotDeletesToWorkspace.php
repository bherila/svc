<?php

namespace App\Models\Concerns;

use App\Exceptions\UnscopableWorkspaceWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one write path `BelongsToWorkspace` cannot reach, for a tenant-owned pivot.
 *
 * `detach()` on a relation declaring `using()` does not delete through a loaded
 * row. It synthesises a pivot carrying the two relationship keys and nothing
 * else, and `AsPivot::delete()` sees no primary key among them, so it builds its
 * own statement from those two keys instead of going through
 * `setKeysForSaveQuery()`. The delete is correct - a parent id is unique across
 * the installation, so it removes the row it means to - and it still says
 * nothing about a workspace, which is the whole of what this repository asks a
 * tenant-owned write to say. From review on #230.
 *
 * The workspace comes from the pivot's own attributes when it has them, and
 * otherwise from the parent the relation was reached through: `detach()` on
 * `$project->members()` has the project, and the project has a workspace.
 *
 * `WorkspaceMembership` never needs the parent: `workspace_id` is one of its two
 * relationship keys, so the synthesised pivot already carries it and the clause
 * this adds repeats what the key predicate says. Repeating it is the price of
 * not special-casing a model here, and it is cheaper than a branch no call site
 * reaches.
 *
 * A relation whose parent owns no workspace either - `$user->clientCompanies()`,
 * since `users` belongs to no workspace - can supply nothing, and is refused
 * rather than quietly falling back to the unscoped statement. Nothing in the
 * application detaches that way today; a caller that needs to can delete the
 * loaded pivot row, which goes through the save-query hook and is scoped
 * there.
 */
trait ScopesPivotDeletesToWorkspace
{
    /** @return Builder<static> */
    protected function getDeleteQuery()
    {
        return parent::getDeleteQuery()->where('workspace_id', $this->pivotWorkspaceKey());
    }

    private function pivotWorkspaceKey(): mixed
    {
        // Original first for the same reason the save query prefers it: what
        // the row is, not what this instance was told.
        $attributes = $this->getRawOriginal() + $this->getAttributes();

        if (array_key_exists('workspace_id', $attributes)) {
            return $attributes['workspace_id'];
        }

        $parent = $this->pivotParent;

        if ($parent instanceof Model && array_key_exists('workspace_id', $parent->getAttributes())) {
            return $parent->getAttributes()['workspace_id'];
        }

        throw new UnscopableWorkspaceWrite(sprintf(
            'A detach of %s was refused because neither the pivot nor the relation\'s parent carries a workspace_id, so the delete could not be scoped to a workspace. Detach from the side that owns the workspace, or delete the loaded pivot row.',
            static::class,
        ));
    }
}
