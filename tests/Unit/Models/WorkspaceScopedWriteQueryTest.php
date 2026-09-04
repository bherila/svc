<?php

namespace Tests\Unit\Models;

use App\Exceptions\UnscopableWorkspaceWrite;
use App\Exceptions\WorkspaceOwnershipImmutable;
use App\Models\ClientExpense;
use App\Models\ClientTimeEntry;
use App\Models\WorkspaceInvoiceCounter;
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
