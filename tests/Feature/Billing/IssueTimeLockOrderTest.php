<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceFromTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Issue's locks against the writers #661 suspected of taking the same rows in
 * the opposite order, and one more the search found, in two MariaDB sessions.
 *
 * A draft priced with disputed history locks that history first - the rows
 * its held-work basis reads, with the invoice lines and allocations that
 * place them - and its own linked time second. In each case one
 * session is held at the boundary that would let the other overtake it, the
 * other runs until it waits or finishes, and then both run to the end. The
 * two must serialise - one waits for the other's commit - and neither may be
 * rolled back as a deadlock victim.
 *
 * What these found, on MariaDB 10.11, when they were written:
 *
 * - Bulk approval: no inversion. The issue's first phase already holds every
 *   time row of the client it examines, linked or not, so the approval waits.
 *   Should that phase ever lock only the rows it matches, this is where the
 *   deadlock shows.
 * - Ad-hoc rebuild: two deadlocks. The rebuild took no agreement lock, and the
 *   issue's basis join locks allocations before lines, against the registry;
 *   the rebuild now takes the client's agreements first, as a time edit does.
 *   Behind it, the issue's locking reads of its agreement's invoices scanned
 *   the workspace's `(workspace_id, id)` index for want of one naming the
 *   agreement, and locked an unrelated draft's entry that any writer of its
 *   lines needs - the hand-edit case, fixed by that index.
 */
final class IssueTimeLockOrderTest extends TestCase
{
    use UsesAProbeDatabase;

    /**
     * A client approving both kinds of row at once locks them in one
     * statement, by id. With the linked hour older than the disputed one, the
     * approval could take the linked hour while the issue, held between its
     * two phases, holds the disputed one.
     */
    public function test_a_bulk_approval_between_the_issues_two_phases_of_time_locks_waits_rather_than_deadlocks(): void
    {
        $this->race(function (array $world): array {
            return [
                ['mode' => 'issue', 'invoice' => $world['draft']->id, 'pause_on' => 'basis'],
                ['mode' => 'approve', 'company' => $world['company']->id, 'contact' => $world['contact']->id,
                    'time' => [$world['linked']->public_id, $world['disputed']->public_id], 'pause_on' => null],
            ];
        }, function (array $held, array $other, bool $otherWaited, array $world, string $context): void {
            $this->assertTrue($otherWaited, 'The approval reaches a row the issue holds. '.$context);
            $this->assertSame(['outcome' => 'success', 'status' => 'issued', 'paused' => true], $held, $context);
            $this->assertNotSame('unexpected', $other['outcome'], 'Neither session is rolled back as a deadlock victim. '.$context);
            $this->assertSame('issued', $world['draft']->fresh()?->status, $context);
        });
    }

