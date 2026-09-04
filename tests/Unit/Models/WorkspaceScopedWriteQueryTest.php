<?php

namespace Tests\Unit\Models;

use App\Exceptions\UnscopableWorkspaceWrite;
use App\Exceptions\WorkspaceOwnershipImmutable;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Models\WorkspaceInvoiceCounter;
use App\Models\WorkspaceMembership;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * The predicate `BelongsToWorkspace` builds, without a database.
 *
 * `WorkspaceScopedWriteTest` proves the behaviour that matters: another
 * workspace's row survives a forged save, every model with the column uses the
 * trait, and the statements the boundary issues name the workspace. What it
 * cannot do is discriminate the clause itself, because it needs a database and
 * the diff-scoped mutation gate runs the Unit suite alone - `infection.diff.json5`
 * says so, to keep the PR lane fast. A trait reachable only from a feature test
 * is reported as zero mutants, which the workflow itself calls no evidence that
 * anything discriminated a behavioural change.
 *
 * So the discrimination lives here, on the same reasoning
 * `ConcurrencyLockRegistryTest` was written on: `setKeysForSaveQuery()` is a
 * pure function from a model's attributes to a query, and a query knows its own
 * SQL without ever being run.
 */
final class WorkspaceScopedWriteQueryTest extends TestCase
{
    public function test_the_statement_carries_the_key_and_the_workspace(): void
    {
        $query = $this->saveQueryFor(ClientExpense::class, ['id' => 7, 'workspace_id' => 4242]);

        // Both halves, because either one alone is a different bug: without the
        // key this updates every row in the workspace, and without the
        // workspace it is the id-only statement this override exists to end.
        $this->assertStringContainsString('"id" = ?', str_replace('`', '"', $query->toSql()));
        $this->assertStringContainsString('"workspace_id" = ?', str_replace('`', '"', $query->toSql()));
        $this->assertSame([7, 4242], $query->getBindings());
    }

    /**
     * The workspace-keyed table says it once, through the key clause the parent
     * builds. Twice would be noise; not at all would be unscoped.
     */
    public function test_a_table_keyed_by_the_workspace_names_it_exactly_once(): void
    {
        $query = $this->saveQueryFor(WorkspaceInvoiceCounter::class, ['workspace_id' => 4242]);

        $this->assertSame(1, substr_count($query->toSql(), 'workspace_id'), $query->toSql());
        $this->assertSame([4242], $query->getBindings());
    }

    /** A model that declares its ownership fixed refuses the move (#229). */
    public function test_a_row_whose_ownership_is_fixed_cannot_be_saved_elsewhere(): void
    {
        $model = $this->hydrate(ClientExpense::class, ['id' => 7, 'workspace_id' => 4242]);
        $model->setAttribute('workspace_id', 9999);

        $this->expectException(WorkspaceOwnershipImmutable::class);
        $this->expectExceptionMessage(ClientExpense::class);

        $this->buildSaveQuery($model);
    }

    /**
     * A model that has not declared it still moves, and the statement is still
     * predicated on the workspace the row is in rather than the one it is being
     * given. Both halves are load-bearing: `client_stripe_events` is inserted
     * before its tenant is known and stamped afterwards, and the legacy-row
     * fixtures move a row on purpose to prove the application refuses it.
     */
    public function test_a_row_whose_ownership_is_not_fixed_still_moves(): void
    {
        $model = $this->hydrate(ClientTimeEntry::class, ['id' => 7, 'workspace_id' => 4242]);
        $model->setAttribute('workspace_id', 9999);

        $this->assertSame([7, 4242], $this->buildSaveQuery($model)->getBindings());
    }

