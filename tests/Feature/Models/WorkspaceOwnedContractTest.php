<?php

namespace Tests\Feature\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\ExternalImportFailure;
use App\Models\ExternalImportItem;
use App\Models\ExternalImportRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class WorkspaceOwnedContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every workspace-owned model is marked, wherever it lives.
     *
     * Enumerated recursively, and the namespace derived from the path rather
     * than assumed to be the root. `File::files()` lists only direct children,
     * so a model at `app/Models/Billing/Foo.php` was skipped silently - and a
     * skipped model is not a gap in this test alone: the lookup rule keys off
     * the same contract, so the model would also have been invisible to static
     * analysis. A registry that quietly enumerates less than it claims is the
     * failure this epic keeps finding, so it should not be reintroduced by the
     * test written to prevent it.
     */
    public function test_every_model_with_a_workspace_column_carries_the_ownership_contract(): void
    {
        $unmarked = [];
        $scanned = 0;

        foreach (File::allFiles(app_path('Models')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Models\\'.$relative;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            /** @var Model $model */
            $model = $reflection->newInstance();

            $scanned++;

            if (Schema::hasColumn($model->getTable(), 'workspace_id')
                && ! $reflection->implementsInterface(WorkspaceOwned::class)) {
                $unmarked[] = $class;
            }
        }

        $this->assertSame([], $unmarked, 'Workspace-owned models missing the WorkspaceOwned contract: '.implode(', ', $unmarked));

        // A floor, because the assertion above passes just as happily when the
        // scan finds nothing at all - which is precisely how the non-recursive
        // version hid a whole directory. The number is a floor rather than an
        // equality so adding a model does not fail an unrelated branch.
        $this->assertGreaterThanOrEqual(30, $scanned, 'The model scan found implausibly few models to check.');
    }

    /** The scan reaches a model in a subdirectory, not only the root. */
    public function test_the_model_scan_descends_into_subdirectories(): void
    {
        $nested = app_path('Models/ContractScanFixture');
        File::ensureDirectoryExists($nested);
        File::put($nested.'/NestedScanProbe.php', <<<'PHP'
            <?php

            namespace App\Models\ContractScanFixture;

            use Illuminate\Database\Eloquent\Model;

            /** A probe for the recursive scan; carries no table of its own. */
            final class NestedScanProbe extends Model {}
            PHP);

        try {
            $found = collect(File::allFiles(app_path('Models')))
                ->map(fn ($file) => 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
                ->contains('App\\Models\\ContractScanFixture\\NestedScanProbe');

            $this->assertTrue($found, 'A model below app/Models must be enumerated and namespaced from its path.');
        } finally {
            File::deleteDirectory($nested);
        }
    }

    public function test_import_ledger_children_expose_their_run_workspace_ownership(): void
    {
        $this->assertNull((new ExternalImportItem)->workspaceId());
        $this->assertNull((new ExternalImportFailure)->workspaceId());

        $workspace = Workspace::create([
            'name' => 'Workspace Ownership Contract Fixture',
            'slug' => 'workspace-ownership-contract-fixture',
        ]);
        $run = ExternalImportRun::create([
            'workspace_id' => $workspace->getKey(),
            'source_connection' => 'synthetic-contract-fixture',
            'source_identity_hash' => hash('sha256', 'synthetic-contract-fixture'),
            'mode' => 'apply',
            'status' => 'completed',
            'source_high_water_marks' => [],
            'counts' => [],
            'fingerprints' => [],
        ]);
        $item = ExternalImportItem::create([
            'external_import_run_id' => $run->getKey(),
            'source_connection' => 'synthetic-contract-fixture',
            'source_identity_hash' => $run->source_identity_hash,
            'source_table' => 'synthetic_records',
            'source_key' => 'synthetic-record-1',
            'target_type' => 'synthetic',
            'target_public_id' => null,
            'source_fingerprint' => hash('sha256', 'synthetic-record-1'),
            'status' => 'imported',
        ]);
        $failure = ExternalImportFailure::create([
            'external_import_run_id' => $run->getKey(),
            'external_import_item_id' => $item->getKey(),
            'source_connection' => 'synthetic-contract-fixture',
            'source_table' => 'synthetic_records',
            'source_key_hash' => hash('sha256', 'synthetic-record-1'),
            'reason_code' => 'synthetic_failure',
            'redacted_context' => [],
            'failure_fingerprint' => hash('sha256', 'synthetic-failure-1'),
        ]);

        $this->assertSame($workspace->getKey(), $item->workspaceId());
        $this->assertSame($workspace->getKey(), $failure->workspaceId());
    }
}
