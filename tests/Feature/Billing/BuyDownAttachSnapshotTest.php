<?php

namespace Tests\Feature\Billing;

use App\Exceptions\DisputedWorkChanged;
use App\Exceptions\WorkOutsideAgreementSegment;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Approval\DisputeSettlements;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\HeldWorkBasisRecorder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * A buy-down attached to a cadence draft by a caller whose transaction read
 * before the attach locked anything, as the API's mutation executor does, in
 * two MariaDB sessions (docs/ux-overhaul.md 7b″).
 *
 * The attach checks the draft's held-work basis with locking reads, which see
 * the current state. What it writes after the check - the renewed basis and
 * the draft's totals - must come from current reads too: the caller's
 * REPEATABLE READ snapshot can predate a dispute's answer and the rebuild
 * after it, and writing that snapshot back would record a basis a later
 * dispute matches again, or total lines a regeneration has replaced.
 *
 * The world is the fifth review's: 10 retainer hours, no rollover, no
 * threshold; January's 20 hours A; February's 30 hours B; March's draft X
 * reconciling February; and one deferred March hour C to buy down.
 */
final class BuyDownAttachSnapshotTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_a_basis_renewed_after_an_attach_is_the_current_one_not_the_callers_snapshot(): void
    {
        $this->inProbe('basis', function (Workspace $workspace, ClientAgreement $agreement, ClientInvoice $x, ClientTimeEntry $a, User $owner): void {
            // The dispute commits; its rebuild has not run, so X still prices A.
            $this->stampDispute($a);

            $this->attachFromSnapshot($workspace, $agreement, $x, function () use ($workspace, $a, $owner, $x): void {
                // Answered, January unreconciled: A counts again, and the
                // rebuild after the answer prices X from that.
                $fresh = $a->fresh();
                app(DisputeSettlements::class)->settleTime($workspace, $fresh->public_id, $owner, DisputeSettlement::Answered, 'Synthetic reply', AgentApiVersion::for($fresh));
                $this->assertFalse((bool) $a->fresh()->is_deferred);
                $this->assertTrue(app(HeldWorkBasisRecorder::class)->isCurrent($x->fresh()));
            });

            $attached = $x->fresh();
            $this->assertTrue(app(HeldWorkBasisRecorder::class)->isCurrent($attached), "The renewed basis is the answered state the attach checked, not the caller's snapshot of the dispute.");

            // Disputed again, with no rebuild: X was priced while A counted.
            $this->stampDispute($a);
            try {
                app(InvoiceLifecycleService::class)->issue($attached, $workspace);
                $this->fail('X was issued charging capacity A no longer uses.');
            } catch (DisputedWorkChanged $refused) {
                $this->assertSame('disputed_work_changed', $refused->refusalCode());
            }
            $this->assertSame('draft', $x->fresh()->status);
        });
    }

    public function test_totals_after_an_attach_are_the_current_lines_not_the_callers_snapshot(): void
    {
        $this->inProbe('totals', function (Workspace $workspace, ClientAgreement $agreement, ClientInvoice $x, ClientTimeEntry $a): void {
            $before = (int) $x->fresh()->total_amount;

            $this->attachFromSnapshot($workspace, $agreement, $x, function () use ($a, $x, $before): void {
                // The dispute and its rebuild: X is repriced without A.
                $this->stampDispute($a);
                app(ClientInvoicingService::class)->regenerateDraftInvoice($x->fresh());
                $this->assertLessThan($before, (int) $x->fresh()->total_amount, 'Held, A no longer uses February capacity.');
            });

            $attached = $x->fresh();
            $lines = (int) DB::table('client_invoice_lines')->where('workspace_id', $workspace->id)->where('client_invoice_id', $x->id)->sum('total_amount');
            $this->assertSame($lines, (int) $attached->subtotal_amount, "The totals are the current lines', not the replaced ones the caller's snapshot still held.");
            $this->assertSame($lines, (int) $attached->total_amount);
            $this->assertTrue(app(HeldWorkBasisRecorder::class)->isCurrent($attached));
        });
    }

    /**
     * The same older snapshot under an issue. What issue does after its
     * checks reads the lines ordinarily - the time it marks invoiced, the
     * accounting journal, the document - so a snapshot that predates a
     * rebuild of the draft would act on the lines it replaced. The issue
     * refuses instead, and the draft issues from a fresh request.
     */
    public function test_an_issue_from_a_snapshot_older_than_the_lines_is_refused(): void
    {
        $this->inProbe('issue', function (Workspace $workspace, ClientAgreement $agreement, ClientInvoice $x, ClientTimeEntry $a): void {
            $outcome = $this->attachFromSnapshot($workspace, $agreement, $x, function () use ($a, $x): void {
                $this->stampDispute($a);
                app(ClientInvoicingService::class)->regenerateDraftInvoice($x->fresh());
            }, 'issue');

            $this->assertSame('refused', $outcome['outcome']);
            $this->assertSame('This invoice changed after it was loaded. Reload it and issue it again.', $outcome['message']);
            $this->assertSame('draft', $x->fresh()->status);
            $this->assertSame(0, DB::table('accounting_invoice_entries')->where('client_invoice_id', $x->id)->count());

            $issued = app(InvoiceLifecycleService::class)->issue($x->fresh(), $workspace);
            $lines = DB::table('client_invoice_lines')->where('workspace_id', $workspace->id)->where('client_invoice_id', $x->id);
            $charged = DB::table('client_invoice_line_time_entries')->whereIn('client_invoice_line_id', (clone $lines)->pluck('id'))->pluck('client_time_entry_id');
            $this->assertNotEmpty($charged);
            $this->assertSame([], ClientTimeEntry::query()->whereKey($charged)->where('status', '!=', 'invoiced')->pluck('id')->all());
            $journal = DB::table('accounting_invoice_entries')->where('client_invoice_id', $x->id)->get(['payload'])
                ->map(fn ($row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR));
            $this->assertEqualsCanonicalizing((clone $lines)->pluck('public_id')->all(), $journal->pluck('line_id')->all());
            $this->assertSame((int) $issued->total_amount, (int) $journal->sum('total_amount'));
        });
    }

    /**
     * The post-merge review's disclosure: the draft's *Still deferred*
     * statement is frozen at issue from ordinary reads. A deferred hour
     * returned to draft after the caller's snapshot - withdrawn from the client
     * - would be printed as it was. The issue refuses instead, and a fresh one
     * freezes the statement without it.
     */
    public function test_an_issue_from_a_snapshot_older_than_a_withdrawn_deferred_hour_is_refused(): void
    {
        $this->inProbe('withdrawn', function (Workspace $workspace, ClientAgreement $agreement, ClientInvoice $x): void {
            $c = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('is_deferred', true)->sole();
            $listed = fn (): array => array_column((array) ($x->fresh()->deferred_work_statement['entries'] ?? []), 'id');
            $this->assertContains($c->id, $listed(), 'Approved and waiting, the deferred hour is listed.');

            $outcome = $this->attachFromSnapshot($workspace, $agreement, $x, function () use ($c): void {
                ClientTimeEntry::query()->whereKey($c->id)->update(['status' => 'draft', 'lock_version' => DB::raw('lock_version + 1')]);
            }, 'issue');

            $this->assertSame('refused', $outcome['outcome'], json_encode($outcome, JSON_THROW_ON_ERROR));
            $this->assertSame('This invoice changed after it was loaded. Reload it and issue it again.', $outcome['message']);
            $this->assertSame('draft', $x->fresh()?->status);

            app(InvoiceLifecycleService::class)->issue($x->fresh(), $workspace);
            $this->assertSame('issued', $x->fresh()->status);
            $this->assertNotContains($c->id, $listed(), 'The issued statement no longer lists the withdrawn hour.');
        });
    }

    /**
     * Codex's stale handover: the caller's snapshot predates a backdated
     * termination of the agreement and the successor that takes over. The
     * draft still charges February's work at the outgoing rate; the issue's
     * handover check reads the agreements with row locks, sees the successor,
     * and refuses.
     */
    public function test_an_issue_from_a_snapshot_older_than_a_successor_is_refused(): void
    {
        $this->inProbe('handover', function (Workspace $workspace, ClientAgreement $agreement, ClientInvoice $x): void {
            $outcome = $this->attachFromSnapshot($workspace, $agreement, $x, function () use ($workspace, $agreement): void {
                app(AgreementWorkflow::class)->terminate($agreement->fresh(), '2026-02-05');
                ClientAgreement::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $agreement->client_company_id, 'title' => 'Synthetic successor',
                    'status' => 'active', 'starts_on' => '2026-02-06', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 5000,
                    'retainer_minutes' => 0, 'retainer_amount' => 0, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
                ]);
            }, 'issue');

            $this->assertSame('refused', $outcome['outcome'], json_encode($outcome, JSON_THROW_ON_ERROR));
            $this->assertSame(WorkOutsideAgreementSegment::class, $outcome['class']);
            $this->assertSame('draft', $x->fresh()->status);
        });
    }

    /** @param callable(Workspace, ClientAgreement, ClientInvoice, ClientTimeEntry, User): void $test */
    private function inProbe(string $name, callable $test): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A REPEATABLE READ snapshot in a second session is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('buydown_snapshot');
        Artisan::call('migrate', ['--database' => 'buydown_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('buydown_snapshot');
        Schema::clearResolvedInstance('db.schema');
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic snapshot '.$name, 'slug' => 'synthetic-buydown-snapshot-'.$name]);
            $owner = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic snapshot client', 'slug' => 'synthetic-buydown-snapshot-client-'.$name]);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic snapshot project']);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement', 'status' => 'active',
                'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
                'retainer_minutes' => 600, 'retainer_amount' => 60000, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
            ]);
            $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 1200);
            $this->hours($workspace, $company, $project, $owner, '2026-02-10', 1800);
            $c = $this->hours($workspace, $company, $project, $owner, '2026-03-05', 60);
            ClientTimeEntry::query()->whereKey($c->id)->update(['is_deferred' => true]);
            $x = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-03-01'));
            $this->assertNotNull($x->held_work_basis);

            $test($workspace, $agreement, $x, $a, $owner);
        } finally {
            DB::purge('buydown_snapshot');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /**
     * Start the worker, let it fix its snapshot, move the state on, then let
     * it finish.
     *
     * @return array<string, mixed> the worker's outcome
     */
    private function attachFromSnapshot(Workspace $workspace, ClientAgreement $agreement, ClientInvoice $target, callable $meanwhile, string $mode = 'attach'): array
    {
        $barrier = sys_get_temp_dir().'/svc-buydown-snapshot-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        $payload = base64_encode(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id, 'agreement' => $agreement->id, 'invoice' => $target->id,
            'paused' => $paused, 'release' => $release, 'mode' => $mode,
        ], JSON_THROW_ON_ERROR));
        $worker = new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/buy-down-snapshot-worker.php'), $payload],
            base_path(),
            ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
            null,
            120,
        );
        try {
            $worker->start();
            $deadline = microtime(true) + 30;
            while (! is_file($paused) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($paused, $worker->getOutput().$worker->getErrorOutput());

            $meanwhile();

            $this->assertTrue(touch($release), 'Could not release the attach.');
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $outcome = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            if ($mode === 'attach') {
                $this->assertSame('success', $outcome['outcome'], $worker->getOutput());
            }

            return $outcome;
        } finally {
            $worker->stop(0);
            @unlink($paused);
            @unlink($release);
        }
    }

    private function hours(Workspace $workspace, ClientCompany $company, ClientProject $project, User $author, string $day, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
    }

    private function stampDispute(ClientTimeEntry $entry): void
    {
        ClientTimeEntry::query()->where('workspace_id', $entry->workspace_id)->whereKey($entry->id)->update([
            'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic question', 'lock_version' => DB::raw('lock_version + 1'),
        ]);
    }
}
