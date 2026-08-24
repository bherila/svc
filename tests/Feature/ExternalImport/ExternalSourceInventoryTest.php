<?php

namespace Tests\Feature\ExternalImport;

use App\Services\ExternalImport\SyntheticExternalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExternalSourceInventoryTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-source-inventory-');
        app(SyntheticExternalSource::class)->create($this->sourcePath);
        Config::set('external-import.sources.external', [
            'connection' => 'synthetic-inventory',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }

        parent::tearDown();
    }

    public function test_inventory_never_resolves_or_writes_a_destination(): void
    {
        Config::set('external-import.destination_connection', 'deliberately-missing');
        $destinationCounts = $this->destinationCounts();

        $exit = Artisan::call('svc:import:external:inventory', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['redacted']);
        $this->assertSame('source-only-read-only', $payload['mode']);
        $this->assertSame(5, $payload['counts']['tables']);
        $this->assertSame(5, $payload['counts']['source_rows']);
        $this->assertSame(0, $payload['counts']['duplicates']);
        $this->assertSame(0, $payload['counts']['orphans']);
        $this->assertSame(0, $payload['counts']['missing_key_columns']);
        $this->assertCount(5, $payload['fingerprints']);
        $this->assertSame($destinationCounts, $this->destinationCounts());
    }

    public function test_inventory_output_does_not_disclose_source_values(): void
    {
        Artisan::call('svc:import:external:inventory', ['--format' => 'json']);
        $output = Artisan::output();

        $this->assertStringContainsString('"redacted":true', $output);
        $this->assertStringNotContainsString('Synthetic User Example', $output);
        $this->assertStringNotContainsString('synthetic.user@example.test', $output);
        $this->assertStringNotContainsString('Synthetic project description', $output);
    }

    public function test_inventory_fails_closed_for_an_unallowlisted_source(): void
    {
        $this->artisan('svc:import:external:inventory', ['--source' => 'unknown', '--format' => 'json'])
            ->assertFailed()
            ->expectsOutput('{"ok":false,"reason_code":"source_not_allowlisted","redacted":true}');
    }

    public function test_inventory_rejects_unknown_output_formats_before_connecting(): void
    {
        Config::set('external-import.sources', []);

        $this->artisan('svc:import:external:inventory', ['--format' => 'xml'])
            ->assertFailed()
            ->expectsOutput('External source inventory could not run: invalid_format');
    }

    /** @return array<string, int> */
    private function destinationCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'workspaces' => DB::table('workspaces')->count(),
            'companies' => DB::table('client_companies')->count(),
            'runs' => DB::table('external_import_runs')->count(),
            'items' => DB::table('external_import_items')->count(),
        ];
    }
}
