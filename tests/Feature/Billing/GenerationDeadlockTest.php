<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Support\Billing\InvoiceChangedDuringGeneration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class GenerationDeadlockTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_two_project_drafts_can_regenerate_while_one_has_detached_its_first_allocation(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Two-session next-key locking is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('generation_snapshot');
        Artisan::call('migrate', ['--database' => 'generation_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        $barrier = sys_get_temp_dir().'/svc-generation-snapshot-'.Str::lower(Str::random(16));
        $workers = [];
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic regeneration', 'slug' => 'synthetic-regeneration']);
            $owner = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $agreements = [];
            foreach (['A', 'B'] as $name) {
                $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic '.$name]);
                $agreement = ClientAgreement::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                    'title' => 'Synthetic '.$name, 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly',
                    'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 60000,
                    'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
                ]);
                ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                    'user_id' => $owner->id, 'worked_on' => '2026-01-20', 'minutes' => 900, 'description' => 'Synthetic work',
                    'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
                ]);
                app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-02-01'));
                $agreements[] = $agreement;
            }
            foreach ([1, 0] as $index) {
                $input = [
                    'connection' => config('database.connections.generation_snapshot'),
                    'workspace' => $workspace->id, 'company' => $company->id, 'agreement' => $agreements[$index]->id,
                    'period' => '2026-02-01', 'pause_on' => $index === 1 ? 'delete from `client_invoice_line_time_entries`' : 'never',
                    'paused' => $barrier.'-'.$index.'-paused', 'release' => $barrier.'-'.$index.'-release',
                ];
                $workers[$index] = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/generation-snapshot-worker.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'], null, 90);
            }
            $workers[1]->start();
            $deadline = microtime(true) + 30;
            while (! is_file($barrier.'-1-paused') && $workers[1]->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($barrier.'-1-paused', $workers[1]->getOutput().$workers[1]->getErrorOutput());
            $workers[0]->start();
            // Wait for a real database lock wait, not a scheduler-dependent
            // assertion that a process is still alive after a fixed delay.
            $waiting = false;
            $deadline = microtime(true) + 15;
            while ($workers[0]->isRunning() && microtime(true) < $deadline) {
                $waiting = DB::selectOne('select count(*) as n from information_schema.innodb_trx where trx_mysql_thread_id != connection_id() and trx_state = ? and trx_query like ?', ['LOCK WAIT', '%client_invoice%'])->n > 0;
                if ($waiting) {
                    break;
                }
                usleep(10_000);
            }
            $competitorProgressed = $waiting || ! $workers[0]->isRunning();
            touch($barrier.'-1-release');
            foreach ($workers as $worker) {
                $worker->wait();
                $output = $worker->getOutput().$worker->getErrorOutput();
                $this->assertTrue($worker->isSuccessful(), $output);
                $lines = explode("\n", trim($worker->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('success', $result['outcome'], $output);
            }
            $this->assertTrue($competitorProgressed, 'The competitor neither completed nor reached a real lock wait.');
            DB::purge('generation_snapshot');
            $this->assertSame(1800, (int) ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sum('minutes'));
            $this->assertSame(4, DB::table('client_invoice_line_time_entries')->where('workspace_id', $workspace->id)->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0);
            }
            foreach (glob($barrier.'-*') ?: [] as $file) {
                @unlink($file);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_an_interim_issue_and_selected_time_creation_do_not_invert_invoice_and_time_locks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Invoice range locks require the MariaDB lane.');
        }
        $this->bootProbeDatabase('generation_snapshot');
        Artisan::call('migrate', ['--database' => 'generation_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        $barrier = sys_get_temp_dir().'/svc-generation-snapshot-'.Str::lower(Str::random(16));
        $workers = [];
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic selected race', 'slug' => 'synthetic-selected-race']);
            $owner = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'title' => 'Synthetic quarterly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'quarterly',
                'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 60000,
                'rollover_months' => 0, 'catch_up_threshold_minutes' => 0, 'bill_overage_interim' => true,
            ])->fresh();
            $entry = null;
            foreach (['2026-01-20' => 900, '2026-03-15' => 60] as $on => $minutes) {
                $entry = ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                    'user_id' => $owner->id, 'worked_on' => $on, 'minutes' => $minutes, 'description' => 'Synthetic work',
                    'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
                ]);
            }
            $draft = app(InterimOverageGenerator::class)->generateInterimOverageInvoice($company, Carbon::parse('2026-01-01'), $agreement);
            $this->assertNotNull($draft);
            // A later invoice keeps the excluded draft out of the terminal
            // range: the issuer also locks the gap after this last row.
            ClientInvoice::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'invoice_number' => 'SYN-LATER-1', 'invoice_kind' => 'ad_hoc', 'status' => 'draft', 'currency' => 'USD',
            ]);
            $draft = app(InterimOverageGenerator::class)->generateInterimOverageInvoice($company, Carbon::parse('2026-01-01'), $agreement, null, $draft);
            foreach (['issue', 'selected'] as $operation) {
                $input = [
                    'connection' => config('database.connections.generation_snapshot'),
                    'workspace' => $workspace->id, 'company' => $company->id, 'agreement' => $agreement->id,
                    'operation' => $operation, 'invoice' => $draft->id, 'entry' => $entry->public_id,
                    'pause_on' => $operation === 'issue' ? 'select `id`, `status`, `invoice_kind`' : 'never',
                    'pause_lock_only' => true,
                    'paused' => $barrier.'-'.$operation.'-paused', 'release' => $barrier.'-'.$operation.'-release',
                    'connection_file' => $barrier.'-'.$operation.'-connection',
                ];
                $workers[$operation] = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/generation-snapshot-worker.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'], null, 90);
            }
            $workers['issue']->start();
            $deadline = microtime(true) + 30;
            while (! is_file($barrier.'-issue-paused') && $workers['issue']->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($barrier.'-issue-paused', $workers['issue']->getOutput().$workers['issue']->getErrorOutput());
            $workers['selected']->start();
            $waiting = false;
            $deadline = microtime(true) + 15;
            while ($workers['selected']->isRunning() && microtime(true) < $deadline) {
                if (is_file($barrier.'-selected-connection')) {
                    $thread = (int) file_get_contents($barrier.'-selected-connection');
                    $waiting = DB::selectOne('select count(*) as n from information_schema.processlist where id = ? and command != ? and info like ?', [$thread, 'Sleep', 'insert into `client_invoices`%'])->n > 0;
                    if ($waiting) {
                        break;
                    }
                }
                usleep(10_000);
            }
            $competitorProgressed = $waiting || ! $workers['selected']->isRunning();
            touch($barrier.'-issue-release');
            foreach ($workers as $worker) {
                $worker->wait();
                $output = $worker->getOutput().$worker->getErrorOutput();
                $this->assertTrue($worker->isSuccessful(), $output);
                $lines = explode("\n", trim($worker->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('success', $result['outcome'], $output);
            }
            $this->assertTrue($competitorProgressed, 'The selected creator neither completed nor reached the invoice range.');
            DB::purge('generation_snapshot');
            $this->assertSame('issued', $draft->fresh()->status);
            $this->assertSame(1, $entry->fresh()->invoiceLines()->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0);
            }
            foreach (glob($barrier.'-*') ?: [] as $file) {
                @unlink($file);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function cadences(): iterable
    {
        yield 'monthly' => ['monthly', '2026-02-01'];
        yield 'quarterly' => ['quarterly', '2026-04-01'];
    }

    #[DataProvider('cadences')]
    public function test_generation_reserves_numbering_before_compare_and_set_time_updates(string $cadence, string $period): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Compare-and-set UPDATE row locks require the MariaDB lane.');
        }
        $this->bootProbeDatabase('generation_snapshot');
        Artisan::call('migrate', ['--database' => 'generation_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        $barrier = sys_get_temp_dir().'/svc-generation-snapshot-'.Str::lower(Str::random(16));
        $workers = [];
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic numbering race', 'slug' => 'synthetic-numbering-race']);
            $owner = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'title' => 'Synthetic retainer', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => $cadence,
                'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 60000,
                'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
            ]);
            $entry = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'user_id' => $owner->id, 'worked_on' => '2026-01-20', 'minutes' => 60, 'description' => 'Synthetic draft work',
                'status' => 'draft', 'is_billable' => true, 'is_deferred' => false, 'currency' => 'USD',
            ]);
            foreach (['numbered-time-lock', 'generate'] as $operation) {
                $input = [
                    'connection' => config('database.connections.generation_snapshot'),
                    'workspace' => $workspace->id, 'company' => $company->id, 'agreement' => $agreement->id,
                    'operation' => $operation, 'entry_id' => $entry->id, 'period' => $period,
                    'pause_on' => $operation === 'numbered-time-lock' ? 'from `workspaces`' : 'never',
                    'pause_lock_only' => true,
                    'paused' => $barrier.'-'.$operation.'-paused', 'release' => $barrier.'-'.$operation.'-release',
                    'connection_file' => $barrier.'-'.$operation.'-connection',
                ];
                $workers[$operation] = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/generation-snapshot-worker.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'], null, 90);
            }
            $workers['numbered-time-lock']->start();
            $deadline = microtime(true) + 30;
            while (! is_file($barrier.'-numbered-time-lock-paused') && $workers['numbered-time-lock']->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($barrier.'-numbered-time-lock-paused', $workers['numbered-time-lock']->getOutput().$workers['numbered-time-lock']->getErrorOutput());
            $workers['generate']->start();
            $waiting = false;
            $deadline = microtime(true) + 15;
            while ($workers['generate']->isRunning() && microtime(true) < $deadline) {
                if (is_file($barrier.'-generate-connection')) {
                    $thread = (int) file_get_contents($barrier.'-generate-connection');
                    $waiting = DB::selectOne('select count(*) as n from information_schema.processlist where id = ? and command != ? and info like ?', [$thread, 'Sleep', '%from `workspaces`%for update'])->n > 0;
                    if ($waiting) {
                        break;
                    }
                }
                usleep(10_000);
            }
            touch($barrier.'-numbered-time-lock-release');
            foreach ($workers as $worker) {
                $worker->wait();
                $output = $worker->getOutput().$worker->getErrorOutput();
                $this->assertTrue($worker->isSuccessful(), $output);
                $lines = explode("\n", trim($worker->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('success', $result['outcome'], $output);
            }
            $this->assertTrue($waiting, 'Generation never reached the held numbering row.');
            DB::purge('generation_snapshot');
            $this->assertSame('approved', $entry->fresh()->status);
            $this->assertSame(60, (int) ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sum('minutes'));
            $this->assertSame(1, $entry->fresh()->invoiceLines()->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0);
            }
            foreach (glob($barrier.'-*') ?: [] as $file) {
                @unlink($file);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function replacementLines(): iterable
    {
        yield 'retainer only' => ['replace'];
        yield 'new expense claim' => ['expense'];
        yield 'same maximum id, changed amount' => ['edit'];
        yield 'empty snapshot, first current line' => ['empty'];
    }

    #[DataProvider('replacementLines')]
    public function test_an_older_caller_snapshot_cannot_append_to_a_concurrently_regenerated_draft(string $change): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A caller snapshot across a committed regeneration requires MariaDB.');
        }
        $this->bootProbeDatabase('generation_snapshot');
        Artisan::call('migrate', ['--database' => 'generation_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        $barrier = sys_get_temp_dir().'/svc-generation-snapshot-'.Str::lower(Str::random(16));
        $worker = null;
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic stale regeneration', 'slug' => 'synthetic-stale-regeneration']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'title' => 'Synthetic monthly retainer', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly',
                'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 60000,
                'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
            ]);
            $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-02-01'));
            $this->assertSame(1, $draft->lines()->count());
            if ($change === 'empty') {
                $draft->lines()->delete();
                $draft->recalculateTotals();
            }
            $input = [
                'connection' => config('database.connections.generation_snapshot'),
                'workspace' => $workspace->id, 'company' => $company->id, 'agreement' => $agreement->id,
                'operation' => 'snapshot-generate', 'period' => '2026-02-01', 'invoice' => $draft->id,
                'pause_on' => 'from `client_invoice_lines`',
                'paused' => $barrier.'-paused', 'release' => $barrier.'-release',
            ];
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Billing/generation-snapshot-worker.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'], null, 90);
            $worker->start();
            $deadline = microtime(true) + 30;
            while (! is_file($barrier.'-paused') && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($barrier.'-paused', $worker->getOutput().$worker->getErrorOutput());
            $expense = $change === 'expense' ? ClientExpense::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'spent_on' => '2026-01-15', 'amount' => 12345, 'currency' => 'USD',
                'description' => 'Synthetic concurrent expense', 'status' => 'approved',
            ]) : null;
            if ($change === 'edit') {
                $draft->lines()->update(['unit_amount' => 72000, 'total_amount' => 72000]);
                $draft->recalculateTotals();
                $current = $draft->fresh();
            } else {
                $current = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-02-01'));
            }
            $currentIds = $current->lines()->pluck('id')->all();
            $expenseLine = $expense?->fresh()->client_invoice_line_id;
            $expectedAmount = $change === 'edit' ? 72000 : 60000 + ($change === 'expense' ? 12345 : 0);
            touch($barrier.'-release');
            $worker->wait();
            $output = $worker->getOutput().$worker->getErrorOutput();
            $this->assertTrue($worker->isSuccessful(), $output);
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            DB::purge('generation_snapshot');
            $this->assertSame($currentIds, $draft->lines()->pluck('id')->all(), $output);
            $this->assertSame($expectedAmount, $draft->fresh()->total_amount);
            $this->assertSame($expectedAmount, (int) $draft->lines()->sum('total_amount'));
            if ($expense !== null) {
                $this->assertNotNull($expenseLine);
                $this->assertSame($expenseLine, $expense->fresh()->client_invoice_line_id);
                $this->assertSame('invoiced', $expense->fresh()->status);
            }
            $this->assertSame('refused', $result['outcome'], $output);
            $this->assertSame(InvoiceChangedDuringGeneration::class, $result['class'], $output);
            $this->assertStringContainsString('changed', $result['message']);
        } finally {
            $worker?->stop(0);
            foreach (glob($barrier.'*') ?: [] as $file) {
                @unlink($file);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
