<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Revenue\AgreementLedgerRepository;
use App\Services\Revenue\ProjectionInputsRepository;
use App\Services\Revenue\RecurringRevenueProjector;
use App\Services\Revenue\RevenueActualsRepository;
use App\Services\Revenue\RevenueSummaries;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Revenue\AgreementProjection;
use App\Support\Revenue\ClientRevenue;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueActualsInputs;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueBasis;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRevenueFixtures;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The revenue planning reads over REST and MCP (docs/ux-overhaul.md section
 * 13): for one synthetic fixture, the route, the tool and the DTOs computed
 * directly return the same figures; another workspace, a non-manager and a
 * client of another workspace are not found; and `finance:read` opens none
 * of them.
 */
final class RevenueRestTest extends TestCase
{
    use BuildsRevenueFixtures;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'revenue:read'];

    private const string AS_OF = '2026-11-06';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-06 12:00:00');
    }

    /** @return iterable<string, array{string, string, array<string, string>, array<string, mixed>, Closure(Workspace, RevenueAssumptions, ?int): array<string, mixed>}> */
    public static function reads(): iterable
    {
        $summary = static function (Workspace $workspace, RevenueAssumptions $assumptions, ?int $clientId): array {
            [$inputs, $actuals] = self::inputs($workspace);
            $projection = (new RecurringRevenueProjector)->project($clientId === null ? $inputs : $inputs->forClient($clientId));

            return (new RevenueSummaries)->summary($actuals, $inputs, $projection, $assumptions, $clientId)->toArray();
        };
        $monthly = static function (string $band): Closure {
            return static function (Workspace $workspace, RevenueAssumptions $assumptions, ?int $clientId) use ($band): array {
                [$inputs, $actuals] = self::inputs($workspace);
                $projection = (new RecurringRevenueProjector)->project($clientId === null ? $inputs : $inputs->forClient($clientId));

                return ['assumptions' => $assumptions->toArray(), 'band' => $band, 'months' => (new RevenueSummaries)->monthly($actuals, $projection, $assumptions, $band, $clientId)];
            };
        };
        $projection = static function (Workspace $workspace, RevenueAssumptions $assumptions, ?int $clientId): array {
            [$inputs] = self::inputs($workspace);
            $projection = (new RecurringRevenueProjector)->project($clientId === null ? $inputs : $inputs->forClient($clientId));

            return ['assumptions' => $assumptions->toArray(), 'agreements' => array_map(static fn (AgreementProjection $row): array => $row->toArray($assumptions->includeProjectedOverage), $projection->agreements)];
        };
        $clients = static function (Workspace $workspace, RevenueAssumptions $assumptions, ?int $clientId): array {
            [$inputs, $actuals] = self::inputs($workspace);
            if ($clientId !== null) {
                [$inputs, $actuals] = [$inputs->forClient($clientId), $actuals->forClient($clientId)];
            }
            $roster = (new RevenueSummaries)->clients($actuals, $inputs, $assumptions, app(AgreementLedgerRepository::class)->inForce($workspace, $inputs));

            return ['assumptions' => $assumptions->toArray(), 'clients' => array_map(static fn (ClientRevenue $row): array => $row->toArray(), $roster['clients']), 'totals' => $roster['totals']];
        };

        yield 'summary' => ['revenue.summary', 'summary', ['include_projected_overage' => 'true'], ['include_projected_overage' => true], $summary];
        yield 'summary, collected, no expenses' => ['revenue.summary', 'summary', ['basis' => 'collected', 'include_expenses' => 'false'], ['basis' => 'collected', 'include_expenses' => false], $summary];
        yield 'monthly' => ['revenue.monthly', 'monthly', ['include_projected_overage' => 'true'], ['include_projected_overage' => true], $monthly('all')];
        yield 'monthly, one band' => ['revenue.monthly', 'monthly', ['band' => 'overage_and_other', 'include_projected_overage' => '1'], ['band' => 'overage_and_other', 'include_projected_overage' => true], $monthly('overage_and_other')];
        yield 'projection' => ['revenue.projection', 'projection', ['include_projected_overage' => 'true'], ['include_projected_overage' => true], $projection];
        yield 'clients' => ['revenue.clients', 'clients', [], [], $clients];
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $arguments
     * @param  Closure(Workspace, RevenueAssumptions, ?int): array<string, mixed>  $direct
     */
    #[DataProvider('reads')]
    public function test_rest_mcp_and_the_dtos_return_the_same_figures_for_the_callers_workspace_alone(string $operation, string $segment, array $query, array $arguments, Closure $direct): void
    {
        $mine = $this->revenueWorkspace('mine');
        $theirs = $this->revenueWorkspace('theirs', 7);
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $response = $this->getJson($this->url($mine['workspace'], $segment, $query))->assertOk();
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema(json_decode((string) $response->getContent()), AgentApiResponseSchemaCatalog::forOperation($operation)), $operation);
        $assumptions = new RevenueAssumptions(
            CarbonImmutable::parse(self::AS_OF),
            RevenueBasis::from($arguments['basis'] ?? 'invoiced'),
            $arguments['include_expenses'] ?? true,
            $arguments['include_projected_overage'] ?? false,
            'USD',
        );
        $wire = static fn (array $data): array => json_decode((string) json_encode(['data' => $data]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($wire($direct($mine['workspace'], $assumptions, null)), $response->json());

        $mcp = $this->callTool($this->initialize(), $operation, ['workspace_id' => $mine['workspace']->public_id, ...$arguments]);
        $this->assertFalse($mcp['result']['isError'] ?? false, (string) json_encode($mcp));
        $this->assertSame($response->json(), $mcp['result']['structuredContent']);

        // One client of this workspace narrows the figures; a client of another is not found.
        $narrowed = $this->getJson($this->url($mine['workspace'], $segment, [...$query, 'client_id' => $mine['alpha']->public_id]))->assertOk();
        $this->assertSame($wire($direct($mine['workspace'], $assumptions, $mine['alpha']->id)), $narrowed->json());
        $this->getJson($this->url($mine['workspace'], $segment, [...$query, 'client_id' => $theirs['alpha']->public_id]))->assertNotFound();

        // Another workspace is not found, whatever the caller's role there.
        $this->getJson($this->url($theirs['workspace'], $segment, $query))->assertNotFound();
        $foreign = $this->callTool($this->initialize(), $operation, ['workspace_id' => $theirs['workspace']->public_id, ...$arguments]);
        $this->assertTrue($foreign['result']['isError'] ?? false, (string) json_encode($foreign));

        // A member who does not manage the workspace is not told it has figures.
        $member = User::factory()->create();
        $mine['workspace']->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, self::SCOPES);
        $this->getJson($this->url($mine['workspace'], $segment, $query))->assertNotFound();

        // finance:read does not imply revenue:read.
        $this->actingAsMcp($mine['owner'], ['mcp:use', 'identity:read', 'finance:read', 'billing:read']);
        $this->getJson($this->url($mine['workspace'], $segment, $query))->assertForbidden();
    }

    public function test_the_summary_reports_the_fixtures_figures(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $this->revenueWorkspace('theirs', 7);
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $data = $this->getJson($this->url($mine['workspace'], 'summary', ['include_projected_overage' => 'true']))->assertOk()->json('data');

        // Retainers by the day they sell, hosting by its charge date, overage, the credit and the expense by service month; tax excluded.
        $this->assertSame([783000, 0], [$data['ytd']['amount'], $data['ytd']['prior_amount']]);
        $this->assertSame(783000, $data['ttm']['amount']);
        // Alpha's twelve retainers and four hosting charges, Gamma's last half December, Beta's four quarters from January.
        $this->assertSame(1320000 + 24195 + 1200000, $data['next_12_months']['contracted']);
        // Alpha's trailing average (120000 over six months), every month it stays in force.
        $this->assertSame(12 * 20000, $data['next_12_months']['projected_overage']);
        // November's retainer was sold by October's invoice; Gamma's is still to come.
        $this->assertSame(['month' => '2026-11', 'actual' => 100000, 'remaining_contracted' => 50000, 'projected_overage' => 20000], $data['current_month']);
        $this->assertSame(['outstanding' => 169000, 'invoice_count' => 1, 'overdue_amount' => 169000, 'overdue_count' => 1], $data['open_receivables']);
        $this->assertSame([[$mine['gamma']->public_id, '2026-12-15', 50000]], array_map(static fn (array $change): array => [$change['client_id'], $change['date'], $change['monthly_amount']], $data['changes']['ending']));
        $this->assertSame([[$mine['beta']->public_id, '2027-01-01', 100000]], array_map(static fn (array $change): array => [$change['client_id'], $change['date'], $change['monthly_amount']], $data['changes']['starting']));
        $this->assertSame(['as_of' => '2026-11-06', 'basis' => 'invoiced', 'overage_window_months' => 6, 'minimum_history_months' => 3, 'method_version' => RevenueAssumptions::METHOD_VERSION],
            array_intersect_key($data['assumptions'], array_flip(['as_of', 'basis', 'overage_window_months', 'minimum_history_months', 'method_version'])));
    }

    public function test_the_roster_reports_mrr_and_agreement_health(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $clients = $this->getJson($this->url($mine['workspace'], 'clients'))->assertOk()->json('data.clients');

        $this->assertSame(['Synthetic Alpha mine', 'Synthetic Beta mine', 'Synthetic Gamma mine'], array_column($clients, 'name'));
        // $1,000 a month and the quarterly hosting spread over its three months.
        $this->assertSame([110000, 0, 50000], array_column($clients, 'mrr'));
        $this->assertSame([false, false, true], array_column($clients, 'ending_soon'));
        $this->assertSame(['ok', 10, 0], [$clients[0]['agreements'][0]['ledger_status'], $clients[0]['agreements'][0]['hours_included'], $clients[0]['agreements'][0]['hours_used']]);
        $this->assertSame([], $clients[1]['agreements']);
    }

    public function test_an_invalid_toggle_is_refused_with_its_name(): void
    {
        $mine = $this->revenueWorkspace('mine');
        $this->actingAsMcp($mine['owner'], self::SCOPES);

        $this->getJson($this->url($mine['workspace'], 'summary', ['basis' => 'accrual']))->assertUnprocessable()->assertJsonValidationErrors('basis');
        $this->getJson($this->url($mine['workspace'], 'monthly', ['band' => 'tax']))->assertUnprocessable()->assertJsonValidationErrors('band');
        $this->getJson($this->url($mine['workspace'], 'summary', ['include_expenses' => 'maybe']))->assertUnprocessable()->assertJsonValidationErrors('include_expenses');
    }

    /** @return array{ProjectionInputs, RevenueActualsInputs} */
    private static function inputs(Workspace $workspace): array
    {
        $asOf = CarbonImmutable::parse(self::AS_OF);

        return [app(ProjectionInputsRepository::class)->load($workspace, $asOf), app(RevenueActualsRepository::class)->load($workspace, $asOf)];
    }

    /** @param array<string, string> $query */
    private function url(Workspace $workspace, string $segment, array $query = []): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/revenue/'.$segment.($query === [] ? '' : '?'.http_build_query($query));
    }
}
