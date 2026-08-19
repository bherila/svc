<?php

namespace Tests\Feature\LegacyMigration;

use App\Models\User;
use App\Models\Workspace;
use App\Services\LegacyMigration\LegacyMigrationService;
use App\Services\LegacyMigration\SyntheticLegacySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class LegacyActivityAndEmailMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-legacy-activity-');
        Config::set('legacy-migration.sources.legacy', [
            'connection' => 'synthetic',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
        app(SyntheticLegacySource::class)->create($this->sourcePath);

        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE client_company_activity (id INTEGER PRIMARY KEY, client_company_id INTEGER, actor_user_id INTEGER, action TEXT, subject_type TEXT, subject_id INTEGER, payload TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, client_company_id INTEGER, client_agreement_id INTEGER, invoice_number TEXT, status TEXT, issue_date TEXT, due_date TEXT, period_start TEXT, period_end TEXT, invoice_total TEXT, currency TEXT, notes TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER, description TEXT, line_type TEXT, quantity TEXT, unit_price TEXT, line_total TEXT, sort_order INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, project_id INTEGER, client_company_id INTEGER, task_id INTEGER, user_id INTEGER, name TEXT, minutes_worked INTEGER, date_worked TEXT, is_billable INTEGER, is_deferred_billing INTEGER, approval_status TEXT, subcontractor_hourly_rate TEXT, currency TEXT, client_invoice_line_id INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE client_invoice_email_deliveries (id INTEGER PRIMARY KEY, client_invoice_id INTEGER, queued_by_user_id INTEGER, status TEXT, mailer TEXT, provider TEXT, provider_message_id TEXT, transport_message_id TEXT, to_recipients TEXT, cc_recipients TEXT, bcc_recipients TEXT, subject TEXT, note TEXT, queued_at TEXT, sent_at TEXT, failed_at TEXT, last_status_checked_at TEXT, last_event TEXT, last_event_at TEXT, last_event_reason TEXT, delivery_events TEXT, provider_response TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO client_company_activity VALUES (21, 11, 7, 'invoice.generated', 'ClientInvoice', 31, '{\"invoice_total\":3750}', '2026-02-01', '2026-02-01')");
        $pdo->exec("INSERT INTO client_invoices VALUES (31, 11, NULL, 'SYN-31', 'paid', '2026-02-01', '2026-03-01', '2026-02-01', '2026-02-28', '3750.00', 'USD', NULL, '2026-02-01', '2026-02-01')");
        $pdo->exec("INSERT INTO client_invoice_lines VALUES (32, 31, NULL, NULL, 'Synthetic billed time', 'time', '1', '3750.00', '3750.00', 1, '2026-02-01', '2026-02-01')");
        $pdo->exec("INSERT INTO client_time_entries VALUES (51, 13, 11, 14, 7, 'Synthetic billed time', 60, '2026-02-01', 1, 0, 'approved', NULL, 'USD', 32, '2026-02-01', '2026-02-01')");
        $pdo->exec("INSERT INTO client_invoice_email_deliveries VALUES (41, 31, 7, 'sent', 'brevo', 'brevo', 'provider-41', 'transport-41', '[\"to@example.test\"]', '[]', '[]', 'Synthetic invoice', NULL, '2026-02-01 10:00:00', '2026-02-01 10:01:00', NULL, '2026-02-01 10:02:00', NULL, NULL, NULL, '[]', '{\"accepted\":true}', '2026-02-01 10:00:00', '2026-02-01 10:02:00')");
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }
        parent::tearDown();
    }

    public function test_activity_and_invoice_delivery_are_migrated_and_idempotent(): void
    {
        $user = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $user->public_id);
        $workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-activity-workspace']);

        $first = app(LegacyMigrationService::class)->run('legacy', $workspace->public_id, true);
        $second = app(LegacyMigrationService::class)->run('legacy', $workspace->public_id, true);

        $this->assertSame(10, $first['counts']['imported']);
        $this->assertSame(1, $first['link_counts']['inserted']);
        $this->assertSame(0, $first['link_counts']['failed']);
        $this->assertSame(1, DB::table('client_company_activity')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('client_invoice_email_deliveries')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, $second['counts']['failed']);
        $this->assertGreaterThanOrEqual(2, $second['counts']['idempotent']);
        $this->assertSame(1, $second['link_counts']['idempotent']);
        $this->assertSame(1, DB::table('client_company_activity')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('client_invoice_email_deliveries')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->id)->count());
    }
}