    /**
     * The same workspace arriving in a different PHP type is the same
     * workspace. A driver that returns integers as strings is ordinary, and a
     * refusal there would be a save that can never succeed rather than a guard.
     */
    public function test_a_workspace_that_comes_back_as_a_string_is_the_same_workspace(): void
    {
        $stored = $this->hydrate(ClientExpense::class, ['id' => 7, 'workspace_id' => '4242']);
        $stored->setAttribute('workspace_id', 4242);

        $this->assertSame(['4242'], array_slice($this->buildSaveQuery($stored)->getBindings(), 1));

        // And the other way round, because the comparison has two sides and a
        // guard that normalised only one of them would refuse exactly half of
        // these saves.
        $assigned = $this->hydrate(ClientExpense::class, ['id' => 7, 'workspace_id' => 4242]);
        $assigned->setAttribute('workspace_id', '4242');

        $this->assertSame([4242], array_slice($this->buildSaveQuery($assigned)->getBindings(), 1));
    }

    public function test_a_model_that_never_loaded_its_workspace_is_refused(): void
    {
        $model = $this->hydrate(ClientExpense::class, ['id' => 7]);

        $this->expectException(UnscopableWorkspaceWrite::class);
        $this->expectExceptionMessage(ClientExpense::class);

        $this->buildSaveQuery($model);
    }

    /**
     * The gap between the two refusals, which a partial select opens.
     *
     * From review on #230. A row read without its workspace, then assigned
     * one, used to answer that assignment as the stored value: the
     * immutability check compared it against itself, passed, and predicated
     * the update on the tenant the row was being moved to - matching nothing
     * while `save()` reported success. A model whose ownership is fixed now
     * takes the original alone, so this is refused as unscopable.
     */
    public function test_an_immutable_model_cannot_supply_its_own_workspace_after_a_partial_read(): void
    {
        $model = $this->hydrate(ClientExpense::class, ['id' => 7]);
        $model->setAttribute('workspace_id', 9999);

        $this->expectException(UnscopableWorkspaceWrite::class);

        $this->buildSaveQuery($model);
    }

    /**
     * A model whose ownership is not fixed keeps the fallback, which is what
     * a hand-built instance needs - and what the forged-model case in
     * `WorkspaceScopedWriteTest` relies on to match no row.
     */
    public function test_a_mutable_model_still_falls_back_to_its_current_attributes(): void
    {
        $model = new ClientTimeEntry;
        $model->setRawAttributes([], sync: true);
        $model->exists = true;
        $model->forceFill(['id' => 7, 'workspace_id' => 4242]);

        $this->assertSame([7, 4242], $this->buildSaveQuery($model)->getBindings());
    }

    /**
     * A workspace stored as null is answered as null, not refused: the row it
     * came from is the row `workspace_id is null` matches. Only an unloaded
     * column is indistinguishable from a value, and that is the case above.
     */
    public function test_a_stored_null_workspace_is_a_value_rather_than_an_absence(): void
    {
        $query = $this->saveQueryFor(ClientExpense::class, ['id' => 7, 'workspace_id' => null]);

        $this->assertStringContainsString('workspace_id', $query->toSql());
        $this->assertSame([7], $query->getBindings(), 'A null is compiled as `is null`, so it binds nothing.');
    }

    /**
     * A `detach()` through a custom pivot never loads the row: it synthesises a
     * pivot holding the two relationship keys, which `AsPivot::delete()` turns
     * into a statement of its own without passing the save-query hook. The
     * workspace has to be added there separately, and it comes from the parent
     * the relation was reached through. From review on #230.
     */
    public function test_a_synthesised_pivot_delete_names_the_workspace(): void
    {
        $query = $this->pivotDeleteQuery(4242);

        $this->assertStringContainsString('workspace_id', $query->toSql(), $query->toSql());
        $this->assertSame([9, 5, 4242], $query->getBindings());
    }

