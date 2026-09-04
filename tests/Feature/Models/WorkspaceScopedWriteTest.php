<?php

namespace Tests\Feature\Models;

use App\Exceptions\UnscopableWorkspaceWrite;
use App\Models\ClientExpense;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceInvoiceCounter;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * Every write to a tenant-owned row names the workspace in its own statement.
 *
 * Eloquent keys a save by primary key alone, so a model read through a
 * perfectly workspace-scoped query still emits `update ... where id = ?`. Every
 * such write in this application is safe by the argument that the id came from
 * a scoped read - but that argument is about the caller, it has to be made
 * again for each new one, and the rule this repository states is about the
 * statement. `BelongsToWorkspace` adds the predicate; this test is what stops
 * the next tenant-owned model from being added without it.
 *
 * There is no exemption list on purpose. A table with a `workspace_id` column
 * is tenant-owned by this repository's definition, and a model that wants out
 * of the predicate wants a different table.
 */
final class WorkspaceScopedWriteTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    public function test_every_model_whose_table_has_a_workspace_uses_the_scoping_trait(): void
    {
        $unscoped = [];

        foreach ($this->tenantOwnedModels() as $class) {
            if (! in_array(BelongsToWorkspace::class, class_uses_recursive($class), true)) {
                $unscoped[] = sprintf('%s [%s]', class_basename($class), (new $class)->getTable());
            }
        }

        $this->assertSame([], $unscoped, sprintf(
            "These models own a workspace column but do not use %s, so their updates and deletes are keyed by id alone:\n\n%s",
            BelongsToWorkspace::class,
            implode("\n", $unscoped),
        ));
    }

    /**
     * The trait is the mechanism; this is the property it exists for, asserted
     * against the SQL each model would actually issue rather than against the
     * presence of a `use` statement.
     */
    public function test_every_such_model_writes_a_statement_naming_the_workspace(): void
    {
        $gaps = [];

        foreach ($this->tenantOwnedModels() as $class) {
            $query = $this->saveQueryFor($class, workspace: 4242);

            if (! str_contains($query->toSql(), 'workspace_id') || ! in_array(4242, $query->getBindings(), true)) {
                $gaps[] = sprintf('%s: %s', class_basename($class), $query->toSql());
            }
        }

        $this->assertSame([], $gaps, "A save on these models would not be scoped to a workspace:\n".implode("\n", $gaps));
    }

    /**
     * A workspace rewritten in memory must not become the predicate: that would
     * turn a save into a write aimed at whichever tenant the caller named.
     */
    public function test_the_predicate_is_the_stored_workspace_not_the_one_in_memory(): void
    {
        $model = new ClientExpense;
        $model->setRawAttributes(['id' => 7, 'workspace_id' => 4242], sync: true);
        $model->exists = true;
        $model->setAttribute('workspace_id', 9999);

        $query = $model->newModelQuery();
        $this->buildSaveQuery($model, $query);

        $this->assertContains(4242, $query->getBindings(), 'The stored workspace is what the row is in.');
        $this->assertNotContains(9999, $query->getBindings(), 'The in-memory workspace would aim the write at another tenant.');
    }

    public function test_a_forged_model_cannot_write_another_workspaces_row(): void
    {
        $mine = $this->syntheticWorkspace('scoped writer');
        $theirs = $this->syntheticWorkspace('bystander');
        $target = $this->recordSyntheticExpense($theirs, $this->syntheticCompany($theirs, 'bystander'));
        $control = $this->recordSyntheticExpense($mine, $this->syntheticCompany($mine, 'scoped writer'));

        // What a caller holding an unchecked id produces: a model that says it
        // belongs here, keyed at a row that does not.
        $this->assertTrue($this->forge($target->id, $mine->id)->save(), 'Eloquent reports a save whether or not a row matched.');
        $this->assertSame(
            'Synthetic travel expense',
            $target->fresh()?->description,
            'The other workspace\'s expense was rewritten through an id predicate.',
        );

        // The same forgery against a row this workspace does own, so the test
        // cannot be passing because the write never happens at all.
        $this->forge($control->id, $mine->id)->save();
        $this->assertSame('Rewritten by a forged model', $control->fresh()?->description);
    }

    public function test_a_model_hydrated_without_its_workspace_is_refused(): void
    {
        $workspace = $this->syntheticWorkspace('partial read');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'partial read'));

        $partial = ClientExpense::query()->select(['id', 'description'])->findOrFail($expense->id);
        $partial->setAttribute('description', 'Rewritten from a partial read');

        $this->expectException(UnscopableWorkspaceWrite::class);

        $partial->save();
    }

    public function test_the_delete_paths_and_restore_carry_the_workspace_too(): void
    {
        $workspace = $this->syntheticWorkspace('deletes');
        $company = $this->syntheticCompany($workspace, 'deletes');
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $forced = $this->recordSyntheticExpense($workspace, $company);

        $statements = $this->capture(function () use ($expense, $forced): void {
            $expense->delete();
            $expense->restore();
            $forced->forceDelete();
        });

        $this->assertCount(3, $statements, 'A soft delete, a restore and a force delete, or the paths changed.');

        foreach ($statements as $sql) {
            $this->assertStringContainsString('workspace_id', $sql, $sql);
        }
    }

    /**
     * A table keyed by the workspace is already scoped by the key predicate,
     * and the trait leaves it alone rather than saying the same thing twice.
     */
    public function test_a_table_keyed_by_the_workspace_is_not_scoped_twice(): void
    {
        $workspace = $this->syntheticWorkspace('counter');
        $counter = WorkspaceInvoiceCounter::query()->create(['workspace_id' => $workspace->id, 'next_number' => 1]);

        $statements = $this->capture(static function () use ($counter): void {
            $counter->forceFill(['next_number' => 2])->save();
        });

        $this->assertCount(1, $statements);
        $this->assertSame(1, substr_count($statements[0], 'workspace_id'), $statements[0]);
        $this->assertSame(2, WorkspaceInvoiceCounter::query()->findOrFail($workspace->id)->next_number);
    }

    /** A model that claims a workspace it is not keyed in. */
    private function forge(int $id, int $workspaceId): ClientExpense
    {
        $model = new ClientExpense;
        $model->setRawAttributes(['id' => $id, 'workspace_id' => $workspaceId], sync: true);
        $model->exists = true;

        return $model->forceFill(['description' => 'Rewritten by a forged model']);
    }

    /**
     * The statements a piece of work issues against `client_expenses` or
     * `workspace_invoice_counters`, so a test can read the SQL rather than
     * infer it from the outcome.
     *
     * @return list<string>
     */
    private function capture(Closure $work): array
    {
        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            if (str_contains($query->sql, 'client_expenses') || str_contains($query->sql, 'workspace_invoice_counters')) {
                $statements[] = $query->sql;
            }
        });

        $work();

        return $statements;
    }

    /**
     * The query a save on this model would be keyed by, without issuing it.
     *
     * @param  class-string<Model>  $class
     * @return Builder<Model>
     */
    private function saveQueryFor(string $class, int $workspace): Builder
    {
        $model = new $class;
        $model->setRawAttributes([$model->getKeyName() => 1, 'workspace_id' => $workspace], sync: true);
        $model->exists = true;

        $query = $model->newModelQuery();
        $this->buildSaveQuery($model, $query);

        return $query;
    }

    /**
     * `setKeysForSaveQuery()` is protected, which is right - it is the
     * framework's seam, not an API - so the test reaches it the way the model
     * itself would.
     *
     * @param  Builder<Model>  $query
     */
    private function buildSaveQuery(Model $model, Builder $query): void
    {
        $build = function (Builder $query): void {
            $this->setKeysForSaveQuery($query);
        };

        $bound = Closure::bind($build, $model, $model::class);
        $bound($query);
    }

    /**
     * Every model whose table carries a `workspace_id`.
     *
     * Read off the schema rather than off a list, so a model added tomorrow is
     * in this test the day it gets its column.
     *
     * @return list<class-string<Model>>
     */
    private function tenantOwnedModels(): array
    {
        $models = [];

        foreach (glob(base_path('app/Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (Schema::hasTable($table) && Schema::hasColumn($table, 'workspace_id')) {
                $models[] = $class;
            }
        }

        sort($models);

        $this->assertNotSame([], $models, 'No tenant-owned model was found, so this test asserted nothing.');

        return $models;
    }
}
