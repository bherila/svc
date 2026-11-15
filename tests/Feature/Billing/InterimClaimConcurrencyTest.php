<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InterimOverageGenerator;
use App\Support\Billing\InterimLedgerChanged;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * The January and February interim drafts of `InterimClaimValidityTest`,
 * issued at the same time from two MariaDB processes.
 *
 * Each claim is checked against the cycle's charged claims, so two issues of
 * one agreement's interims must not be checked concurrently: each would pass
 * against a cycle the other is about to change. The first is held just after
 * issue() has locked its invoice; the second is started and given time to get
 * through. Serialised on the agreement it cannot, and when it can it finds the
 * first one charged and is refused - as stale when it is February, out of order
 * when it is January.
 */
final class InterimClaimConcurrencyTest extends TestCase
{
    use UsesAProbeDatabase;

    private const COMPETITOR_SECONDS = 5.0;

    /** @return iterable<string, array{string, bool}> */
    public static function orders(): iterable
    {
        yield 'january held, february competing' => ['january', true];
        yield 'february held, january competing' => ['february', false];
    }

    #[DataProvider('orders')]
    public function test_two_interim_claims_of_one_cycle_cannot_both_issue_concurrently(string $heldMonth, bool $competitorMayRegenerate): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Cross-process agreement serialisation is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('interim_race');
        Artisan::call('migrate', ['--database' => 'interim_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('interim_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-interim-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic interim race', 'slug' => 'synthetic-interim-race']);
            $user = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id, 'name' => 'Synthetic interim race client', 'slug' => 'synthetic-interim-race-client',
            ]);
            $project = ClientProject::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic interim race project',
            ]);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic quarterly retainer',
                'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01', 'retainer_minutes' => 600,
                'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 0, 'hourly_rate_amount' => 20000,
                'rollover_months' => 0, 'billing_cadence' => 'quarterly', 'bill_overage_interim' => true,
            ])->fresh();
            foreach (['2024-01-10', '2024-02-10'] as $on) {
                ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                    'user_id' => $user->id, 'worked_on' => $on, 'minutes' => 900, 'description' => 'Synthetic work',
                    'is_billable' => true, 'is_deferred' => false, 'status' => 'approved', 'currency' => 'USD',
                ]);
            }
            $generator = app(InterimOverageGenerator::class);
            $drafts = [
                'january' => $generator->generateInterimOverageInvoice($company, Carbon::parse('2024-01-01'), $agreement->fresh()),
                'february' => $generator->generateInterimOverageInvoice($company, Carbon::parse('2024-02-01'), $agreement->fresh()),
            ];
            $this->assertSame('5.0000', (string) $drafts['january']?->hours_billed_at_rate);
            $this->assertSame('10.0000', (string) $drafts['february']?->hours_billed_at_rate);
            $competingMonth = $heldMonth === 'january' ? 'february' : 'january';

            $held = $this->worker($workspace, $drafts[$heldMonth], $paused, $release);
            $competitor = $this->worker($workspace, $drafts[$competingMonth], null, null);
            $processes = [$held, $competitor];
            $held->start();
            $this->awaitFile($held, $paused);
            $competitor->start();
            $deadline = microtime(true) + self::COMPETITOR_SECONDS;
            while ($competitor->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $competitorFinishedWhileHeld = ! $competitor->isRunning();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $held->wait();
            $competitor->wait();
            $output = $held->getOutput().$held->getErrorOutput().$competitor->getOutput().$competitor->getErrorOutput();

            DB::purge('interim_race');
            $charged = (float) ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('invoice_kind', 'interim_overage')
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->sum('hours_billed_at_rate');
            $context = json_encode(['competitor_finished_while_held' => $competitorFinishedWhileHeld, 'charged_hours' => $charged], JSON_THROW_ON_ERROR).' '.$output;

            $this->assertLessThanOrEqual(10.0, $charged, 'Both interim claims were charged: 15 hours against 10 of excess. '.$context);
            $this->assertFalse($competitorFinishedWhileHeld, 'The competitor got through while the first held the cycle. '.$context);
            $this->assertSame('success', $this->workerResult($held)['outcome'], $context);
            $this->assertSame(['outcome' => 'refused', 'regenerate' => $competitorMayRegenerate], $this->workerResult($competitor), $context);
            $this->assertSame('draft', ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($drafts[$competingMonth]->id)->status, $context);
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
     * A caller whose snapshot predates a time change cannot use it to approve
     * a claim.
     *
     * The claim is checked against a ledger of time built from ordinary reads.
     * Inside an outer transaction - the agent API's receipt transaction - those
     * return a snapshot fixed before issue() ran; if time was cut after it, the
     * stale ledger still shows the old excess. Here January is cut from 15 to
     * 10 hours after the snapshot, which drops the cumulative excess through
     * February from 10 to 5, and February's 10-hour claim must not issue.
     */
    public function test_a_stale_time_snapshot_cannot_approve_an_interim_claim(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Snapshot visibility under REPEATABLE READ is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('interim_race');
        Artisan::call('migrate', ['--database' => 'interim_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('interim_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-interim-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            [$workspace, $company, $agreement, $entries] = $this->cycle();
            $february = app(InterimOverageGenerator::class)->generateInterimOverageInvoice($company, Carbon::parse('2024-02-01'), $agreement->fresh());
            $this->assertSame('10.0000', (string) $february?->hours_billed_at_rate);

            $issuer = $this->worker($workspace, $february, $paused, $release, ['mode' => 'outer-snapshot']);
            $cut = $this->worker($workspace, null, null, null, ['mode' => 'reduce-time', 'entry' => $entries[0]->id, 'minutes' => 600]);
            $processes = [$issuer, $cut];
            $issuer->start();
            $this->awaitFile($issuer, $paused);
            $cut->start();
            $cut->wait();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $issuer->wait();
            $output = $issuer->getOutput().$issuer->getErrorOutput().$cut->getOutput().$cut->getErrorOutput();
            $this->assertSame(['outcome' => 'success'], $this->workerResult($cut), $output);

            DB::purge('interim_race');
            $row = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($february->id);
            $this->assertSame('draft', $row->status, 'Ten hours charged against five of excess. '.$output);
            $this->assertSame(['outcome' => 'refused', 'class' => InterimLedgerChanged::class], $this->workerResult($issuer), $output);
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

    /** @return iterable<string, array{string}> */
    public static function allocationChanges(): iterable
    {
        yield 'the allocation is released' => ['release-allocation'];
        yield 'the allocating line is redated' => ['redate-line'];
    }

    /**
     * The ledger also reads where deferred work was placed - by another
     * invoice's line and its pivot, neither of which a time entry's row
     * records. With a month of rollover, five deferred hours placed in January
     * use the January hours that would otherwise roll into February, so
     * February's fifteen hours leave five of excess and the draft claims them.
     * Released or moved out of the cycle after the caller's snapshot, January's
     * hours roll again and the excess is gone: the stale ledger must not
     * approve a five-hour claim against none (#718).
     */
    #[DataProvider('allocationChanges')]
    public function test_a_stale_allocation_snapshot_cannot_approve_an_interim_claim(string $change): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Snapshot visibility under REPEATABLE READ is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('interim_race');
        Artisan::call('migrate', ['--database' => 'interim_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('interim_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-interim-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            [$workspace, $company, $agreement, $entries] = $this->cycle();
            $agreement->forceFill(['rollover_months' => 1])->save();
            $entries[0]->forceFill(['minutes' => 300])->save();
            $deferred = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $entries[0]->client_project_id,
                'user_id' => $entries[0]->user_id, 'worked_on' => '2024-01-12', 'minutes' => 300, 'description' => 'Synthetic deferred work',
                'is_billable' => true, 'is_deferred' => true, 'status' => 'approved', 'currency' => 'USD',
            ]);
            $line = $this->allocate($workspace, $company, $deferred, '2024-01-31');
            $february = app(InterimOverageGenerator::class)->generateInterimOverageInvoice($company, Carbon::parse('2024-02-01'), $agreement->fresh());
            $this->assertSame('5.0000', (string) $february?->hours_billed_at_rate, 'The fixture no longer places the deferred hours in January.');

            $issuer = $this->worker($workspace, $february, $paused, $release, ['mode' => 'outer-snapshot']);
            $competitor = $this->worker($workspace, null, null, null, ['mode' => $change, 'entry' => $deferred->id, 'line' => $line->id, 'date' => '2024-04-30']);
            $processes = [$issuer, $competitor];
            $issuer->start();
            $this->awaitFile($issuer, $paused);
            $competitor->start();
            $competitor->wait();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $issuer->wait();
            $output = $issuer->getOutput().$issuer->getErrorOutput().$competitor->getOutput().$competitor->getErrorOutput();
            $this->assertSame(['outcome' => 'success'], $this->workerResult($competitor), $output);

            DB::purge('interim_race');
            $row = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($february->id);
            $this->assertSame('draft', $row->status, 'Five hours charged against no excess. '.$output);
            $this->assertSame(['outcome' => 'refused', 'class' => InterimLedgerChanged::class], $this->workerResult($issuer), $output);
            // The premise: against current rows there is no excess to claim.
            $regenerated = app(InterimOverageGenerator::class)->generateInterimOverageInvoice($company->fresh(), Carbon::parse('2024-02-01'), $agreement->fresh());
            $this->assertSame(0.0, (float) ($regenerated->hours_billed_at_rate ?? 0), 'The change left February with excess after all.');
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
     * Time the claim's ledger never reads cannot refuse it. The agreement
     * covers one project; an edit to another project's entry after the
     * snapshot leaves the ledger - and so the claim - exactly as it was.
     */
    public function test_time_outside_the_agreements_scope_does_not_refuse_the_claim(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Snapshot visibility under REPEATABLE READ is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('interim_race');
        Artisan::call('migrate', ['--database' => 'interim_race', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('interim_race');
        Schema::clearResolvedInstance('db.schema');
        $processes = [];
        $barrier = sys_get_temp_dir().'/svc-interim-race-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        try {
            [$workspace, $company, $agreement, $entries] = $this->cycle();
            $agreement->forceFill(['client_project_id' => $entries[0]->client_project_id])->save();
            $elsewhere = ClientProject::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic other project',
            ]);
            $unrelated = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $elsewhere->id,
                'user_id' => $entries[0]->user_id, 'worked_on' => '2024-01-20', 'minutes' => 120, 'description' => 'Synthetic other work',
                'is_billable' => true, 'is_deferred' => false, 'status' => 'approved', 'currency' => 'USD',
            ]);
            $february = app(InterimOverageGenerator::class)->generateInterimOverageInvoice($company, Carbon::parse('2024-02-01'), $agreement->fresh());
            $this->assertSame('10.0000', (string) $february?->hours_billed_at_rate);

            $issuer = $this->worker($workspace, $february, $paused, $release, ['mode' => 'outer-snapshot']);
            $edit = $this->worker($workspace, null, null, null, ['mode' => 'reduce-time', 'entry' => $unrelated->id, 'minutes' => 60]);
            $processes = [$issuer, $edit];
            $issuer->start();
            $this->awaitFile($issuer, $paused);
            $edit->start();
            $edit->wait();
            $this->assertTrue(touch($release), 'Could not release the held issue.');
            $issuer->wait();
            $output = $issuer->getOutput().$issuer->getErrorOutput().$edit->getOutput().$edit->getErrorOutput();

            $this->assertSame('success', $this->workerResult($issuer)['outcome'], 'Refused over time the claim does not read. '.$output);
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

    /** @return array{Workspace, ClientCompany, ClientAgreement, list<ClientTimeEntry>} */
    private function cycle(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic interim race', 'slug' => 'synthetic-interim-race']);
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic interim race client', 'slug' => 'synthetic-interim-race-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic interim race project',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic quarterly retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01', 'retainer_minutes' => 600,
            'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 0, 'hourly_rate_amount' => 20000,
            'rollover_months' => 0, 'billing_cadence' => 'quarterly', 'bill_overage_interim' => true,
        ])->fresh();
        $entries = [];
        foreach (['2024-01-10', '2024-02-10'] as $on) {
            $entries[] = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'user_id' => $user->id, 'worked_on' => $on, 'minutes' => 900, 'description' => 'Synthetic work',
                'is_billable' => true, 'is_deferred' => false, 'status' => 'approved', 'currency' => 'USD',
            ]);
        }

        return [$workspace, $company, $agreement, $entries];
    }

    /** Place deferred work on another invoice's line, dated as given. */
    private function allocate(Workspace $workspace, ClientCompany $company, ClientTimeEntry $entry, string $lineDate): ClientInvoiceLine
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-PLACED-1',
            'status' => 'issued', 'invoice_kind' => InvoiceKind::AdHoc->value, 'issue_date' => $lineDate,
            'service_period_start' => '2024-01-01', 'service_period_end' => $lineDate, 'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'balance_amount' => 0, 'hours_billed_at_rate' => 0,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id,
            'type' => InvoiceLineType::PriorMonthRetainer->value, 'description' => 'Synthetic deferred work applied',
            'quantity' => '0', 'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'hours' => ((int) $entry->minutes) / 60, 'line_date' => $lineDate, 'sort_order' => 1,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);

        return $line;
    }

    /** @param  array<string, int|string>  $extra */
    private function worker(Workspace $workspace, ?ClientInvoice $invoice, ?string $paused, ?string $release, array $extra = []): Process
    {
        $input = base64_encode(json_encode([
            ...$extra,
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'workspace' => $workspace->id,
            'invoice' => $invoice?->id,
            'paused' => $paused,
            'release' => $release,
        ], JSON_THROW_ON_ERROR));

        return new Process(
            [PHP_BINARY, base_path('tests/Fixtures/Billing/interim-issue-race-worker.php'), $input],
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
