<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageStatusCommandTest extends TestCase
{
    public function test_status_reports_a_configured_disk_without_writing(): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);

        $this->artisan('svc:storage:status', ['--format' => 'json'])
            ->expectsOutput('{"configured":true,"write_probe":null,"cleanup":null}')
            ->assertSuccessful();
    }

    public function test_write_probe_verifies_content_and_removes_its_synthetic_file(): void
    {
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);

        $this->artisan('svc:storage:status', ['--write' => true, '--format' => 'json'])
            ->expectsOutput('{"configured":true,"write_probe":true,"cleanup":true}')
            ->assertSuccessful();

        Storage::disk('svc_files')->assertDirectoryEmpty('_health');
    }

    public function test_status_fails_without_disclosing_an_invalid_disk_name(): void
    {
        config(['svc.filesystem_disk' => 'synthetic-missing-disk']);

        $this->artisan('svc:storage:status', ['--format' => 'json'])
            ->expectsOutput('{"configured":false,"write_probe":false,"cleanup":false}')
            ->doesntExpectOutputToContain('synthetic-missing-disk')
            ->assertFailed();
    }
}
