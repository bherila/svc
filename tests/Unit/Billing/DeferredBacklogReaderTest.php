<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\DeferredBacklogReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(DeferredBacklogReader::class)]
final class DeferredBacklogReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backlog_and_disclosure_use_settlement_dates_while_the_ledger_stops_at_termination(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic dates', 'slug' => 'synthetic-dates']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic ended terms',
            'status' => 'terminated', 'starts_on' => '2026-01-01', 'ends_on' => '2026-05-31', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
        ]);
        $today = CarbonImmutable::parse('2026-06-15');
        $reader = new DeferredBacklogReader;
        $this->assertSame('2026-06-15', $reader->forAgreement($workspace, $agreement, $today)->through);
        $this->assertSame('2026-06-15', $reader->settlementEnd($workspace, $agreement, $today)->toDateString());
        $this->assertSame('2026-05-31', $reader->segmentEnd($workspace, $agreement, $today)->toDateString());

        $elsewhere = Workspace::query()->create(['name' => 'Synthetic elsewhere', 'slug' => 'synthetic-elsewhere']);
        $this->expectException(LogicException::class);
        $reader->settlementEnd($elsewhere, $agreement, $today);
    }
}