    /**
     * An ad-hoc draft carrying an answered hour - disputed history, so the
     * issue's basis join reaches the ad-hoc draft's line - is rebuilt after a
     * dispute on another of its selections. The rebuild takes no agreement
     * lock: it locks its selected time, then deletes and recreates its lines.
     * Held after its time lock, it holds the answered hour, while the issue's
     * join could hold the ad-hoc line that rebuild is about to delete.
     */
    public function test_an_ad_hoc_rebuild_and_an_issue_whose_basis_reaches_its_lines_wait_rather_than_deadlock(): void
    {
        $this->race(function (array $world): array {
            return [
                ['mode' => 'regenerate', 'invoice' => $world['adHoc']->id, 'entry' => $world['heldOnAdHoc']->id, 'pause_on' => 'selection'],
                ['mode' => 'issue', 'invoice' => $world['draft']->id, 'pause_on' => null],
            ];
        }, function (array $held, array $other, bool $otherWaited, array $world, string $context): void {
            $this->assertTrue($otherWaited, 'The issue reaches a row the rebuild holds. '.$context);
            $this->assertSame(['outcome' => 'success', 'status' => 'draft', 'paused' => true], $held, $context);
            $this->assertNotSame('unexpected', $other['outcome'], 'Neither session is rolled back as a deadlock victim. '.$context);
            $this->assertFalse(DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $world['heldOnAdHoc']->id)->exists(), $context);
        });
    }

    /**
     * The first deadlock that case found, by another writer of the same shape:
     * a hand edit of an unrelated ad-hoc draft holds its row and inserts lines,
     * whose tenant key checks the draft's `(workspace_id, id)` entry, and takes
     * no agreement lock. The issue's locking reads of its agreement's invoices
     * scanned that entry, because no index named the agreement, and locked it
     * on the way to the row the edit holds. Scanned by agreement, they never
     * examine the ad-hoc draft, and the two do not meet.
     */
    public function test_a_hand_edit_of_an_unrelated_draft_and_an_issue_do_not_deadlock(): void
    {
        $this->race(function (array $world): array {
            return [
                ['mode' => 'edit', 'invoice' => $world['adHoc']->id, 'pause_on' => 'invoice'],
                ['mode' => 'issue', 'invoice' => $world['draft']->id, 'pause_on' => null],
            ];
        }, function (array $held, array $other, bool $otherWaited, array $world, string $context): void {
            $this->assertSame(['outcome' => 'success', 'status' => 'draft', 'paused' => true], $held, $context);
            $this->assertSame(['outcome' => 'success', 'status' => 'issued', 'paused' => false], $other, 'Neither session is rolled back as a deadlock victim. '.$context);
        });
    }

    /**
     * @param  callable(array<string, mixed>): array{array<string, mixed>, array<string, mixed>}  $sessions  the held session's input, then the other's
     * @param  callable(array<string, mixed>, array<string, mixed>, bool, array<string, mixed>, string): void  $assert
     */
    private function race(callable $sessions, callable $assert): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row locks across two sessions are exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('issue_time_lock');
        Artisan::call('migrate', ['--database' => 'issue_time_lock', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('issue_time_lock');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-issue-time-lock-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            $world = $this->world();
            [$heldInput, $otherInput] = $sessions($world);
            $heldSession = $this->worker($world['workspace'], $heldInput + ['paused' => $paused, 'release' => $release]);
            $otherSession = $this->worker($world['workspace'], $otherInput + ['paused' => null, 'release' => null]);
            $processes = [$heldSession, $otherSession];
            $heldSession->start();
            $this->awaitFile($heldSession, $paused);
            $otherSession->start();
            // Until the other session waits on a lock the held one has, or
            // finishes. The process is checked often; the lock view at most
            // once a second: MariaDB refreshes `innodb_trx` only after 100ms
            // in which nobody read it, so polling it faster freezes it for
            // every other test on the server too.
            $deadline = microtime(true) + 10;
            $lastLook = 0.0;
            $waits = false;
            while ($otherSession->isRunning() && ! $waits && microtime(true) < $deadline) {
                usleep(10_000);
                if (microtime(true) - $lastLook >= 1.0) {
                    $lastLook = microtime(true);
                    $waits = $this->aSessionWaits();
                }
            }
            $otherWaited = $otherSession->isRunning();
            $this->assertTrue(touch($release), 'Could not release the held session.');
            $heldSession->wait();
            $otherSession->wait();
            $context = $heldSession->getOutput().$heldSession->getErrorOutput().$otherSession->getOutput().$otherSession->getErrorOutput();

            DB::purge('issue_time_lock');
            $assert($this->workerResult($heldSession), $this->workerResult($otherSession), $otherWaited, $world, $context);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            @unlink($paused);
            @unlink($release);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /**
     * A monthly retainer whose October draft bills September: a linked hour,
     * logged first, and a disputed hour logged after it, which the draft holds
     * out and records in its held-work basis. Beside it, an ad-hoc draft of an
     * answered hour - settled history the basis also reads - and an hour
     * whose dispute the ad-hoc draft has not yet been rebuilt for.
     *
     * @return array{workspace: Workspace, company: ClientCompany, contact: User, draft: ClientInvoice, linked: ClientTimeEntry, disputed: ClientTimeEntry, adHoc: ClientInvoice, heldOnAdHoc: ClientTimeEntry}
     */
    private function world(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic issue time locks', 'slug' => 'synthetic-issue-time-locks']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic issue time locks client', 'slug' => 'synthetic-issue-time-locks-client',
            'billing_email' => 'ap-issue-time-locks@synthetic.example.test',
        ]);
        $contact = User::factory()->create(['email' => 'ap-issue-time-locks@synthetic.example.test']);
        ClientCompanyMembership::query()->create([
            'client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic issue time locks project', 'is_visible_to_client' => true]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic retainer', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 600, 'retainer_amount' => 100000,
        ]);
        $hour = fn (string $day, array $extra = []): ClientTimeEntry => ClientTimeEntry::query()->create($extra + [
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $owner->id, 'worked_on' => $day, 'minutes' => 60, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
        $linked = $hour('2026-09-10');
        $answered = $hour('2026-09-12', ['client_dispute_settlement' => 'answered', 'client_dispute_reply' => 'Synthetic reply']);
        $heldOnAdHoc = $hour('2026-09-14');
        $disputed = $hour('2026-09-20', ['client_disputed_at' => '2026-10-02 09:00:00', 'client_dispute_note' => 'Synthetic question']);
        $adHoc = app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SYNTH-LOCK-ADHOC', 'currency' => 'USD'], [$answered->public_id, $heldOnAdHoc->public_id]);
        // Disputed after its selection; its rebuild is the session under test.
        ClientTimeEntry::query()->whereKey($heldOnAdHoc->id)->update(['client_disputed_at' => '2026-10-03 09:00:00', 'client_dispute_note' => 'Synthetic question']);

        CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        try {
            $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-10-01'));
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertInstanceOf(ClientInvoice::class, $draft);
        $this->assertNotNull($draft->held_work_basis, 'The draft records the disputed history it was priced without.');
        $this->assertTrue(DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $linked->id)->exists());
        $this->assertTrue(DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $answered->id)->exists(), 'The ad-hoc draft still carries the answered hour.');
        $this->assertLessThan($disputed->id, $linked->id);

        return compact('workspace', 'company', 'contact', 'draft', 'linked', 'disputed', 'adHoc', 'heldOnAdHoc');
    }

    /** A session of this test's own probe database waits on a row lock - never another test's. */
    private function aSessionWaits(): bool
    {
        return DB::table('information_schema.innodb_trx as trx')
            ->join('information_schema.processlist as session', 'session.id', '=', 'trx.trx_mysql_thread_id')
            ->where('trx.trx_state', 'LOCK WAIT')
            ->where('session.db', config('database.connections.issue_time_lock.database'))
            ->exists();
    }

    /** @param array<string, mixed> $input */
    private function worker(Workspace $workspace, array $input): Process
    {
        $payload = base64_encode(json_encode($input + [
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/issue-time-lock-order-worker.php'), $payload],
            base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            null,
            90,
        );
    }

    private function awaitFile(Process $process, string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
