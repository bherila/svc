<?php

namespace Tests\Feature\LegacyMigration;

use App\Models\LegacyMigrationItem;
use App\Models\LegacyMigrationRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegacyMigration\LegacyMigrationService;
use App\Services\LegacyMigration\SourceConfigurationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class LegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-legacy-');
        Config::set('legacy-migration.sources.legacy', [
            'connection' => 'synthetic',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
        $this->createSource();
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }
        parent::tearDown();
    }

    public function test_dry_run_has_zero_destination_and_ledger_writes(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug);

        $this->assertSame('dry-run', $summary['mode']);
        $this->assertTrue($summary['redacted']);
        $this->assertSame(0, LegacyMigrationRun::query()->count());
        $this->assertSame(0, LegacyMigrationItem::query()->count());
        $this->assertDatabaseCount('client_companies', 0);
    }

    public function test_apply_is_idempotent_and_uses_parent_before_child_order(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $first = app(LegacyMigrationService::class)->run('legacy', $workspace->public_id, true);
        $second = app(LegacyMigrationService::class)->run('legacy', $workspace->public_id, true);

        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, $this->countForWorkspace('client_companies', $workspace));
        $this->assertSame(1, $this->countForWorkspace('client_projects', $workspace));
        $this->assertSame(1, $this->countForWorkspace('client_tasks', $workspace));
        $this->assertGreaterThanOrEqual(3, $second['counts']['idempotent']);
        $this->assertSame(5, LegacyMigrationItem::query()->count());
        $this->assertDatabaseCount('legacy_migration_runs', 2);
        $this->assertDatabaseCount('legacy_migration_run_items', 10);

        $firstVerification = app(LegacyMigrationService::class)->verify($first['run_public_id']);
        $secondVerification = app(LegacyMigrationService::class)->verify($second['run_public_id']);
        $this->assertTrue($firstVerification['ok']);
        $this->assertTrue($secondVerification['ok']);
    }

    public function test_destination_equivalence_is_refused(): void
    {
        Config::set('legacy-migration.sources.legacy.config', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        try {
            app(LegacyMigrationService::class)->run('legacy', 'missing-workspace');
            $this->fail('Expected destination-equivalence refusal.');
        } catch (SourceConfigurationException $exception) {
            $this->assertSame('source_is_destination', $exception->reasonCode);
        }
    }

    public function test_missing_parent_is_counted_without_private_output(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("INSERT INTO client_projects (id, client_company_id, name, description) VALUES (99, 404, 'Private Project Name', 'Private description')");
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertGreaterThanOrEqual(1, $summary['counts']['failure_reasons']['missing_parent']);
        $this->assertStringNotContainsString('Private Project Name', json_encode($summary));
        $this->assertStringNotContainsString('Private description', json_encode($summary));
    }

    public function test_json_output_is_redacted_and_fingerprints_are_stable(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $first = app(LegacyMigrationService::class)->run('legacy', $workspace->slug);
        $second = app(LegacyMigrationService::class)->run('legacy', $workspace->slug);
        $this->assertSame($first['fingerprints'], $second['fingerprints']);

        Artisan::call('svc:migrate:legacy', ['--source' => 'legacy', '--workspace' => $workspace->slug, '--format' => 'json']);
        $output = Artisan::output();
        $this->assertStringContainsString('"redacted":true', $output);
        $this->assertStringNotContainsString('Alice Example', $output);
        $this->assertStringNotContainsString('alice@example.test', $output);
        $this->assertStringNotContainsString('Private description', $output);
    }

    public function test_email_alone_never_binds_or_imports_a_legacy_user(): void
    {
        $existing = User::factory()->create(['name' => 'Existing SVC User', 'email' => 'alice@example.test']);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertSame('completed_with_skips', $summary['status']);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, $summary['counts']['failure_reasons']['user_binding_required']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_parent']);
        $this->assertDatabaseMissing('legacy_migration_items', ['source_table' => 'users', 'status' => 'imported']);
        $this->assertSame('alice@example.test', $existing->fresh()->email);
    }

    public function test_imported_payments_reconcile_invoice_balance(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_payments (client_invoice_payment_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, amount TEXT, currency TEXT, payment_date TEXT, payment_method TEXT, stripe_payment_intent_id TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (21, 11, NULL, 'SYN-21', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_payments VALUES (22, 21, '40.00', 'USD', '2026-01-15', 'check', NULL, NULL)");

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertSame('completed', $summary['status']);
        $this->assertDatabaseHas('client_invoices', [
            'workspace_id' => $workspace->getKey(),
            'invoice_number' => 'SYN-21',
            'status' => 'partially_paid',
            'total_amount' => 10000,
            'paid_amount' => 4000,
            'balance_amount' => 6000,
            'is_visible_to_client' => true,
        ]);
    }

    public function test_paid_legacy_invoice_without_migrated_payments_stays_paid(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (31, 11, NULL, 'SYN-31', 'paid', '2025-06-10', '2025-07-10', '500.00', 'USD', NULL)");

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertSame('completed', $summary['status']);
        $this->assertDatabaseHas('client_invoices', [
            'workspace_id' => $workspace->getKey(),
            'invoice_number' => 'SYN-31',
            'status' => 'paid',
            'paid_amount' => 50000,
            'balance_amount' => 0,
        ]);
    }

    public function test_unset_legacy_rates_import_as_null_and_cost_never_lands_in_the_billing_rate(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_agreements (id INTEGER PRIMARY KEY, client_company_id INTEGER, proposal_id INTEGER, title TEXT, active_date TEXT, termination_date TEXT, agreement_text TEXT, is_visible_to_client INTEGER, currency TEXT, hourly_rate TEXT, monthly_retainer_fee TEXT, retainer_fee TEXT, monthly_retainer_hours TEXT, retainer_hours TEXT, billing_cadence TEXT, client_company_signed_date TEXT, client_company_signed_user_id INTEGER, client_company_signed_name TEXT, client_company_signed_title TEXT)');
        $pdo->exec("INSERT INTO client_agreements VALUES (41, 11, NULL, 'Synthetic Agreement', '2026-01-01', NULL, NULL, 1, 'USD', NULL, NULL, NULL, NULL, NULL, 'monthly', NULL, NULL, NULL, NULL)");
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, client_company_id INTEGER, project_id INTEGER, task_id INTEGER, user_id INTEGER, date_worked TEXT, minutes_worked INTEGER, name TEXT, is_billable INTEGER, is_deferred_billing INTEGER, subcontractor_hourly_rate TEXT, currency TEXT, approval_status TEXT)');
        $pdo->exec("INSERT INTO client_time_entries VALUES (51, 11, 13, NULL, 7, '2026-02-01', 60, 'Synthetic work', 1, 0, '75.00', 'USD', 'approved')");

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertSame('completed', $summary['status']);
        $this->assertDatabaseHas('client_agreements', [
            'workspace_id' => $workspace->getKey(),
            'title' => 'Synthetic Agreement',
            'hourly_rate_amount' => null,
            'retainer_amount' => null,
        ]);
        $this->assertDatabaseHas('client_time_entries', [
            'workspace_id' => $workspace->getKey(),
            'description' => 'Synthetic work',
            'billing_rate_amount' => null,
            'subcontractor_cost_amount' => 7500,
        ]);
    }

    public function test_unparseable_legacy_money_fails_the_row_instead_of_corrupting_it(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (61, 11, NULL, 'SYN-61', 'issued', '2026-01-10', '2026-02-10', '1,234.56 USD', 'USD', NULL)");

        $summary = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertSame('completed_with_failures', $summary['status']);
        $this->assertDatabaseMissing('client_invoices', ['invoice_number' => 'SYN-61']);
        $this->assertSame(1, (int) ($summary['counts']['failure_reasons']['row_transaction_failed'] ?? 0));
    }

    public function test_source_change_is_audited_without_overwriting_the_canonical_item(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $first = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);
        $item = LegacyMigrationItem::query()->where('source_table', 'client_companies')->firstOrFail();
        $originalFingerprint = $item->source_fingerprint;

        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("UPDATE client_companies SET company_name = 'Changed private value' WHERE id = 11");
        $second = app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);

        $this->assertSame('completed', $first['status']);
        $this->assertSame('completed_with_failures', $second['status']);
        $this->assertSame($originalFingerprint, $item->fresh()->source_fingerprint);
        $this->assertSame('imported', $item->fresh()->status);
        $this->assertDatabaseHas('legacy_migration_run_items', [
            'legacy_migration_run_id' => LegacyMigrationRun::query()->where('public_id', $second['run_public_id'])->value('id'),
            'legacy_migration_item_id' => $item->getKey(),
            'observed_status' => 'failed',
        ]);
        $this->assertFalse(app(LegacyMigrationService::class)->verify($second['run_public_id'])['ok']);
    }

    public function test_verify_disambiguates_repeated_attachment_target_types_by_source_table(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $run = LegacyMigrationRun::create([
            'workspace_id' => $workspace->getKey(),
            'source_connection' => 'synthetic',
            'source_identity_hash' => hash('sha256', 'synthetic-attachments'),
            'mode' => 'apply',
            'status' => 'completed',
            'source_high_water_marks' => [],
            'counts' => [],
            'fingerprints' => [],
        ]);
        $attachmentTables = [
            'files_for_client_companies',
            'files_for_projects',
            'files_for_tasks',
            'files_for_agreements',
        ];

        foreach ($attachmentTables as $index => $sourceTable) {
            Schema::create($sourceTable, function ($table): void {
                $table->uuid('public_id')->primary();
            });
            $publicId = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
            DB::table($sourceTable)->insert(['public_id' => $publicId]);
            $item = LegacyMigrationItem::create([
                'legacy_migration_run_id' => $run->getKey(),
                'source_connection' => 'synthetic',
                'source_identity_hash' => $run->source_identity_hash,
                'source_table' => $sourceTable,
                'source_key' => (string) ($index + 1),
                'target_type' => 'attachment',
                'target_public_id' => $publicId,
                'source_fingerprint' => hash('sha256', $sourceTable),
                'status' => 'imported',
            ]);
            DB::table('legacy_migration_run_items')->insert([
                'legacy_migration_run_id' => $run->getKey(),
                'legacy_migration_item_id' => $item->getKey(),
                'observed_status' => 'imported',
                'source_fingerprint' => $item->source_fingerprint,
            ]);
        }

        $verification = app(LegacyMigrationService::class)->verify($run->public_id);

        $this->assertTrue($verification['ok']);
        $this->assertSame(0, $verification['missing_target_count']);
    }

    public function test_apply_preloads_ledger_items_once_per_source_table(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        for ($id = 100; $id < 120; $id++) {
            $pdo->exec("INSERT INTO client_tasks (id, project_id, name, description, completed_at, created_at, updated_at) VALUES ({$id}, 13, 'Synthetic task {$id}', NULL, NULL, '2026-01-05', '2026-01-06')");
        }

        DB::enableQueryLog();
        app(LegacyMigrationService::class)->run('legacy', $workspace->slug, true);
        $ledgerReads = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'from "legacy_migration_items"'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(6, $ledgerReads, 'The five source-table preloads plus invoice reconciliation should be the only ledger reads.');
        $this->assertSame(21, $this->countForWorkspace('client_tasks', $workspace));
    }

    private function createSource(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_companies (id INTEGER PRIMARY KEY, company_name TEXT, slug TEXT, billing_email TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_company_user (id INTEGER PRIMARY KEY, client_company_id INTEGER, user_id INTEGER, role TEXT)');
        $pdo->exec('CREATE TABLE client_projects (id INTEGER PRIMARY KEY, client_company_id INTEGER, name TEXT, description TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_tasks (id INTEGER PRIMARY KEY, project_id INTEGER, name TEXT, description TEXT, completed_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO users VALUES (7, 'Alice Example', 'alice@example.test', '2026-01-01', '2026-01-02')");
        $pdo->exec("INSERT INTO client_companies VALUES (11, 'Example Business', 'example-business', 'billing@example.test', 1, '2026-01-01', '2026-01-02')");
        $pdo->exec("INSERT INTO client_company_user VALUES (12, 11, 7, 'client')");
        $pdo->exec("INSERT INTO client_projects VALUES (13, 11, 'Private Project Name', 'Private description', '2026-01-03', '2026-01-04')");
        $pdo->exec("INSERT INTO client_tasks VALUES (14, 13, 'Private Task Name', 'Private task description', NULL, '2026-01-05', '2026-01-06')");
    }

    private function countForWorkspace(string $table, Workspace $workspace): int
    {
        return DB::table($table)->where('workspace_id', $workspace->getKey())->count();
    }
}
