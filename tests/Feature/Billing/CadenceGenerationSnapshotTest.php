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
use App\Services\Billing\TimeEntrySplitter;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\TimeChangedDuringBilling;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * Cadence generation prices time it reads through its transaction's snapshot,
 * then links that time to the draft's lines under row locks (#718). A time
 * writer takes the maintenance share lock and the row's lock, not the
 * agreement's, so it can commit between the two. The draft must not charge
 * what the time no longer says.
 */
final class CadenceGenerationSnapshotTest extends TestCase
{
    use UsesAProbeDatabase;

    /** @return iterable<string, array{int, int}> */
    public static function cuts(): iterable
    {
        // Fifteen hours against a ten-hour retainer are split at ten; cut to
        // twelve, the split would write the fifteen back.
        yield 'an entry the pool splits' => [900, 720];
        // Five hours fit the pool and are linked whole; cut to three, the line
        // would still charge five.
        yield 'an entry linked whole' => [300, 180];
    }

    #[DataProvider('cuts')]
    public function test_time_cut_after_the_generators_snapshot_is_not_charged_as_it_was(int $before, int $after): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A REPEATABLE READ snapshot in a second session is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('generation_snapshot');
        Artisan::call('migrate', ['--database' => 'generation_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        $barrier = sys_get_temp_dir().'/svc-generation-snapshot-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        $worker = null;
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic generation snapshot', 'slug' => 'synthetic-generation-snapshot']);
            $owner = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic generation client', 'slug' => 'synthetic-generation-client']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic generation project']);
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic monthly retainer', 'status' => 'active',
                'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
                'retainer_minutes' => 600, 'retainer_amount' => 60000, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
            ]);
            $entry = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'user_id' => $owner->id, 'worked_on' => '2026-01-20', 'minutes' => $before, 'description' => 'Synthetic work',
                'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
            ]);

            $worker = new Process(
                [PHP_BINARY, base_path('tests/Fixtures/Billing/generation-snapshot-worker.php'), base64_encode(json_encode([
                    'connection' => config('database.connections.'.DB::getDefaultConnection()),
                    'workspace' => $workspace->id, 'company' => $company->id, 'agreement' => $agreement->id, 'period' => '2026-02-01',
                    'paused' => $paused, 'release' => $release,
                ], JSON_THROW_ON_ERROR))],
                base_path(),
                ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
                null,
                120,
            );
            $worker->start();
            $deadline = microtime(true) + 30;
            while (! is_file($paused) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($paused, $worker->getOutput().$worker->getErrorOutput());

            // Cut, as a time edit commits it.
            ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereKey($entry->id)
                ->update(['minutes' => $after, 'lock_version' => DB::raw('lock_version + 1')]);

            $this->assertTrue(touch($release), 'Could not release the generator.');
            $worker->wait();
            $output = $worker->getOutput().$worker->getErrorOutput();
            $this->assertTrue($worker->isSuccessful(), $output);
            $lines = explode("\n", trim($worker->getOutput()));
            $outcome = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            // Refused, writing nothing: retrying prices the time as it is now.
            $this->assertSame(['outcome' => 'refused', 'class' => TimeChangedDuringBilling::class], array_intersect_key($outcome, ['outcome' => 1, 'class' => 1]), $output);

            DB::purge('generation_snapshot');
            // The split and the lines describe the time as it is now: twelve
            // hours in all, never the fifteen the snapshot showed.
            $minutes = (int) ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sum('minutes');
            $this->assertSame($after, $minutes, 'Generation wrote time that does not exist. '.$output);
            $drafts = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', 'draft')->with('lines.timeEntries')->get();
            if ($outcome['outcome'] !== 'success') {
                $this->assertSame([], $drafts->all(), 'A refused generation left a draft. '.$output);

                return;
            }
            foreach ($drafts as $draft) {
                foreach ($draft->lines as $line) {
                    if ($line->timeEntries->isEmpty()) {
                        continue;
                    }
                    $this->assertSame(
                        round($line->timeEntries->sum('minutes') / 60, 4),
                        round((float) $line->hours, 4),
                        "Line {$line->type} charges hours its time does not have. ".$output,
                    );
                }
            }
            $this->assertSame(round($after / 60, 4), round((float) $drafts->flatMap->lines->filter(fn ($line) => $line->timeEntries->isNotEmpty())->sum('hours'), 4), $output);
        } finally {
            $worker?->stop(0);
            @unlink($paused);
            @unlink($release);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /**
     * Recombination merges a split entry's unbilled fragments back into one,
     * deleting the rest, and decides "unbilled" from the fragments'
     * allocations. Those were an eager load through the snapshot: a fragment
     * allocated after it looked free, was merged away, and its allocation went
     * with it (#718).
     */
    public function test_a_fragment_allocated_after_the_snapshot_is_not_merged_away(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A REPEATABLE READ snapshot in a second session is exercised in the MariaDB lane.');
        }

        $this->bootProbeDatabase('generation_snapshot');
        Artisan::call('migrate', ['--database' => 'generation_snapshot', '--force' => true]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('generation_snapshot');
        Schema::clearResolvedInstance('db.schema');
        $barrier = sys_get_temp_dir().'/svc-generation-snapshot-'.Str::lower(Str::random(16));
        $paused = $barrier.'-paused';
        $release = $barrier.'-release';
        $worker = null;
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic recombination snapshot', 'slug' => 'synthetic-recombination-snapshot']);
            $owner = User::factory()->create();
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic recombination client', 'slug' => 'synthetic-recombination-client']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic recombination project']);
            $root = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'user_id' => $owner->id, 'worked_on' => '2026-01-20', 'minutes' => 900, 'description' => 'Synthetic work',
                'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
            ]);
            ['overflow' => $fragment] = app(TimeEntrySplitter::class)->splitEntry($root->fresh(), 600);
            $invoice = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-HOLDER-1',
                'status' => 'draft', 'invoice_kind' => InvoiceKind::AdHoc->value, 'currency' => 'USD',
                'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'balance_amount' => 0,
            ]);
            $line = ClientInvoiceLine::query()->create([
                'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'type' => InvoiceLineType::AdditionalHours->value,
                'description' => 'Synthetic billed fragment', 'quantity' => '5', 'unit_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 50000,
                'hours' => 5, 'sort_order' => 1,
            ]);

            $worker = new Process(
                [PHP_BINARY, base_path('tests/Fixtures/Billing/generation-snapshot-worker.php'), base64_encode(json_encode([
                    'connection' => config('database.connections.'.DB::getDefaultConnection()),
                    'workspace' => $workspace->id, 'company' => $company->id, 'operation' => 'recombine',
                    'paused' => $paused, 'release' => $release,
                ], JSON_THROW_ON_ERROR))],
                base_path(),
                ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array'],
                null,
                120,
            );
            $worker->start();
            $deadline = microtime(true) + 30;
            while (! is_file($paused) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($paused, $worker->getOutput().$worker->getErrorOutput());

            // The fragment is billed and committed, as an attach would do it.
            $line->timeEntries()->attach($fragment->id, ['workspace_id' => $workspace->id]);

            $this->assertTrue(touch($release), 'Could not release the recombination.');
            $worker->wait();
            $output = $worker->getOutput().$worker->getErrorOutput();
            $this->assertTrue($worker->isSuccessful(), $output);

            DB::purge('generation_snapshot');
            $this->assertNotNull(ClientTimeEntry::query()->where('workspace_id', $workspace->id)->find($fragment->id), 'A billed fragment was merged away. '.$output);
            $this->assertSame([$fragment->id], $line->timeEntries()->pluck('client_time_entries.id')->all(), 'The billed fragment lost its allocation. '.$output);
            $this->assertSame(900, (int) ClientTimeEntry::query()->where('workspace_id', $workspace->id)->sum('minutes'), $output);
        } finally {
            $worker?->stop(0);
            @unlink($paused);
            @unlink($release);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
