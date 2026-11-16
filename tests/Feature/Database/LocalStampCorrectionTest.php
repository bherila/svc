<?php

namespace Tests\Feature\Database;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\Database\LocalStamps\LocalStampCorrection;
use App\Support\Database\LocalStamps\LocalStampSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The pre-#712 correction over real rows: what it rewrites, what it leaves,
 * what it reports, and that it stays inside the workspaces whose clock was off
 * UTC.
 */
final class LocalStampCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_listed_column_exists_where_the_correction_reads_it(): void
    {
        foreach (LocalStampSpec::writtenLocalBefore712() as $spec) {
            $needed = array_filter([$spec->tenant, $spec->companion, $spec->mayBeImported ? 'public_id' : null, 'id', ...$spec->columns]);
            foreach ($needed as $column) {
                $this->assertTrue(Schema::hasColumn($spec->table, $column), "{$spec->table}.{$column} is listed but does not exist.");
            }
        }
    }

    public function test_local_stamps_are_corrected_and_the_rest_left_and_reported(): void
    {
        [$pacific, $pacificCompany] = $this->tenant('America/Los_Angeles', 'pacific');
        [$utc, $utcCompany] = $this->tenant('UTC', 'utc');

        // Signed and activated with the wall clock; updated_at, from Eloquent,
        // is the same statement's UTC.
        $signed = $this->agreement($pacific, $pacificCompany, signedAt: '2026-10-01 10:00:00', updatedAt: '2026-10-01 17:00:00');
        // Imported by the retired importer: its source's times, not ours.
        $imported = $this->agreement($pacific, $pacificCompany, signedAt: '2026-10-02 10:00:00', updatedAt: '2026-10-02 17:00:00');
        $run = DB::table('external_import_runs')->insertGetId([
            'public_id' => (string) Str::uuid(), 'workspace_id' => $pacific->id, 'source_connection' => 'synthetic',
            'source_identity_hash' => str_repeat('a', 64), 'mode' => 'apply', 'status' => 'completed',
        ]);
        DB::table('external_import_items')->insert([
            'external_import_run_id' => $run, 'source_connection' => 'synthetic', 'source_identity_hash' => str_repeat('a', 64),
            'source_table' => 'agreements', 'source_key' => '1', 'target_type' => 'agreement', 'target_public_id' => $imported->public_id,
            'source_fingerprint' => str_repeat('b', 64), 'status' => 'imported',
        ]);
        // Written after #712's release: UTC already.
        $released = $this->agreement($pacific, $pacificCompany, signedAt: '2026-11-14 09:00:00', updatedAt: '2026-11-14 09:00:00');
        // The same wall-clock values in a UTC workspace were right all along.
        $elsewhere = $this->agreement($utc, $utcCompany, signedAt: '2026-10-01 10:00:00', updatedAt: '2026-10-01 10:00:00');

        $inEra = $this->activity($pacific, $pacificCompany, '2026-09-15 12:00:00');
        $inBand = $this->activity($pacific, $pacificCompany, '2026-08-30 03:00:00');
        $beforeSwitch = $this->activity($pacific, $pacificCompany, '2026-08-29 20:00:00');

        $report = LocalStampCorrection::run(DB::connection(), LocalStampSpec::writtenLocalBefore712());

        $this->assertSame(['2026-10-01 17:00:00', '2026-10-01 17:00:00', '2026-10-01 17:00:00'], $this->agreementStamps($signed));
        $this->assertSame(['2026-10-02 10:00:00', '2026-10-02 10:00:00', '2026-10-02 17:00:00'], $this->agreementStamps($imported));
        $this->assertSame(['2026-11-14 09:00:00', '2026-11-14 09:00:00', '2026-11-14 09:00:00'], $this->agreementStamps($released));
        $this->assertSame(['2026-10-01 10:00:00', '2026-10-01 10:00:00', '2026-10-01 10:00:00'], $this->agreementStamps($elsewhere));

        $this->assertSame(['2026-09-15 19:00:00', '2026-09-15 19:00:00'], $this->activityStamps($inEra));
        $this->assertSame(['2026-08-30 03:00:00', '2026-08-30 03:00:00'], $this->activityStamps($inBand));
        $this->assertSame(['2026-08-29 20:00:00', '2026-08-29 20:00:00'], $this->activityStamps($beforeSwitch));

        $this->assertSame([
            'client_agreements.activated_at' => 1,
            'client_agreements.signed_at' => 1,
            'client_company_activity.created_at' => 1,
            'client_company_activity.updated_at' => 1,
        ], $report['corrected']);
        $this->assertSame([
            'client_company_activity.created_at' => [$inBand],
            'client_company_activity.updated_at' => [$inBand],
        ], $report['ambiguous']);
    }

    public function test_nothing_is_touched_where_every_workspace_is_on_utc(): void
    {
        [$utc, $company] = $this->tenant('UTC', 'only-utc');
        $agreement = $this->agreement($utc, $company, signedAt: '2026-10-01 10:00:00', updatedAt: '2026-10-01 10:00:00');

        $this->assertSame(['corrected' => [], 'ambiguous' => []], LocalStampCorrection::run(DB::connection(), LocalStampSpec::writtenLocalBefore712()));
        $this->assertSame(['2026-10-01 10:00:00', '2026-10-01 10:00:00', '2026-10-01 10:00:00'], $this->agreementStamps($agreement));
    }

    /** @return array{Workspace, ClientCompany} */
    private function tenant(string $timezone, string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => 'synthetic-'.$slug]);
        $workspace->forceFill(['timezone' => $timezone])->save();
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client '.$slug, 'slug' => 'synthetic-client-'.$slug]);

        return [$workspace, $company];
    }

    private function agreement(Workspace $workspace, ClientCompany $company, string $signedAt, string $updatedAt): ClientAgreement
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement', 'status' => 'draft',
            'currency' => 'USD', 'starts_on' => '2026-09-01', 'retainer_minutes' => 120, 'retainer_amount' => 30000,
            'catch_up_threshold_minutes' => 0, 'hourly_rate_amount' => 12000, 'billing_cadence' => 'monthly',
            'bill_overage_interim' => false, 'rollover_months' => 0,
        ]);
        DB::table('client_agreements')->where('id', $agreement->id)->update(['signed_at' => $signedAt, 'activated_at' => $signedAt, 'updated_at' => $updatedAt]);

        return $agreement;
    }

    /** @return list<string> signed_at, activated_at, updated_at */
    private function agreementStamps(ClientAgreement $agreement): array
    {
        $row = DB::table('client_agreements')->where('id', $agreement->id)->first(['signed_at', 'activated_at', 'updated_at']);

        return [(string) $row->signed_at, (string) $row->activated_at, (string) $row->updated_at];
    }

    private function activity(Workspace $workspace, ClientCompany $company, string $at): int
    {
        return DB::table('client_company_activity')->insertGetId([
            'public_id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'action' => 'synthetic.event', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @return list<string> */
    private function activityStamps(int $id): array
    {
        $row = DB::table('client_company_activity')->where('id', $id)->first(['created_at', 'updated_at']);

        return [(string) $row->created_at, (string) $row->updated_at];
    }
}
