<?php

namespace Tests\Feature\ExternalImport;

use App\Models\ExternalImportItem;
use App\Models\ExternalImportRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ExternalImport\ExternalImportService;
use App\Services\ExternalImport\SourceConfigurationException;
use App\Services\ExternalImport\SyntheticExternalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class ExternalImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-external-');
        Config::set('external-import.sources.external', [
            'connection' => 'synthetic',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
        app(SyntheticExternalSource::class)->create($this->sourcePath);
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

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug);

        $this->assertSame('dry-run', $summary['mode']);
        $this->assertTrue($summary['redacted']);
        $this->assertSame(0, ExternalImportRun::query()->count());
        $this->assertSame(0, ExternalImportItem::query()->count());
        $this->assertDatabaseCount('client_companies', 0);
    }

    public function test_apply_is_idempotent_and_uses_parent_before_child_order(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $first = app(ExternalImportService::class)->run('external', $workspace->public_id, true);
        $second = app(ExternalImportService::class)->run('external', $workspace->public_id, true);

        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, $this->countForWorkspace('client_companies', $workspace));
        $this->assertSame(1, $this->countForWorkspace('client_projects', $workspace));
        $this->assertSame(1, $this->countForWorkspace('client_tasks', $workspace));
        $this->assertGreaterThanOrEqual(3, $second['counts']['idempotent']);
        $this->assertSame(5, ExternalImportItem::query()->count());
        $this->assertDatabaseCount('external_import_runs', 2);
        $this->assertDatabaseCount('external_import_run_items', 10);

        $firstVerification = app(ExternalImportService::class)->verify($first['run_public_id']);
        $secondVerification = app(ExternalImportService::class)->verify($second['run_public_id']);
        $this->assertTrue($firstVerification['ok']);
        $this->assertTrue($secondVerification['ok']);
    }

    public function test_destination_equivalence_is_refused(): void
    {
        // Point the source at whatever the destination actually is, rather than
        // assuming sqlite. Hard-coding it made this assert nothing on a MySQL
        // run: the two were genuinely distinct, so the guard correctly let the
        // import through and the test failed on a later check instead.
        $destination = (string) (Config::get('external-import.destination_connection') ?: Config::get('database.default'));
        Config::set('external-import.sources.external.config', Config::get("database.connections.{$destination}"));

        try {
            app(ExternalImportService::class)->run('external', 'missing-workspace');
            $this->fail('Expected destination-equivalence refusal.');
        } catch (SourceConfigurationException $exception) {
            $this->assertSame('source_is_destination', $exception->reasonCode);
        }
    }

    public function test_synthetic_source_refuses_memory_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not :memory:');

        app(SyntheticExternalSource::class)->create(':memory:');
    }

    public function test_synthetic_source_refuses_existing_non_empty_database(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'svc-external-non-empty-');

        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE existing_synthetic_data (id INTEGER PRIMARY KEY)');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('existing non-empty database');

            app(SyntheticExternalSource::class)->create($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_missing_parent_is_counted_without_private_output(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("INSERT INTO client_projects (id, client_company_id, name, description) VALUES (99, 404, 'Synthetic Orphan Project Example', 'Synthetic orphan project description')");
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertGreaterThanOrEqual(1, $summary['counts']['failure_reasons']['missing_parent']);
        $this->assertStringNotContainsString('Synthetic Orphan Project Example', json_encode($summary));
        $this->assertStringNotContainsString('Synthetic orphan project description', json_encode($summary));
    }

    public function test_json_output_is_redacted_and_fingerprints_are_stable(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $first = app(ExternalImportService::class)->run('external', $workspace->slug);
        $second = app(ExternalImportService::class)->run('external', $workspace->slug);
        $this->assertSame($first['fingerprints'], $second['fingerprints']);

        Artisan::call('svc:import:external', ['--source' => 'external', '--workspace' => $workspace->slug, '--format' => 'json']);
        $output = Artisan::output();
        $this->assertStringContainsString('"redacted":true', $output);
        $this->assertStringNotContainsString('Synthetic User Example', $output);
        $this->assertStringNotContainsString('synthetic.user@example.test', $output);
        $this->assertStringNotContainsString('Synthetic project description', $output);
    }

    public function test_email_alone_never_binds_or_imports_an_external_user(): void
    {
        $existing = User::factory()->create(['name' => 'Existing SVC User', 'email' => 'synthetic.user@example.test']);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame('completed_with_skips', $summary['status']);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, $summary['counts']['failure_reasons']['user_binding_required']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_parent']);
        $this->assertDatabaseMissing('external_import_items', ['source_table' => 'users', 'status' => 'imported']);
        $this->assertSame('synthetic.user@example.test', $existing->fresh()->email);
    }

    /**
     * The predecessor soft-deletes, and the import read every row regardless.
     *
     * Of 78 invoices in the migrated source it brought across 49 deleted ones,
     * and of 822 invoice lines it brought across 764. Deleted lines were then
     * counted into invoice totals, which made 14 invoices disagree with the sum
     * of their own lines - read twice, by two reviewers, as corruption in the
     * source. The source is consistent; the import was not.
     */
    public function test_a_soft_deleted_source_row_is_not_imported(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (51, 11, NULL, 'SYN-LIVE', 'paid', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL, NULL)");
        $pdo->exec("INSERT INTO client_invoices VALUES (52, 11, NULL, 'SYN-DELETED', 'draft', '2026-01-11', '2026-02-11', '250.00', 'USD', NULL, '2026-03-01 10:00:00')");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $this->assertSame(0, $summary['counts']['failed']);

        $this->assertDatabaseHas('client_invoices', [
            'workspace_id' => $workspace->getKey(),
            'invoice_number' => 'SYN-LIVE',
        ]);
        $this->assertDatabaseMissing('client_invoices', [
            'workspace_id' => $workspace->getKey(),
            'invoice_number' => 'SYN-DELETED',
        ]);

        // The inventory has to agree with what was written, or the backfill's
        // unmatched check reads a row it was never going to import as a gap.
        $this->assertSame(1, $summary['inventory']['client_invoices']['row_count']);
    }

    public function test_imported_payments_reconcile_invoice_balance(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_payments (client_invoice_payment_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, amount TEXT, currency TEXT, payment_date TEXT, payment_method TEXT, stripe_payment_intent_id TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (21, 11, NULL, 'SYN-21', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_payments VALUES (22, 21, '40.00', 'USD', '2026-01-15', 'check', NULL, NULL)");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

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

    public function test_paid_imported_invoice_without_imported_payments_stays_paid(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (31, 11, NULL, 'SYN-31', 'paid', '2025-06-10', '2025-07-10', '500.00', 'USD', NULL)");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame('completed', $summary['status']);
        $this->assertDatabaseHas('client_invoices', [
            'workspace_id' => $workspace->getKey(),
            'invoice_number' => 'SYN-31',
            'status' => 'paid',
            'paid_amount' => 50000,
            'balance_amount' => 0,
        ]);
    }

    public function test_duplicate_imported_invoice_numbers_receive_stable_collision_suffixes(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (71, 11, NULL, 'SYN-DUPLICATE', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoices VALUES (72, 11, NULL, 'SYN-DUPLICATE', 'issued', '2026-01-11', '2026-02-11', '200.00', 'USD', NULL)");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $numbers = DB::table('client_invoices')->where('workspace_id', $workspace->getKey())->orderBy('id')->pluck('invoice_number')->all();

        $this->assertSame('completed', $summary['status']);
        $this->assertSame('SYN-DUPLICATE', $numbers[0]);
        $this->assertMatchesRegularExpression('/^SYN-DUPLICATE-external-[a-f0-9]{16}$/', $numbers[1]);
    }

    public function test_imported_invoice_line_quantities_normalize_blank_and_hour_minute_values(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (81, 11, NULL, 'SYN-81', 'issued', '2026-01-10', '2026-02-10', '437.50', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (82, 81, NULL, NULL, 'Synthetic fixed line', '', '100.00', '100.00', 'adjustment', 1)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (83, 81, NULL, NULL, 'Synthetic hourly line', '2:15', '150.00', '337.50', 'time', 2)");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $quantities = DB::table('client_invoice_lines')->where('workspace_id', $workspace->getKey())->orderBy('sort_order')->pluck('quantity')->all();

        $this->assertSame('completed', $summary['status']);
        $this->assertSame(1.0, (float) $quantities[0]);
        $this->assertSame(2.25, (float) $quantities[1]);
    }

    public function test_unknown_imported_invoice_line_quantity_fails_instead_of_guessing(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (91, 11, NULL, 'SYN-91', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (92, 91, NULL, NULL, 'Synthetic invalid quantity', 'several', '100.00', '100.00', 'adjustment', 1)");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame('completed_with_failures', $summary['status']);
        $this->assertSame(1, (int) ($summary['counts']['failure_reasons']['row_transaction_failed'] ?? 0));
        $this->assertDatabaseMissing('client_invoice_lines', ['description' => 'Synthetic invalid quantity']);
    }

    public function test_unset_imported_rates_import_as_null_and_cost_never_lands_in_the_billing_rate(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_agreements (id INTEGER PRIMARY KEY, client_company_id INTEGER, proposal_id INTEGER, title TEXT, active_date TEXT, termination_date TEXT, agreement_text TEXT, is_visible_to_client INTEGER, currency TEXT, hourly_rate TEXT, monthly_retainer_fee TEXT, retainer_fee TEXT, monthly_retainer_hours TEXT, retainer_hours TEXT, billing_cadence TEXT, client_company_signed_date TEXT, client_company_signed_user_id INTEGER, client_company_signed_name TEXT, client_company_signed_title TEXT)');
        $pdo->exec("INSERT INTO client_agreements VALUES (41, 11, NULL, 'Synthetic Agreement', '2026-01-01', NULL, NULL, 1, 'USD', NULL, NULL, NULL, NULL, NULL, 'monthly', NULL, NULL, NULL, NULL)");
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, client_company_id INTEGER, project_id INTEGER, task_id INTEGER, user_id INTEGER, date_worked TEXT, minutes_worked INTEGER, name TEXT, is_billable INTEGER, is_deferred_billing INTEGER, subcontractor_hourly_rate TEXT, currency TEXT, approval_status TEXT)');
        $pdo->exec("INSERT INTO client_time_entries VALUES (51, 11, 13, NULL, 7, '2026-02-01', 60, 'Synthetic work', 1, 0, '75.00', 'USD', 'approved')");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

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

    public function test_unparseable_imported_money_fails_the_row_instead_of_corrupting_it(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (61, 11, NULL, 'SYN-61', 'issued', '2026-01-10', '2026-02-10', '1,234.56 USD', 'USD', NULL)");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame('completed_with_failures', $summary['status']);
        $this->assertDatabaseMissing('client_invoices', ['invoice_number' => 'SYN-61']);
        $this->assertSame(1, (int) ($summary['counts']['failure_reasons']['row_transaction_failed'] ?? 0));
    }

    public function test_source_change_is_audited_without_overwriting_the_canonical_item(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $first = app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $item = ExternalImportItem::query()->where('source_table', 'client_companies')->firstOrFail();
        $originalFingerprint = $item->source_fingerprint;

        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("UPDATE client_companies SET company_name = 'Changed synthetic value' WHERE id = 11");
        $second = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame('completed', $first['status']);
        $this->assertSame('completed_with_failures', $second['status']);
        $this->assertSame($originalFingerprint, $item->fresh()->source_fingerprint);
        $this->assertSame('imported', $item->fresh()->status);
        $this->assertDatabaseHas('external_import_run_items', [
            'external_import_run_id' => ExternalImportRun::query()->where('public_id', $second['run_public_id'])->value('id'),
            'external_import_item_id' => $item->getKey(),
            'observed_status' => 'failed',
        ]);
        $this->assertFalse(app(ExternalImportService::class)->verify($second['run_public_id'])['ok']);
    }

    public function test_verify_resolves_imported_attachment_targets_to_attachment_table(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $run = ExternalImportRun::create([
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
            $publicId = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
            DB::table('client_attachments')->insert([
                'public_id' => $publicId,
                'workspace_id' => $workspace->getKey(),
                'record_type' => 'agreement',
                'record_public_id' => sprintf('10000000-0000-4000-8000-%012d', $index + 1),
                'object_key' => 'synthetic/object/'.$publicId,
                'original_filename' => 'synthetic-'.$index.'.txt',
                'media_type' => 'text/plain',
                'bytes' => 9,
                'sha256' => hash('sha256', 'synthetic'),
                'lifecycle_state' => 'available',
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $item = ExternalImportItem::create([
                'external_import_run_id' => $run->getKey(),
                'source_connection' => 'synthetic',
                'source_identity_hash' => $run->source_identity_hash,
                'source_table' => $sourceTable,
                'source_key' => (string) ($index + 1),
                'target_type' => 'attachment',
                'target_public_id' => $publicId,
                'source_fingerprint' => hash('sha256', $sourceTable),
                'status' => 'imported',
            ]);
            DB::table('external_import_run_items')->insert([
                'external_import_run_id' => $run->getKey(),
                'external_import_item_id' => $item->getKey(),
                'observed_status' => 'imported',
                'source_fingerprint' => $item->source_fingerprint,
            ]);
        }

        $verification = app(ExternalImportService::class)->verify($run->public_id);

        $this->assertTrue($verification['ok']);
        $this->assertSame(0, $verification['missing_target_count']);
    }

    public function test_apply_preloads_ledger_items_once_per_source_table(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        for ($id = 100; $id < 120; $id++) {
            $pdo->exec("INSERT INTO client_tasks (id, project_id, name, description, completed_at, created_at, updated_at) VALUES ({$id}, 13, 'Synthetic task {$id}', NULL, NULL, '2026-01-05', '2026-01-06')");
        }

        DB::enableQueryLog();
        app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $ledgerReads = collect(DB::getQueryLog())
            // Each grammar quotes identifiers its own way - SQLite with double
            // quotes, MySQL with backticks - so match the name, not the quoting.
            ->filter(fn (array $query): bool => preg_match('/\bfrom\s+["`]?external_import_items["`]?\b/i', (string) $query['query']) === 1)
            ->count();
        DB::disableQueryLog();

        $this->assertSame(6, $ledgerReads, 'The five source-table preloads plus invoice reconciliation should be the only ledger reads.');
        $this->assertSame(21, $this->countForWorkspace('client_tasks', $workspace));
    }

    private function countForWorkspace(string $table, Workspace $workspace): int
    {
        return DB::table($table)->where('workspace_id', $workspace->getKey())->count();
    }
}
