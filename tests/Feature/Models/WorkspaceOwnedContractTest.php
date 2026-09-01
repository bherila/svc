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

    public function test_every_model_with_a_workspace_column_carries_the_ownership_contract(): void
    {
        $unmarked = [];

        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            /** @var Model $model */
            $model = $reflection->newInstance();

            if (Schema::hasColumn($model->getTable(), 'workspace_id')
                && ! $reflection->implementsInterface(WorkspaceOwned::class)) {
                $unmarked[] = $class;
            }
        }

        $this->assertSame([], $unmarked, 'Workspace-owned models missing the WorkspaceOwned contract: '.implode(', ', $unmarked));
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