    /**
     * `WorkspaceMembership` carries `workspace_id` as one of its two
     * relationship keys, so the synthesised pivot already has it and no parent
     * lookup is needed. Asserted rather than assumed: it is the reason there is
     * no special case for the workspace being its own pivot parent.
     */
    public function test_a_membership_pivot_scopes_by_the_key_it_already_carries(): void
    {
        $workspace = new Workspace;
        $workspace->setRawAttributes(['id' => 4242], sync: true);
        $workspace->exists = true;

        $pivot = WorkspaceMembership::fromRawAttributes(
            $workspace,
            ['workspace_id' => 4242, 'user_id' => 5],
            'workspace_memberships',
            exists: true,
        );
        $pivot->setPivotKeys('workspace_id', 'user_id');

        $build = function (): Builder {
            return $this->getDeleteQuery();
        };

        $this->assertSame([4242, 5, 4242], Closure::bind($build, $pivot, $pivot::class)()->getBindings());
    }

    /**
     * A pivot loaded straight from a query has no relation parent at all, so
     * the parent lookup must be a check rather than a dereference. From review
     * on #230: the first version read through it and would have raised a PHP
     * error here instead of the refusal.
     */
    public function test_a_pivot_loaded_without_a_relation_parent_is_refused(): void
    {
        $pivot = new ClientProjectMembership;
        $pivot->setRawAttributes(['client_project_id' => 9, 'user_id' => 5], sync: true);
        $pivot->exists = true;
        $pivot->setPivotKeys('client_project_id', 'user_id');

        $build = function (): Builder {
            return $this->getDeleteQuery();
        };

        $this->expectException(UnscopableWorkspaceWrite::class);

        Closure::bind($build, $pivot, $pivot::class)();
    }

    /**
     * And a parent that owns no workspace - `users` is not tenant-owned, so
     * `$user->clientCompanies()` is one - is refused rather than falling back to
     * the unscoped statement the override exists to replace.
     */
    public function test_a_synthesised_pivot_with_no_workspace_anywhere_is_refused(): void
    {
        $this->expectException(UnscopableWorkspaceWrite::class);
        $this->expectExceptionMessage(ClientProjectMembership::class);

        $this->pivotDeleteQuery(null)->toSql();
    }

    /**
     * The pivot `detach()` builds: the two relationship keys and no primary
     * key, with the parent carrying the workspace (or not, when null).
     *
     * @return Builder<Model>
     */
    private function pivotDeleteQuery(?int $parentWorkspaceId): Builder
    {
        $parent = new ClientProject;
        $parent->setRawAttributes(
            $parentWorkspaceId === null ? ['id' => 9] : ['id' => 9, 'workspace_id' => $parentWorkspaceId],
            sync: true,
        );
        $parent->exists = true;

        $pivot = ClientProjectMembership::fromRawAttributes(
            $parent,
            ['client_project_id' => 9, 'user_id' => 5],
            'client_project_memberships',
            exists: true,
        );
        $pivot->setPivotKeys('client_project_id', 'user_id');

        $build = function (): Builder {
            return $this->getDeleteQuery();
        };

        return Closure::bind($build, $pivot, $pivot::class)();
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $attributes
     * @return Builder<Model>
     */
    private function saveQueryFor(string $class, array $attributes): Builder
    {
        return $this->buildSaveQuery($this->hydrate($class, $attributes));
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $attributes
     */
    private function hydrate(string $class, array $attributes): Model
    {
        $model = new $class;
        $model->setRawAttributes($attributes, sync: true);
        $model->exists = true;

        return $model;
    }

    /**
     * `setKeysForSaveQuery()` is protected, which is right - it is the
     * framework's seam, not an API - so the test reaches it the way the model
     * itself would.
     *
     * @return Builder<Model>
     */
    private function buildSaveQuery(Model $model): Builder
    {
        $build = function (Builder $query): Builder {
            return $this->setKeysForSaveQuery($query);
        };

        $bound = Closure::bind($build, $model, $model::class);

        // The builder the method *returns*, not the one it was handed. They are
        // the same object today, and `save()` writes through the return value,
        // so a version that stopped returning it would break every write while
        // a test reading the argument stayed green.
        return $bound($model->newModelQuery());
    }
}
