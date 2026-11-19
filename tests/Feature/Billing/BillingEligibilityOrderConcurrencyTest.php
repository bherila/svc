<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\LateWork;
use App\Support\Billing\BillingEligibilityPosition;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** Physical commits in separate MariaDB processes, never auto-increment allocation or elapsed-time guesses. */
final class BillingEligibilityOrderConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    private string $originalConnection;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<string> */
    private array $barriers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Physical two-session eligibility ordering is exercised in the MariaDB lane.');
        }
        $this->bootProbeDatabase('billing_eligibility_concurrency');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'billing_eligibility_concurrency', '--force' => true]));
        $this->originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('billing_eligibility_concurrency');
        Schema::clearResolvedInstance('db.schema');
        $this->travelTo('2026-02-01 12:00:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(0);
        }
        foreach ($this->barriers as $file) {
            @unlink($file);
        }
        if (isset($this->originalConnection)) {
            DB::setDefaultConnection($this->originalConnection);
            Schema::clearResolvedInstance('db.schema');
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function commitOrders(): iterable
    {
        yield 'lower allocated id commits later' => [true];
        yield 'lower allocated id commits first control' => [false];
    }

    #[DataProvider('commitOrders')]
    public function test_sequences_follow_physical_commit_order_when_both_events_are_pending(bool $lowerCommitsLater): void
    {
        [$workspace, $company] = $this->company();
        [$low, $lowReady, $lowRelease] = $this->worker($workspace, ['mode' => 'event', 'company' => $company->id], pause: true);
        [$high, $highReady, $highRelease] = $this->worker($workspace, ['mode' => 'event', 'company' => $company->id], pause: true);
        $low->start();
        $this->awaitFile($low, $lowReady);
        $lowPending = json_decode(file_get_contents($lowReady), true, flags: JSON_THROW_ON_ERROR);
        $high->start();
        $this->awaitFile($high, $highReady);
        $highPending = json_decode(file_get_contents($highReady), true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThan($highPending['event_id'], $lowPending['event_id']);
        $this->assertNull($lowPending['sequence']);
        $this->assertNull($highPending['sequence']);
        $this->assertSame(0, $this->counter($workspace, $company), 'Allocating either event must not reserve commit order.');
        $first = $lowerCommitsLater ? $high : $low;
        $second = $lowerCommitsLater ? $low : $high;
        $this->assertTrue(touch($lowerCommitsLater ? $highRelease : $lowRelease));
        $first->wait();
        $firstResult = $this->workerResult($first);
        $this->assertSame('success', $firstResult['outcome'], json_encode($firstResult));
        $this->assertSame(1, $firstResult['sequence']);
        $this->assertTrue($second->isRunning(), 'The other transaction is still held before its physical commit.');
        $this->assertTrue(touch($lowerCommitsLater ? $lowRelease : $highRelease));
        $second->wait();
        $secondResult = $this->workerResult($second);
        $this->assertSame('success', $secondResult['outcome'], json_encode($secondResult));
        $this->assertSame(2, $secondResult['sequence']);
        $this->assertTrue((new BillingEligibilityPosition($secondResult['event_id'], $secondResult['sequence']))->after(new BillingEligibilityPosition($firstResult['event_id'], $firstResult['sequence'])));
        $this->assertSame(2, $this->counter($workspace, $company));
        $this->assertSame(0, DB::table('client_billing_eligibility_events')->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->whereNull('sequence')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function domainOrders(): iterable
    {
        yield 'issue held before approval' => ['issue'];
        yield 'approval held before issue' => ['approve'];
    }

    #[DataProvider('domainOrders')]
    public function test_approval_and_first_issue_use_their_actual_committed_order_in_one_clock_second(string $heldMode): void
    {
        [$workspace, $company] = $this->company();
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $workspace->memberships()->create(['user_id' => $author->id, 'role' => 'member']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic ordering project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic ordering hourly agreement',
            'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD',
            'hourly_rate_amount' => 10000, 'retainer_minutes' => 0, 'retainer_amount' => 0,
        ]);
        $attributes = ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => '2026-01-10', 'minutes' => 60, 'description' => 'Synthetic ordering work',
            'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD'];
        ClientTimeEntry::query()->create(['status' => 'approved'] + $attributes);
        $entry = ClientTimeEntry::query()->create(['status' => 'draft', 'subcontractor_billing_mode' => 'retainer'] + $attributes);
        $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-02-01'));
        $this->assertInstanceOf(ClientInvoice::class, $draft);
        $inputs = ['company' => $company->id, 'actor' => $owner->id, 'entry' => $entry->id, 'invoice' => $draft->id];
        $otherMode = $heldMode === 'issue' ? 'approve' : 'issue';
        [$held, $ready, $release] = $this->worker($workspace, ['mode' => $heldMode] + $inputs, pause: true);
        [$other, , , $started] = $this->worker($workspace, ['mode' => $otherMode] + $inputs, pause: false);
        $held->start();
        $this->awaitFile($held, $ready);
        $other->start();
        $this->awaitFile($other, $started);
        $otherSession = json_decode(file_get_contents($started), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
        $waited = $this->awaitBlockedOrFinished($other, $otherSession);
        $this->assertTrue(touch($release));
        $held->wait();
        $other->wait();
        $heldResult = $this->workerResult($held);
        $otherResult = $this->workerResult($other);
        $this->assertSame('success', $heldResult['outcome'], json_encode($heldResult));
        $this->assertNotSame('unexpected', $otherResult['outcome'], json_encode($otherResult));
        if ($otherResult['outcome'] === 'refused') {
            $this->assertSame('issue', $otherMode, 'Only a stale issue may refuse; approval must complete.');
            $this->assertMatchesRegularExpression('/changed|regenerat|stale|refresh|rebuild/i', $otherResult['message']);
            $draft = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft->fresh());
            $this->assertInstanceOf(ClientInvoice::class, $draft);
            app(InvoiceLifecycleService::class)->issue($draft, $workspace);
        }
        $entry = $entry->fresh();
        $invoice = $draft->fresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame($entry->approved_at?->format('Y-m-d H:i:s'), $invoice->issued_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($entry->billing_eligibility_event_id);
        $this->assertNotNull($invoice->billing_eligibility_event_id);
        $positions = DB::table('client_billing_eligibility_events')->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->whereIn('id', [$entry->billing_eligibility_event_id, $invoice->billing_eligibility_event_id])->pluck('sequence', 'id');
        $entrySequence = (int) $positions[$entry->billing_eligibility_event_id];
        $issueSequence = (int) $positions[$invoice->billing_eligibility_event_id];
        $entryAfterIssue = $otherResult['outcome'] === 'refused' ? false : ($waited ? $heldMode === 'issue' : $heldMode === 'approve');
        $allocatedToIssued = DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->where('allocation.workspace_id', $workspace->id)->where('allocation.client_time_entry_id', $entry->id)
            ->where('line.workspace_id', $workspace->id)->where('line.client_invoice_id', $invoice->id)->exists();
        $this->assertSame($allocatedToIssued ? 'invoiced' : 'approved', $entry->status);
        if ($entryAfterIssue) {
            $this->assertFalse($allocatedToIssued, 'An approval committed after issue cannot already be on that issued invoice.');
            $this->assertGreaterThan($issueSequence, $entrySequence);
        } else {
            $this->assertLessThan($issueSequence, $entrySequence);
        }
        $late = app(LateWork::class)->ids(ClientTimeEntry::query()->whereKey($entry->id), $agreement, Carbon::parse('2026-03-01'));
        $this->assertSame($entryAfterIssue ? [$entry->id] : [], $late, 'Committed approval/issue order, not equal wall timestamps, decides late eligibility.');
    }

    /** @return array{Workspace, ClientCompany} */
    private function company(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic eligibility race', 'slug' => 'synthetic-eligibility-race']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic eligibility client', 'slug' => 'synthetic-eligibility-client']);

        return [$workspace, $company];
    }

    /** @return iterable<string, array{string}> */
    public static function creationOrders(): iterable
    {
        yield 'issue before creation' => ['issue'];
        yield 'creation before issue control' => ['create'];
    }

    #[DataProvider('creationOrders')]
    public function test_actual_time_creation_and_issue_keep_company_foreign_keys_before_the_final_fence(string $heldMode): void
    {
        [$workspace, $company] = $this->company();
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $workspace->memberships()->create(['user_id' => $author->id, 'role' => 'member']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic concurrent creation']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic creation agreement',
            'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD',
            'hourly_rate_amount' => 10000, 'retainer_minutes' => 0, 'retainer_amount' => 0,
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => '2026-01-10', 'minutes' => 60, 'description' => 'Synthetic preexisting work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ]);
        $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-02-01'));
        $inputs = ['company' => $company->id, 'actor' => $owner->id, 'author' => $author->id, 'project' => $project->id, 'invoice' => $draft->id];
        $otherMode = $heldMode === 'issue' ? 'create' : 'issue';
        [$held, $ready, $release] = $this->worker($workspace, ['mode' => $heldMode] + $inputs, pause: true);
        [$other, , , $started] = $this->worker($workspace, ['mode' => $otherMode] + $inputs, pause: false);
        $held->start();
        $this->awaitFile($held, $ready);
        $other->start();
        $this->awaitFile($other, $started);
        $otherSession = json_decode(file_get_contents($started), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
        $this->assertTrue($this->awaitBlockedOrFinished($other, $otherSession), 'The real company/time foreign-key locks serialize creation and issue.');
        $this->assertTrue(touch($release));
        $held->wait();
        $other->wait();
        $heldResult = $this->workerResult($held);
        $otherResult = $this->workerResult($other);
        $this->assertSame('success', $heldResult['outcome'], json_encode($heldResult));
        $this->assertSame('success', $otherResult['outcome'], json_encode($otherResult));
        $creation = $heldMode === 'create' ? $heldResult : $otherResult;
        $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereKey($creation['entry_id'])->firstOrFail();
        $invoice = $draft->fresh();
        $this->assertSame('approved', $entry->status);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame($entry->created_at?->format('Y-m-d H:i:s'), $invoice->issued_at?->format('Y-m-d H:i:s'));
        $this->assertSame($heldMode === 'issue' ? [$entry->id] : [], app(LateWork::class)->ids(
            ClientTimeEntry::query()->whereKey($entry->id)->unbilled(), $agreement, Carbon::parse('2026-03-01'),
        ));
    }

    private function counter(Workspace $workspace, ClientCompany $company): int
    {
        return (int) DB::table('client_billing_eligibility_counters')->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->value('sequence');
    }

    /** @param array<string, mixed> $input
     * @return array{Process, string, string, string}
     */
    private function worker(Workspace $workspace, array $input, bool $pause): array
    {
        $prefix = sys_get_temp_dir().'/svc-eligibility-order-'.Str::lower(Str::random(16));
        [$started, $ready, $release] = [$prefix.'-started', $prefix.'-ready', $prefix.'-release'];
        array_push($this->barriers, $started, $ready, $release);
        $payload = base64_encode(json_encode($input + ['connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id, 'started' => $started, 'ready' => $pause ? $ready : null, 'release' => $pause ? $release : null], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/Concurrency/billing-eligibility-order-worker.php'), $payload],
            base_path(), ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'], null, 60);
        $this->workers[] = $worker;

        return [$worker, $ready, $release, $started];
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    private function awaitBlockedOrFinished(Process $process, int $sessionId): bool
    {
        $deadline = microtime(true) + 12;
        $lastLook = 0.0;
        while ($process->isRunning() && microtime(true) < $deadline) {
            if (microtime(true) - $lastLook >= 1.0) {
                $lastLook = microtime(true);
                $waits = DB::table('information_schema.innodb_trx as trx')->join('information_schema.processlist as session', 'session.id', '=', 'trx.trx_mysql_thread_id')
                    ->where('session.id', $sessionId)->where('session.db', config('database.connections.billing_eligibility_concurrency.database'))
                    ->where('trx.trx_state', 'LOCK WAIT')->exists();
                if ($waits) {
                    return true;
                }
            }
            usleep(10_000);
        }
        $this->assertFalse($process->isRunning(), 'The peer must finish or have an observed database lock wait before releasing the held transaction.');

        return false;
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
