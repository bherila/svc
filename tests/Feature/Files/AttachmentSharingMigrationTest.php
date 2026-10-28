<?php

namespace Tests\Feature\Files;

use App\Services\Files\AttachmentPrivacyLatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class AttachmentSharingMigrationTest extends TestCase
{
    use BuildsSyntheticExpenses, UsesAProbeDatabase;

    public function test_fresh_schema_defaults_omitted_file_visibility_private_without_requiring_a_runtime_marker(): void
    {
        $this->bootProbeDatabase('sharing_default_probe');
        config(['svc.attachments.sharing_writes_enabled' => false]);
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch('/synthetic-no-runtime-policy-directory'));
        Artisan::call('migrate', ['--database' => 'sharing_default_probe', '--force' => true]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('sharing_default_probe');
        Schema::clearResolvedInstance('db.schema');
        try {
            $workspace = $this->syntheticWorkspace('sharing-default');
            $company = $this->syntheticCompany($workspace, 'sharing-default');
            $id = (string) Str::uuid();
            DB::table('client_attachments')->insert(['public_id' => $id, 'workspace_id' => $workspace->id,
                'record_type' => 'company', 'record_public_id' => $company->public_id, 'object_key' => 'synthetic/'.$id,
                'original_filename' => 'Synthetic encrypted fixture placeholder', 'media_type' => 'text/plain',
                'bytes' => 12, 'sha256' => str_repeat('a', 64)]);
            $this->assertSame(0, (int) DB::table('client_attachments')->where('workspace_id', $workspace->id)
                ->where('public_id', $id)->value('is_visible_to_client'));
        } finally {
            DB::setDefaultConnection($default);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_default_change_preserves_every_existing_lifecycle_and_rollback_refuses_retained_privacy(): void
    {
        $this->bootProbeDatabase('sharing_retained_probe');
        Artisan::call('migrate', ['--database' => 'sharing_retained_probe', '--force' => true]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('sharing_retained_probe');
        Schema::clearResolvedInstance('db.schema');
        $directory = sys_get_temp_dir().'/svc-sharing-ddl-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($directory));
        try {
            $migration = require database_path('migrations/2026_10_22_180210_use_private_attachment_default.php');
            $migration->down();
            $workspace = $this->syntheticWorkspace('sharing-retained');
            $company = $this->syntheticCompany($workspace, 'sharing-retained');
            foreach (['staged', 'available', 'deleting', 'deleted', 'corrupt'] as $state) {
                $id = (string) Str::uuid();
                DB::table('client_attachments')->insert(['public_id' => $id, 'workspace_id' => $workspace->id,
                    'record_type' => 'company', 'record_public_id' => $company->public_id, 'object_key' => 'synthetic/'.$id,
                    'original_filename' => 'Synthetic encrypted fixture placeholder', 'media_type' => 'text/plain',
                    'bytes' => 12, 'sha256' => str_repeat('a', 64), 'lifecycle_state' => $state]);
            }
            $migration->up();
            $this->assertSame(5, DB::table('client_attachments')->where('is_visible_to_client', true)->count());
            $this->assertSame(0, DB::table('client_attachments')->where('is_visible_to_client', false)->count());
            $indexes = Schema::getIndexes('client_attachments');
            $keys = Schema::getForeignKeys('client_attachments');
            foreach (['private', 'topic', 'comment', 'latch'] as $reason) {
                DB::table('client_attachments')->update(['is_visible_to_client' => $reason !== 'private',
                    'record_type' => in_array($reason, ['topic', 'comment'], true) ? $reason : 'company']);
                if ($reason === 'latch') {
                    DB::table('client_attachments')->delete();
                    File::put($directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
                }
                try {
                    $migration->down();
                    $this->fail('Rollback removed a required private default.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('private-default schema', $exception->getMessage());
                }
                $this->assertSame($indexes, Schema::getIndexes('client_attachments'));
                $this->assertSame($keys, Schema::getForeignKeys('client_attachments'));
            }
        } finally {
            File::deleteDirectory($directory);
            DB::setDefaultConnection($default);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
