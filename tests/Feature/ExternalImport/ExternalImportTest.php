<?php

namespace Tests\Feature\ExternalImport;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
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

    /**
     * A row imported by an earlier pass and deleted at the source afterwards.
     *
     * Filtering it out of the read means its ledger item is never examined, so
     * the destination keeps a live copy of something the source no longer has
     * while the run reports clean. The delete is not propagated - that row may
     * since have been issued or paid against, and an import pass should not make
     * that call - but the run must say so.
     */
    public function test_a_row_deleted_after_import_is_reported_rather_than_ignored(): void
    {
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (61, 11, NULL, 'SYN-61', 'paid', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL, NULL)");

        $first = app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $this->assertSame(0, $first['counts']['deleted_at_source']);
        $this->assertDatabaseHas('client_invoices', ['workspace_id' => $workspace->getKey(), 'invoice_number' => 'SYN-61']);

        // The source deletes it after the first pass.
        $pdo->exec("UPDATE client_invoices SET deleted_at = '2026-03-01 10:00:00' WHERE client_invoice_id = 61");

        $second = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $second['counts']['deleted_at_source']);
        $this->assertNotSame('completed', $second['status'], 'A run that left an orphaned copy behind has not completed cleanly');

        // Not propagated: the copy is still here, deliberately.
        $this->assertDatabaseHas('client_invoices', ['workspace_id' => $workspace->getKey(), 'invoice_number' => 'SYN-61']);
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

    public function test_a_milestone_task_keeps_the_line_that_billed_it(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (91, 11, NULL, 'SYN-91', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (92, 91, NULL, NULL, 'Synthetic milestone line', '1', '2500.00', '2500.00', 'milestone', 1)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 92, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $lineId = DB::table('client_invoice_lines')->where('workspace_id', $workspace->getKey())->value('id');
        $taskLink = DB::table('client_tasks')->where('workspace_id', $workspace->getKey())->value('client_invoice_line_id');

        $this->assertSame('completed', $summary['status']);
        $this->assertSame(1, $summary['milestone_link_counts']['source_rows']);
        $this->assertSame(1, $summary['milestone_link_counts']['linked']);
        // Without this the next generation run reads the task as unbilled and
        // charges the client for the same deliverable a second time.
        $this->assertNotNull($taskLink);
        $this->assertSame((int) $lineId, (int) $taskLink);
        // Reconstructing a link is not the source editing the row, so the
        // source date the import wrote must survive the reconciliation.
        $this->assertStringStartsWith(
            '2026-01-06',
            (string) DB::table('client_tasks')->where('workspace_id', $workspace->getKey())->value('updated_at'),
        );
    }

    public function test_a_milestone_link_never_crosses_a_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (97, 11, NULL, 'SYN-97', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (98, 97, NULL, NULL, 'Synthetic milestone line', '1', '100.00', '100.00', 'milestone', 1)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 98 WHERE id = 14');

        app(ExternalImportService::class)->run('external', $owner->slug, true);

        // Everything now belongs to the owning workspace, and the ledger is
        // keyed on the source identity rather than on a workspace - so a run
        // for a different tenant resolves these very rows.
        $taskId = DB::table('client_tasks')->where('workspace_id', $owner->getKey())->value('id');
        DB::table('client_tasks')->where('id', $taskId)->update(['client_invoice_line_id' => null]);

        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        // Without the workspace predicate on the read and the update, this run
        // would reach across and write a billing link into another tenant.
        $this->assertSame(1, $summary['milestone_link_counts']['failed']);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['milestone_link_outside_workspace'] ?? 0);
        $this->assertNull(DB::table('client_tasks')->where('id', $taskId)->value('client_invoice_line_id'));
    }

    public function test_a_milestone_link_refuses_an_invoice_line_owned_by_another_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (101, 11, NULL, 'SYN-101', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (102, 101, NULL, NULL, 'Synthetic milestone line', '1', '100.00', '100.00', 'milestone', 1)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 102 WHERE id = 14');

        app(ExternalImportService::class)->run('external', $owner->slug, true);

        // A task belonging to the other workspace, and the ledger repointed at
        // it. This is what an incremental import into a second workspace looks
        // like: the task mapping resolves in one tenant while the invoice line
        // mapping still resolves in the first.
        $strandedTask = $this->taskInWorkspace($other);
        DB::table('external_import_items')
            ->where('source_table', 'client_tasks')
            ->where('source_key', '14')
            ->update(['target_public_id' => $strandedTask->public_id]);

        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        // The task passes a task-only ownership check, so only validating both
        // sides catches this. Otherwise one tenant's task points straight at
        // another tenant's financial row, with no composite key beneath it.
        $this->assertSame(1, $summary['milestone_link_counts']['failed']);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['milestone_link_outside_workspace'] ?? 0);
        $this->assertNull(DB::table('client_tasks')->where('id', $strandedTask->id)->value('client_invoice_line_id'));
    }

    public function test_a_task_whose_source_row_changed_does_not_get_a_milestone_link(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (99, 11, NULL, 'SYN-99', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (100, 99, NULL, NULL, 'Synthetic milestone line', '1', '100.00', '100.00', 'milestone', 1)");

        app(ExternalImportService::class)->run('external', $workspace->slug, true);

        // The source row now differs from the snapshot the ledger recorded, and
        // it has grown a billing link. The run refuses the new snapshot, so the
        // link must be refused with it rather than read straight off the source.
        $pdo->exec("UPDATE client_tasks SET name = 'Renamed after import', client_invoice_line_id = 100 WHERE id = 14");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['counts']['failure_reasons']['source_changed'] ?? 0);
        $this->assertSame(1, $summary['milestone_link_counts']['rejected']);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertNull(DB::table('client_tasks')->where('workspace_id', $workspace->getKey())->value('client_invoice_line_id'));

        // An unlinked milestone is one the next generation run bills again, so
        // the rejection has to reach the run status rather than living only in
        // the link counters.
        $this->assertNotSame('completed', $summary['status']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['milestone_link_source_changed'] ?? 0);
    }

    public function test_a_billed_time_link_refuses_rows_owned_by_another_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, project_id INTEGER, client_company_id INTEGER, task_id INTEGER, user_id INTEGER, name TEXT, minutes_worked INTEGER, date_worked TEXT, is_billable INTEGER, is_deferred_billing INTEGER, approval_status TEXT, client_invoice_line_id INTEGER)');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (111, 11, NULL, 'SYN-111', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (112, 111, NULL, NULL, 'Synthetic billed line', '1', '100.00', '100.00', 'time', 1)");
        $pdo->exec("INSERT INTO client_time_entries VALUES (113, 13, 11, NULL, 7, 'Synthetic billed work', 60, '2026-01-20', 1, 0, 'approved', 112)");

        app(ExternalImportService::class)->run('external', $owner->slug, true);

        $pivotsAfterOwner = DB::table('client_invoice_line_time_entries')->count();

        // Both rows belong to the owning workspace; a run for another tenant
        // resolves them through the same identity-keyed ledger.
        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        $this->assertSame(1, $summary['link_counts']['failed']);
        $this->assertSame(0, $summary['link_counts']['inserted']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['time_link_outside_workspace'] ?? 0);
        // No pivot row was added, and none carries the other tenant's id.
        $this->assertSame($pivotsAfterOwner, DB::table('client_invoice_line_time_entries')->count());
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->where('workspace_id', $other->getKey())->count());
    }

    /**
     * The source regenerates an invoice by soft-deleting its lines and
     * inserting fresh ones, without repointing the entries that named the old
     * ones. Line 122 below is one of those superseded copies.
     */
    private function supersededClaimSource(
        PDO $pdo,
        string $replacementType = 'prior_month_retainer',
        string $supersededType = 'prior_month_retainer',
        bool $withSecondLiveLine = false,
        int $unclaimedEarlierGenerations = 0,
        bool $withEntryAlreadyOnTheReplacement = false,
    ): void {
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, project_id INTEGER, client_company_id INTEGER, task_id INTEGER, user_id INTEGER, name TEXT, minutes_worked INTEGER, date_worked TEXT, is_billable INTEGER, is_deferred_billing INTEGER, approval_status TEXT, client_invoice_line_id INTEGER)');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (121, 11, NULL, 'SYN-121', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (122, 121, NULL, NULL, 'Work items applied to retainer (9.9168)', '1', '100.00', '100.00', '{$supersededType}', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (123, 121, NULL, NULL, 'Work items applied to retainer (10.0000)', '1', '100.00', '100.00', '{$replacementType}', 2, NULL)");

        if ($withSecondLiveLine) {
            $pdo->exec("INSERT INTO client_invoice_lines VALUES (124, 121, NULL, NULL, 'Work items applied to retainer (11.0000)', '1', '100.00', '100.00', '{$replacementType}', 3, NULL)");
        }

        // Earlier regenerations of the same aggregate line. Nothing names them
        // any more - only the last superseded copy is claimed - so they must not
        // make the replacement look ambiguous.
        for ($i = 0; $i < $unclaimedEarlierGenerations; $i++) {
            $key = 200 + $i;
            $pdo->exec("INSERT INTO client_invoice_lines VALUES ({$key}, 121, NULL, NULL, 'Work items applied to retainer (8.0000)', '1', '100.00', '100.00', '{$supersededType}', 0, '2026-01-11 08:00:00')");
        }

        $pdo->exec("INSERT INTO client_time_entries VALUES (125, 13, 11, NULL, 7, 'Synthetic billed work', 60, '2026-01-20', 1, 0, 'approved', 122)");

        if ($withEntryAlreadyOnTheReplacement) {
            // One line bills many entries, so the replacement having claimants
            // already is the ordinary case, not evidence of ambiguity.
            $pdo->exec("INSERT INTO client_time_entries VALUES (126, 13, 11, NULL, 7, 'Work already on the live line', 30, '2026-01-21', 1, 0, 'approved', 123)");
        }
    }

    public function test_a_time_entry_naming_a_superseded_line_is_billed_by_its_replacement(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath));

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $liveLineId = ClientInvoiceLine::query()->where('workspace_id', $workspace->getKey())->value('id');
        $pivot = DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->getKey())->first();

        $this->assertSame(1, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['link_counts']['inserted']);
        $this->assertSame(0, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        // The superseded copy is deleted at the source and must stay uneported;
        // only its live replacement is here to be linked to.
        $this->assertSame(1, ClientInvoiceLine::query()->where('workspace_id', $workspace->getKey())->count());
        $this->assertNotNull($pivot);
        $this->assertSame((int) $liveLineId, (int) $pivot->client_invoice_line_id);
        // The whole point: the generator must not read this as outstanding work
        // and charge the client for hours the invoice already billed.
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->unbilled()->count());
    }

    /**
     * The production shape. One invoice was regenerated twenty-one times, so it
     * carries twenty-one superseded copies of the same aggregate line and one
     * live one. Only the last copy is claimed, and counting the copies rather
     * than the claims would refuse the very case this exists for.
     */
    public function test_earlier_generations_nothing_claims_do_not_make_the_replacement_ambiguous(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath), unclaimedEarlierGenerations: 20);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['link_counts']['inserted']);
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->unbilled()->count());
    }

    /**
     * A time entry's claim is a pivot row precisely because one line bills many
     * entries, so a replacement that already has claimants is the ordinary
     * case. In the migrated data the line the twenty are recovered onto is
     * already held by nineteen live entries; treating that as ambiguity would
     * refuse the case this exists for.
     */
    public function test_a_replacement_other_entries_already_share_is_still_available(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath), withEntryAlreadyOnTheReplacement: true);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['link_counts']['recovered']);
        // Both entries end up on the one line that survived, which is what a
        // many-to-many pivot is for.
        $this->assertSame(2, DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->getKey())->count());
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->unbilled()->count());
    }

    /**
     * A subcontractor charge is one line per (user, project, rate, currency)
     * group, so one superseded line and one live one are as likely to be two
     * different groups as the same line twice. A time entry's claim cannot tell
     * them apart - many entries share a line by design - and the type does not
     * either, so the mapping is refused rather than guessed.
     */
    public function test_a_per_item_line_type_is_never_recovered_from_a_time_entry_claim(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(
            new PDO('sqlite:'.$this->sourcePath),
            replacementType: 'subcontractor',
            supersededType: 'subcontractor',
        );

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->count());
    }

    public function test_a_superseded_claim_is_refused_when_two_live_lines_could_be_the_replacement(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath), withSecondLiveLine: true);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        // Attaching the work to whichever line happened to sort first would
        // suppress a charge that may be owed, so an ambiguous claim is reported
        // rather than guessed.
        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame(0, $summary['link_counts']['inserted']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->count());
    }

    public function test_a_superseded_claim_is_refused_when_no_live_line_shares_its_type(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        // The invoice kept a line, but not one of the superseded line's type -
        // the shape of a line an operator removed rather than regenerated.
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath), replacementType: 'adjustment');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->count());
    }

    public function test_a_superseded_claim_never_resolves_across_a_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath));

        app(ExternalImportService::class)->run('external', $owner->slug, true);
        $pivotsAfterOwner = DB::table('client_invoice_line_time_entries')->count();

        // The ledger is keyed on source identity, so a run for another tenant
        // resolves the replacement to the owning tenant's invoice line. The
        // foreign key is not workspace-composite and would accept it.
        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame($pivotsAfterOwner, DB::table('client_invoice_line_time_entries')->count());
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->where('workspace_id', $other->getKey())->count());
    }

    /**
     * A milestone line is one line per task, so two superseded milestone lines
     * stand for two deliverables. If regeneration kept only one, resolving both
     * claims to the survivor would mark the dropped task billed and nothing
     * would ever charge for it.
     */
    public function test_two_claimed_superseded_lines_never_collapse_onto_one_survivor(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec("INSERT INTO client_tasks VALUES (15, 13, 'Second milestone', 'The one regeneration dropped', '2026-01-09', '2026-01-05', '2026-01-06', NULL, NULL)");
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (141, 11, NULL, 'SYN-141', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (142, 141, NULL, NULL, 'Superseded line for task 14', '1', '2500.00', '2500.00', 'milestone', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (143, 141, NULL, NULL, 'Superseded line for task 15', '1', '2500.00', '2500.00', 'milestone', 2, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (144, 141, NULL, NULL, 'The only line regeneration kept', '1', '2500.00', '2500.00', 'milestone', 3, NULL)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 142, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 143, milestone_price = 2500.00 WHERE id = 15');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(0, $summary['milestone_link_counts']['recovered']);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertSame(2, $summary['counts']['failure_reasons']['missing_milestone_invoice_link_parent'] ?? 0);
        $this->assertSame(
            0,
            ClientTask::query()->where('workspace_id', $workspace->getKey())->whereNotNull('client_invoice_line_id')->count(),
            'Marking the dropped deliverable billed would mean nothing ever charges for it',
        );
    }

    /**
     * A milestone task holds its line in a column because a milestone is one
     * deliverable that cannot be split. So a live line another task already
     * holds is not available to this one: attaching the dropped milestone to
     * its neighbour's line would mark it billed and nothing would charge for it.
     */
    public function test_a_replacement_another_milestone_already_holds_is_not_available(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec("INSERT INTO client_tasks VALUES (16, 13, 'The milestone that kept its line', 'Still billed', '2026-01-09', '2026-01-05', '2026-01-06', NULL, NULL)");
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (151, 11, NULL, 'SYN-151', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (152, 151, NULL, NULL, 'Superseded line for task 14', '1', '2500.00', '2500.00', 'milestone', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (153, 151, NULL, NULL, 'The line task 16 still holds', '1', '2500.00', '2500.00', 'milestone', 2, NULL)");
        // Task 14's line was superseded; task 16 holds the only live one.
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 152, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 153, milestone_price = 2500.00 WHERE id = 16');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $held = ClientTask::query()->where('workspace_id', $workspace->getKey())->whereNotNull('client_invoice_line_id')->count();

        $this->assertSame(0, $summary['milestone_link_counts']['recovered']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_milestone_invoice_link_parent'] ?? 0);
        // Task 16 keeps the line it always had; task 14 is left unlinked and
        // reported rather than quietly attached to a deliverable it is not.
        $this->assertSame(1, $held, 'Only the task that genuinely holds the live line may point at it');
    }

    /**
     * The pivot is unique on the entry. An operator billing it in the gap
     * between the two reads leaves a link this import has no business
     * repointing - and inserting beside it would violate the constraint and
     * throw the run after earlier tables had committed.
     */
    public function test_a_recovered_claim_leaves_a_destination_link_the_operator_made(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath));

        app(ExternalImportService::class)->run('external', $workspace->slug, true);

        // The operator moves the entry onto a different line after the import.
        $entryId = ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->value('id');
        $other = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_invoice_id' => ClientInvoice::query()->where('workspace_id', $workspace->getKey())->value('id'),
            'type' => 'adjustment', 'description' => 'Billed by hand', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 9,
        ]);
        DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entryId)
            ->update(['client_invoice_line_id' => $other->id]);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['counts']['failure_reasons']['time_link_destination_claims_another_line'] ?? 0);
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entryId)->count());
        $this->assertSame(
            (int) $other->id,
            (int) DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entryId)->value('client_invoice_line_id'),
        );
    }

    /**
     * A row can hold the replacement from an earlier import while the claim
     * that put it there has since been cleared at the source. It is then absent
     * from what this run observed, so only the destination knows the line is
     * spoken for.
     */
    public function test_a_replacement_held_in_the_destination_is_not_available_either(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec("INSERT INTO client_tasks VALUES (18, 13, 'The milestone that kept its line', 'Still billed', '2026-01-09', '2026-01-05', '2026-01-06', NULL, NULL)");
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (171, 11, NULL, 'SYN-171', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (172, 171, NULL, NULL, 'Superseded line for task 14', '1', '2500.00', '2500.00', 'milestone', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (173, 171, NULL, NULL, 'The line task 18 holds', '1', '2500.00', '2500.00', 'milestone', 2, NULL)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 172, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 173, milestone_price = 2500.00 WHERE id = 18');

        app(ExternalImportService::class)->run('external', $workspace->slug, true);
        $held = ClientTask::query()->where('workspace_id', $workspace->getKey())->whereNotNull('client_invoice_line_id')->count();

        // Task 18's claim is cleared at the source. The destination link it
        // already established stays, and the next run cannot see the claim.
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = NULL WHERE id = 18');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $held, 'Task 18 took its line on the first run');
        $this->assertSame(0, $summary['milestone_link_counts']['recovered']);
        $this->assertSame(
            1,
            ClientTask::query()->where('workspace_id', $workspace->getKey())->whereNotNull('client_invoice_line_id')->count(),
            'The line is still held by one task, not two',
        );
    }

    /**
     * Losing the race to a writer that claimed the entry for a different line
     * means what the read before the insert would have meant.
     */
    public function test_losing_the_pivot_constraint_to_another_line_is_reported_rather_than_thrown(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath));

        // Between the read that finds no pivot and the insert that follows it,
        // somebody else claims the entry.
        DB::listen(function ($query) use ($workspace): void {
            static $done = false;
            if ($done || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'client_invoice_line_time_entries')) {
                return;
            }
            $done = true;
            $entryId = ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->value('id');
            $invoiceId = ClientInvoice::query()->where('workspace_id', $workspace->getKey())->value('id');
            if ($entryId === null || $invoiceId === null) {
                return;
            }
            $elsewhere = ClientInvoiceLine::query()->create([
                'workspace_id' => $workspace->getKey(), 'client_invoice_id' => $invoiceId,
                'type' => 'adjustment', 'description' => 'Billed by somebody else', 'quantity' => '1.0000',
                'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 9,
            ]);
            DB::table('client_invoice_line_time_entries')->insert([
                'workspace_id' => $workspace->getKey(),
                'client_invoice_line_id' => $elsewhere->id,
                'client_time_entry_id' => $entryId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        // The run completes rather than throwing, and says what happened.
        $this->assertSame(1, $summary['counts']['failure_reasons']['time_link_destination_claims_another_line'] ?? 0);
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->count());
    }

    /**
     * Losing the race to a writer that wrote the very row this was about to
     * does not mean the same thing. Reporting a skip there would end an
     * otherwise reconciled run with skips over work that was in fact done.
     */
    public function test_losing_the_pivot_constraint_to_the_same_line_is_idempotent(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath));

        DB::listen(function ($query) use ($workspace): void {
            static $done = false;
            if ($done || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'client_invoice_line_time_entries')) {
                return;
            }
            $done = true;
            $entryId = ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->value('id');
            $lineId = ClientInvoiceLine::query()->where('workspace_id', $workspace->getKey())->value('id');
            if ($entryId === null || $lineId === null) {
                return;
            }
            DB::table('client_invoice_line_time_entries')->insert([
                'workspace_id' => $workspace->getKey(),
                'client_invoice_line_id' => $lineId,
                'client_time_entry_id' => $entryId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame('completed', $summary['status']);
        $this->assertSame(0, $summary['counts']['failure_reasons']['time_link_destination_claims_another_line'] ?? 0);
        $this->assertSame(1, $summary['link_counts']['idempotent']);
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->unbilled()->count());
    }

    /**
     * The column is indexed but not constrained, so nothing below the
     * application stops two tasks holding one milestone line. A reader that
     * found it free a statement earlier can be overtaken, so the write asks.
     */
    public function test_a_milestone_line_taken_between_the_check_and_the_write_is_not_taken_twice(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec("INSERT INTO client_tasks VALUES (19, 13, 'The task that gets there first', 'Assigned mid-run', NULL, '2026-01-05', '2026-01-06', NULL, NULL)");
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (181, 11, NULL, 'SYN-181', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (182, 181, NULL, NULL, 'Superseded line for task 14', '1', '2500.00', '2500.00', 'milestone', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (183, 181, NULL, NULL, 'The one line that survived', '1', '2500.00', '2500.00', 'milestone', 2, NULL)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 182, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');

        // Somebody assigns the surviving line to task 19 at the moment the
        // availability check has just found it free, so only the write itself
        // can still catch it.
        DB::listen(function ($query) use ($workspace): void {
            static $done = false;
            // The destination check, not the source read of the same shape:
            // only the destination one is workspace-scoped.
            if ($done || ! str_contains($query->sql, 'exists')
                || ! str_contains($query->sql, 'client_tasks')
                || ! str_contains($query->sql, 'client_invoice_line_id')
                || ! str_contains($query->sql, 'workspace_id')) {
                return;
            }
            $done = true;
            $line = ClientInvoiceLine::query()->where('workspace_id', $workspace->getKey())->value('id');
            $other = ClientTask::query()->where('workspace_id', $workspace->getKey())
                ->whereNull('client_invoice_line_id')->orderByDesc('id')->value('id');
            if ($line !== null && $other !== null) {
                DB::table('client_tasks')->where('id', $other)->update(['client_invoice_line_id' => $line]);
            }
        });

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['counts']['failure_reasons']['milestone_link_taken_by_another_task'] ?? 0);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertSame(
            1,
            ClientTask::query()->where('workspace_id', $workspace->getKey())->whereNotNull('client_invoice_line_id')->count(),
            'One deliverable, one line, one holder',
        );
    }

    /**
     * Same type is not the same line. Two retainer draws on one invoice are
     * two pools, and only the words say which - so a replacement whose
     * description says something else is not this line regenerated.
     */
    public function test_a_replacement_whose_description_says_something_else_is_refused(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $this->supersededClaimSource($pdo);
        $pdo->exec("UPDATE client_invoice_lines SET description = 'Work items applied to retainer (10.0000 applied to August 2026 pool)' WHERE client_invoice_line_id = 123");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->count());
    }

    /**
     * The figures in a description move between generations of one line while
     * the words stay put, so they cannot be part of what identifies it.
     */
    public function test_a_replacement_whose_only_difference_is_its_figures_is_still_the_same_line(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->supersededClaimSource(new PDO('sqlite:'.$this->sourcePath));

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['link_counts']['recovered']);
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->unbilled()->count());
    }

    /**
     * A claimant deleted before this run began was never observed, and has no
     * destination row either - so only an unfiltered look at the source can
     * notice that the line it holds is spoken for.
     */
    public function test_a_replacement_held_by_a_deleted_task_is_not_available(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN deleted_at TEXT');
        $pdo->exec("INSERT INTO client_tasks VALUES (20, 13, 'The deleted owner', 'Gone before this run', '2026-01-09', '2026-01-05', '2026-01-06', NULL, NULL, '2026-01-12 09:00:00')");
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (191, 11, NULL, 'SYN-191', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (192, 191, NULL, NULL, 'Milestone', '1', '2500.00', '2500.00', 'milestone', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (193, 191, NULL, NULL, 'Milestone', '1', '2500.00', '2500.00', 'milestone', 2, NULL)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 192, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 193 WHERE id = 20');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(0, $summary['milestone_link_counts']['recovered']);
        $this->assertSame(
            0,
            ClientTask::query()->where('workspace_id', $workspace->getKey())->whereNotNull('client_invoice_line_id')->count(),
            'The deleted task still owns its deliverable',
        );
    }

    /**
     * A retainer description carries the cycle it is for. Deleting every number
     * would make February 2024 and February 2025 the same charge, and attach a
     * stale claim to the wrong year's line.
     */
    public function test_a_replacement_for_a_different_cycle_is_not_the_same_line(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $this->supersededClaimSource($pdo, replacementType: 'retainer', supersededType: 'retainer');
        $pdo->exec("UPDATE client_invoice_lines SET description = 'February 2024 Retainer (10.00) - 2024-02-01 to 2024-02-29' WHERE client_invoice_line_id = 122");
        $pdo->exec("UPDATE client_invoice_lines SET description = 'February 2025 Retainer (12.00) - 2025-02-01 to 2025-02-28' WHERE client_invoice_line_id = 123");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->count());
    }

    /**
     * The same cycle with different hours is the same line regenerated, and
     * must still be recognised as one.
     */
    public function test_a_replacement_for_the_same_cycle_is_the_same_line(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $this->supersededClaimSource($pdo, replacementType: 'retainer', supersededType: 'retainer');
        $pdo->exec("UPDATE client_invoice_lines SET description = 'February 2024 Retainer (10.00) - 2024-02-01 to 2024-02-29' WHERE client_invoice_line_id = 122");
        $pdo->exec("UPDATE client_invoice_lines SET description = 'February 2024 Retainer (12.50) - 2024-02-01 to 2024-02-29' WHERE client_invoice_line_id = 123");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['link_counts']['recovered']);
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->getKey())->unbilled()->count());
    }

    public function test_a_superseded_claim_is_refused_when_the_replacement_changed_since_this_run_read_it(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $this->supersededClaimSource($pdo);

        $first = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        // The replacement now differs from the snapshot this run observed, so
        // it describes a line nobody here has read. A billing link must not be
        // written from one, however plausible the replacement looks.
        $pdo->exec("UPDATE client_invoice_lines SET line_total = '250.00' WHERE client_invoice_line_id = 123");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $first['link_counts']['recovered']);
        $this->assertSame(0, $summary['link_counts']['recovered']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['missing_invoice_time_link_parent'] ?? 0);
        // Refusing to resolve the claim again must not undo the link the run
        // that did read the replacement already established.
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->getKey())->count());
    }

    public function test_a_milestone_naming_a_superseded_line_is_billed_by_its_replacement(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN milestone_price TEXT');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER, deleted_at TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (131, 11, NULL, 'SYN-131', 'issued', '2026-01-10', '2026-02-10', '2500.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (132, 131, NULL, NULL, 'Superseded copy', '1', '2500.00', '2500.00', 'milestone', 1, '2026-01-11 09:00:00')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (133, 131, NULL, NULL, 'Live replacement', '1', '2500.00', '2500.00', 'milestone', 2, NULL)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 132, milestone_price = 2500.00, completed_at = \'2026-01-09\' WHERE id = 14');

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $liveLineId = ClientInvoiceLine::query()->where('workspace_id', $workspace->getKey())->value('id');
        $taskLink = ClientTask::query()->where('workspace_id', $workspace->getKey())->value('client_invoice_line_id');

        $this->assertSame(1, $summary['milestone_link_counts']['recovered']);
        $this->assertSame(1, $summary['milestone_link_counts']['linked']);
        $this->assertNotNull($taskLink);
        $this->assertSame((int) $liveLineId, (int) $taskLink);
    }

    public function test_a_milestone_link_refuses_a_task_owned_by_another_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (131, 11, NULL, 'SYN-131', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (132, 131, NULL, NULL, 'Synthetic milestone line', '1', '100.00', '100.00', 'milestone', 1)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 132 WHERE id = 14');

        app(ExternalImportService::class)->run('external', $owner->slug, true);

        // The inverse of the line-side case: the resolved invoice line belongs
        // to the run workspace and only the task is foreign, so the task check
        // is the only thing that can refuse this.
        $localLine = $this->invoiceLineInWorkspace($other);
        DB::table('external_import_items')
            ->where('source_table', 'client_invoice_lines')
            ->where('source_key', '132')
            ->update(['target_public_id' => $localLine->public_id]);

        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        $this->assertSame(1, $summary['milestone_link_counts']['failed']);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['milestone_link_outside_workspace'] ?? 0);
        // The owning workspace's task keeps the link its own run wrote, and did
        // not get repointed at the line this run resolved.
        $ownerTaskLink = DB::table('client_tasks')->where('workspace_id', $owner->getKey())->value('client_invoice_line_id');
        $this->assertNotNull($ownerTaskLink);
        $this->assertNotSame((int) $localLine->id, (int) $ownerTaskLink);
    }

    public function test_a_billed_time_link_refuses_a_time_entry_owned_by_another_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $this->seedBilledTimeSource();

        app(ExternalImportService::class)->run('external', $owner->slug, true);

        // Line local, entry foreign - only the time-entry check can refuse.
        $localLine = $this->invoiceLineInWorkspace($other);
        DB::table('external_import_items')
            ->where('source_table', 'client_invoice_lines')
            ->where('source_key', '112')
            ->update(['target_public_id' => $localLine->public_id]);

        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        $this->assertSame(1, $summary['link_counts']['failed']);
        $this->assertSame(0, $summary['link_counts']['inserted']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['time_link_outside_workspace'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->where('workspace_id', $other->getKey())->count());
    }

    public function test_a_billed_time_link_refuses_an_invoice_line_owned_by_another_workspace(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $owner = Workspace::create(['name' => 'Owning Workspace', 'slug' => 'owning-workspace']);
        $other = Workspace::create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        $this->seedBilledTimeSource();

        app(ExternalImportService::class)->run('external', $owner->slug, true);

        // Entry local, line foreign - only the invoice-line check can refuse.
        $localEntry = $this->timeEntryInWorkspace($other);
        DB::table('external_import_items')
            ->where('source_table', 'client_time_entries')
            ->where('source_key', '113')
            ->update(['target_public_id' => $localEntry->public_id]);

        $summary = app(ExternalImportService::class)->run('external', $other->slug, true);

        $this->assertSame(1, $summary['link_counts']['failed']);
        $this->assertSame(0, $summary['link_counts']['inserted']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['time_link_outside_workspace'] ?? 0);
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')->where('workspace_id', $other->getKey())->count());
    }

    public function test_a_zero_source_timestamp_does_not_fail_the_row(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        // MySQL's zero date is a legal value there and rejected by a strict
        // destination. Carried verbatim it fails the insert, fails the row, and
        // takes every child that needed this project as a parent with it.
        $pdo->exec("UPDATE client_projects SET created_at = '0000-00-00 00:00:00', updated_at = '' WHERE id = 13");
        // Not just the row's own timestamps: every date the importer carries
        // reads from the same permissive source and lands in the same strict
        // destination.
        $pdo->exec("UPDATE client_tasks SET completed_at = '0000-00-00 00:00:00' WHERE id = 14");
        // An agreement reads its status from these dates the same way, and a
        // zero termination date would retire an agreement that is still live.
        $pdo->exec('CREATE TABLE client_agreements (id INTEGER PRIMARY KEY, client_company_id INTEGER, active_date TEXT, termination_date TEXT, monthly_retainer_hours TEXT, hourly_rate TEXT, billing_cadence TEXT)');
        $pdo->exec("INSERT INTO client_agreements VALUES (51, 11, '2026-01-01', '0000-00-00 00:00:00', '10', '150.00', 'monthly')");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $project = DB::table('client_projects')->where('workspace_id', $workspace->getKey())->first();

        $this->assertSame('completed', $summary['status']);
        $this->assertNotNull($project);
        $this->assertNotNull($project->created_at);
        $this->assertStringStartsNotWith('0000-00-00', (string) $project->created_at);
        // The task below it still arrived, which is what a failed parent costs.
        $this->assertSame(1, DB::table('client_tasks')->where('workspace_id', $workspace->getKey())->count());
        $task = DB::table('client_tasks')->where('workspace_id', $workspace->getKey())->first();
        $this->assertNotNull($task);
        $this->assertNull($task->completed_at);
        // A zero date is a non-empty string, so anything reading the raw value
        // as a yes/no reads yes - and the row ends up saying it completed on no
        // date at all.
        $this->assertSame('open', $task->status);
        $this->assertSame('active', DB::table('client_agreements')->where('workspace_id', $workspace->getKey())->value('status'));
    }

    public function test_imported_rows_keep_the_dates_the_source_recorded(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);

        app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $project = DB::table('client_projects')->where('workspace_id', $workspace->getKey())->first();

        // An imported row dates from when it happened, not from when it was
        // imported; otherwise every migrated record claims to be new.
        $this->assertNotNull($project);
        $this->assertStringStartsWith('2026-01-03', (string) $project->created_at);
        $this->assertStringStartsWith('2026-01-04', (string) $project->updated_at);
    }

    public function test_a_subcontractor_cost_keeps_the_currency_it_was_costed_in(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, project_id INTEGER, client_company_id INTEGER, task_id INTEGER, user_id INTEGER, name TEXT, minutes_worked INTEGER, date_worked TEXT, is_billable INTEGER, is_deferred_billing INTEGER, approval_status TEXT, subcontractor_hourly_rate TEXT, currency TEXT)');
        $pdo->exec("INSERT INTO client_time_entries VALUES (41, 13, 11, NULL, 7, 'Synthetic subcontracted work', 60, '2026-02-02', 1, 0, 'approved', '80.00', 'EUR')");
        // A blank currency is worse than a missing one: the composer skips its
        // cross-currency refusal when the cost currency is empty, so a rate of
        // unknown denomination would bill as though it were the invoice's.
        $pdo->exec("INSERT INTO client_time_entries VALUES (42, 13, 11, NULL, 7, 'Synthetic blank-currency work', 60, '2026-02-03', 1, 0, 'approved', '90.00', '')");

        app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $entry = DB::table('client_time_entries')->where('workspace_id', $workspace->getKey())
            ->where('description', 'Synthetic subcontracted work')->first();

        // InvoiceLineComposer refuses a cross-currency cost only when this field
        // is set. Left null, an EUR rate bills as that many USD cents and the
        // guard never fires.
        $this->assertNotNull($entry);
        $this->assertSame(8000, (int) $entry->subcontractor_cost_amount);
        $this->assertSame('EUR', $entry->subcontractor_cost_currency);

        $blank = DB::table('client_time_entries')->where('workspace_id', $workspace->getKey())
            ->where('description', 'Synthetic blank-currency work')->first();
        $this->assertNotNull($blank);
        $this->assertNotSame('', (string) $blank->subcontractor_cost_currency);
        $this->assertNotSame('', (string) $blank->currency);
    }

    public function test_a_milestone_link_already_decided_here_is_not_repointed(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('ALTER TABLE client_tasks ADD COLUMN client_invoice_line_id INTEGER');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (93, 11, NULL, 'SYN-93', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (94, 93, NULL, NULL, 'Synthetic milestone line', '1', '100.00', '100.00', 'milestone', 1)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (96, 93, NULL, NULL, 'Synthetic corrected milestone line', '1', '100.00', '100.00', 'milestone', 2)");
        $pdo->exec('UPDATE client_tasks SET client_invoice_line_id = 94 WHERE id = 14');

        app(ExternalImportService::class)->run('external', $workspace->slug, true);

        // Stand in for a correction made here after the first import: the task
        // now points at a different line than the source says.
        $taskId = DB::table('client_tasks')->where('workspace_id', $workspace->getKey())->value('id');
        $correctedLineId = DB::table('client_invoice_lines')->where('workspace_id', $workspace->getKey())
            ->orderByDesc('sort_order')->value('id');
        DB::table('client_tasks')->where('id', $taskId)->update(['client_invoice_line_id' => $correctedLineId]);

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $this->assertSame(1, $summary['milestone_link_counts']['idempotent']);
        $this->assertSame(0, $summary['milestone_link_counts']['linked']);
        $this->assertSame((int) $correctedLineId, (int) DB::table('client_tasks')->where('id', $taskId)->value('client_invoice_line_id'));
    }

    public function test_imported_invoices_carry_their_opening_balances(): void
    {
        $user = User::factory()->create();
        Config::set('external-import.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT, starting_unused_hours TEXT, starting_negative_hours TEXT)');
        $pdo->exec("INSERT INTO client_invoices VALUES (95, 11, NULL, 'SYN-95', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL, '3.50', '1.25')");

        $summary = app(ExternalImportService::class)->run('external', $workspace->slug, true);

        $invoice = DB::table('client_invoices')->where('workspace_id', $workspace->getKey())->first();

        $this->assertSame('completed', $summary['status']);
        $this->assertNotNull($invoice);
        // The repair command fills these for older imports. A fresh onboarding
        // that stored null would disagree with it about what a complete import
        // contains, and the rollover a cycle opens with would start from zero.
        $this->assertSame(3.5, (float) $invoice->starting_unused_hours);
        $this->assertSame(1.25, (float) $invoice->starting_negative_hours);
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

    /** A source carrying one billed time entry linked to one invoice line. */
    private function seedBilledTimeSource(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, project_id INTEGER, client_company_id INTEGER, task_id INTEGER, user_id INTEGER, name TEXT, minutes_worked INTEGER, date_worked TEXT, is_billable INTEGER, is_deferred_billing INTEGER, approval_status TEXT, client_invoice_line_id INTEGER)');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, invoice_total TEXT, currency TEXT, notes TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, line_type TEXT, sort_order INTEGER)');
        $pdo->exec("INSERT INTO client_invoices VALUES (111, 11, NULL, 'SYN-111', 'issued', '2026-01-10', '2026-02-10', '100.00', 'USD', NULL)");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (112, 111, NULL, NULL, 'Synthetic billed line', '1', '100.00', '100.00', 'time', 1)");
        $pdo->exec("INSERT INTO client_time_entries VALUES (113, 13, 11, NULL, 7, 'Synthetic billed work', 60, '2026-01-20', 1, 0, 'approved', 112)");
    }

    /** An invoice line owned entirely by the given workspace. */
    private function invoiceLineInWorkspace(Workspace $workspace): ClientInvoiceLine
    {
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Stranded Line Business',
            'slug' => 'stranded-line-business-'.$workspace->getKey(),
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_company_id' => $company->id,
            'invoice_number' => 'STRANDED-'.$workspace->getKey(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        return ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_invoice_id' => $invoice->id,
            'type' => 'milestone', 'description' => 'Stranded', 'quantity' => '1.0000',
            'unit_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'sort_order' => 0,
        ]);
    }

    /** A time entry owned entirely by the given workspace. */
    private function timeEntryInWorkspace(Workspace $workspace): ClientTimeEntry
    {
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Stranded Time Business',
            'slug' => 'stranded-time-business-'.$workspace->getKey(),
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_company_id' => $company->id,
            'name' => 'Stranded Time Project',
        ]);

        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-01-20',
            'minutes' => 60,
            'description' => 'Stranded work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    /** A company, project and task owned entirely by the given workspace. */
    private function taskInWorkspace(Workspace $workspace): ClientTask
    {
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Stranded Business',
            'slug' => 'stranded-business-'.$workspace->getKey(),
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_company_id' => $company->id,
            'name' => 'Stranded Project',
        ]);

        return ClientTask::query()->create([
            'workspace_id' => $workspace->getKey(),
            'client_project_id' => $project->id,
            'title' => 'Stranded Task',
        ]);
    }

    private function countForWorkspace(string $table, Workspace $workspace): int
    {
        return DB::table($table)->where('workspace_id', $workspace->getKey())->count();
    }
}
