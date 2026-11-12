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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('2026-06-15', $reader->forAgreements($workspace, collect([$agreement]), $today)[$agreement->id]->through);
        $this->assertSame('2026-06-15', $reader->settlementEnd($workspace, $agreement, $today)->toDateString());
        $this->assertSame('2026-05-31', $reader->segmentEnd($workspace, $agreement, $today)->toDateString());

        // Foreign keys can arrive as numeric strings on a hydrated legacy row.
        $agreement->forceFill(['workspace_id' => (string) $workspace->id, 'client_company_id' => (string) $company->id]);
        $this->assertSame('2026-06-15', $reader->settlementEnd($workspace, $agreement, $today)->toDateString());

        $elsewhere = Workspace::query()->create(['name' => 'Synthetic elsewhere', 'slug' => 'synthetic-elsewhere']);
        $this->expectException(LogicException::class);
        $reader->settlementEnd($elsewhere, $agreement, $today);
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function handovers(): iterable
    {
        yield 'open agreement handed over' => [null, '2026-06-09'];
        yield 'gap after termination before handover' => ['2026-05-31', '2026-05-31'];
    }

    #[DataProvider('handovers')]
    public function test_both_readers_load_the_successor_instead_of_treating_the_segment_as_empty(?string $endsOn, string $capacityThrough): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic handover dates', 'slug' => 'synthetic-handover-dates']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $terms = ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'currency' => 'USD', 'hourly_rate_amount' => 10000];
        $agreement = ClientAgreement::query()->create($terms + [
            'title' => 'Synthetic outgoing', 'status' => $endsOn === null ? 'active' : 'terminated', 'starts_on' => '2026-01-01', 'ends_on' => $endsOn,
        ]);
        ClientAgreement::query()->create($terms + ['title' => 'Synthetic successor', 'status' => 'active', 'starts_on' => '2026-06-10']);
        $today = CarbonImmutable::parse('2026-06-15');
        $reader = new DeferredBacklogReader;

        $this->assertSame($capacityThrough, $reader->segmentEnd($workspace, $agreement, $today)->toDateString());
        $this->assertSame('2026-06-09', $reader->settlementEnd($workspace, $agreement, $today)->toDateString());
        $this->assertSame('2026-06-09', $reader->forAgreements($workspace, collect([$agreement]), $today)[$agreement->id]->through);
    }
}
