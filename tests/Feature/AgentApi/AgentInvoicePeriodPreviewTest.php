<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceOperations;
use App\Services\Billing\ClientInvoicingService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * invoices.preview_period runs generation's own checks and engine and rolls
 * them back (#377), so what it shows is what invoices.generate_period writes.
 */
final class AgentInvoicePeriodPreviewTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read', 'billing:write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 12:00:00');
        config(['app.url' => 'https://svc.synthetic.example.test', 'agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
    }

    /** @return iterable<string, array{array<string, mixed>, string, list<array{string, int}>, string, string}> */
    public static function cadences(): iterable
    {
        // Retainer of 600 minutes; 1500 worked in September fills September's
        // pool and October's catch-up, so the draft bills the rest as overage.
        yield 'monthly' => [
            ['billing_cadence' => 'monthly', 'starts_on' => '2026-01-01', 'retainer_minutes' => 600, 'retainer_amount' => 100000],
            '2026-10-01',
            [['2026-09-10', 900], ['2026-09-20', 600], ['2026-10-02', 60]],
            '2026-09-01',
            '2026-09-30',
        ];
        // The non-monthly engine path, with interim overage billing on, so
        // generation also reconciles the cycle's interim invoices.
        yield 'quarterly with interim overage' => [
            ['billing_cadence' => 'quarterly', 'starts_on' => '2026-01-15', 'period_retainer_minutes' => 600, 'period_retainer_amount' => 300000, 'bill_overage_interim' => true],
            '2026-07-15',
            [['2026-05-10', 400], ['2026-06-10', 400], ['2026-07-20', 60]],
            '2026-04-15',
            '2026-07-14',
        ];
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<array{string, int}>  $work
     */
    #[DataProvider('cadences')]
    public function test_preview_shows_exactly_the_lines_generation_then_writes_and_writes_nothing_itself(array $terms, string $period, array $work, string $serviceStart, string $serviceEnd): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture($terms);
        foreach ($work as [$date, $minutes]) {
            $this->entry($workspace, $company, $project, $date, $minutes);
        }
        $this->actingAsMcp($owner, self::SCOPES);
        $before = $this->databaseFingerprint();

        $preview = $this->getJson($this->previewUrl($workspace, $agreement, $period))->assertOk()->json();
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($preview, AgentApiResponseSchemaCatalog::forOperation('invoices.preview_period')));
        $mcp = $this->callTool($this->initialize(), 'invoices.preview_period', ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'period_start' => $period]);
        $this->assertFalse($mcp['result']['isError'] ?? true, json_encode($mcp));
        $this->assertSame($preview, $mcp['result']['structuredContent']);
        $this->assertSame($before, $this->databaseFingerprint(), 'A preview must leave every table as it found it.');

        $data = $preview['data'];
        $this->assertSame($agreement->public_id, $data['agreement_id']);
        $this->assertSame(AgentApiVersion::for($agreement->fresh()), $data['agreement_version']);
        $this->assertSame($period, $data['period_start']);
        $this->assertSame([$serviceStart, $serviceEnd], [$data['service_period_start'], $data['service_period_end']]);
        $this->assertContains('additional_hours', array_column($data['lines'], 'type'), 'The fixture must exercise an overage line.');
        $this->assertSame($data['total_amount'], array_sum(array_column($data['lines'], 'total_amount')));

        // The version the preview returned is the one generation accepts.
        $id = $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => $data['agreement_version'], 'period_start' => $period, 'confirm' => true], ['Idempotency-Key' => 'after-preview'])
            ->assertCreated()->json('data.id');
        $invoice = $this->getJson($this->base($workspace).'invoices/'.$id)->assertOk()->json('data');
        $this->assertSame($data['lines'], array_map(static function (array $line): array {
            unset($line['id']);

            return $line;
        }, $invoice['lines']));
        foreach (['company_id', 'company_name', 'invoice_kind', 'service_period_start', 'service_period_end', 'currency', 'total_amount', 'issue_date', 'due_date'] as $field) {
            $this->assertSame($invoice[$field], $data[$field], $field);
        }
        $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
        $this->assertSame($period, $draft->cycle_start->toDateString());
        // Every other invoice here is new: nothing existed before the preview.
        $others = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKeyNot($draft->id)->orderBy('id')->get()
            ->map(static fn (ClientInvoice $other): array => [
                'id' => null,
                'change' => 'created',
                'invoice_kind' => $other->invoiceKindValue(),
                'service_period_start' => $other->service_period_start?->toDateString(),
                'service_period_end' => $other->service_period_end?->toDateString(),
                'currency' => $other->currency,
                'total_amount' => $other->total_amount,
            ])->all();
        $this->assertSame($others, $data['other_invoices']);
        $this->assertSame(0, AgentMutationAudit::query()->where('operation', 'invoices.preview_period')->count());
    }

    /**
     * Generation also rewrites drafts that already exist: on a quarterly
     * agreement billing overage in the interim, it strips an unissued interim
     * draft of its claims before building the cadence draft. The preview
     * names that draft, and the figures it shows are the ones it then has.
     */
    public function test_preview_reports_an_existing_draft_that_generation_would_rewrite(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture(['billing_cadence' => 'quarterly', 'starts_on' => '2026-01-15', 'period_retainer_minutes' => 600, 'period_retainer_amount' => 300000, 'bill_overage_interim' => true]);
        $this->entry($workspace, $company, $project, '2026-05-10', 400);
        $this->entry($workspace, $company, $project, '2026-06-10', 400);
        $interim = app(ClientInvoicingService::class)->generateInterimOverageInvoice($company, Carbon::parse('2026-06-01'), $agreement->fresh());
        $this->assertInstanceOf(ClientInvoice::class, $interim);
        $this->assertSame('draft', $interim->status);
        $this->assertGreaterThan(0, $interim->total_amount, 'The fixture must start with an interim draft that bills something.');
        $this->actingAsMcp($owner, self::SCOPES);
        $before = $this->databaseFingerprint();

        $preview = $this->getJson($this->previewUrl($workspace, $agreement, '2026-07-15'))->assertOk()->json('data');
        $this->assertSame($before, $this->databaseFingerprint());
        $reported = collect($preview['other_invoices'])->firstWhere('id', $interim->public_id);
        $this->assertNotNull($reported, json_encode($preview['other_invoices']));
        $this->assertSame('updated', $reported['change']);

        $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => $preview['agreement_version'], 'period_start' => '2026-07-15', 'confirm' => true], ['Idempotency-Key' => 'after-interim-preview'])->assertCreated();
        $rewritten = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($interim->id)->firstOrFail();
        $this->assertNotSame($interim->total_amount, $rewritten->total_amount, 'Generation must actually rewrite the interim draft for this to prove anything.');
        $this->assertSame([
            'id' => $interim->public_id,
            'change' => 'updated',
            'invoice_kind' => $rewritten->invoiceKindValue(),
            'service_period_start' => $rewritten->service_period_start?->toDateString(),
            'service_period_end' => $rewritten->service_period_end?->toDateString(),
            'currency' => $rewritten->currency,
            'total_amount' => $rewritten->total_amount,
        ], $reported);
    }

    public function test_preview_refuses_a_period_that_already_has_a_non_void_invoice_and_reopens_after_void(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture();
        $this->entry($workspace, $company, $project, '2026-09-10', 60);
        $this->actingAsMcp($owner, self::SCOPES);
        $id = $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true], ['Idempotency-Key' => 'first'])
            ->assertCreated()->json('data.id');
        $before = $this->databaseFingerprint();

        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertConflict()
            ->assertJsonPath('message', 'A non-void invoice already exists for this cadence period. Read or regenerate that draft instead.');
        $refusal = $this->callTool($this->initialize(), 'invoices.preview_period', ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'period_start' => '2026-10-01']);
        $this->assertTrue($refusal['result']['isError'] ?? false, json_encode($refusal));
        $this->assertStringContainsString('A non-void invoice already exists for this cadence period.', json_encode($refusal, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $this->databaseFingerprint());

        ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail()->forceFill(['status' => 'void'])->save();
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertOk()->assertJsonPath('data.period_start', '2026-10-01');
    }

    public function test_a_repeat_generate_call_is_a_no_op_and_another_key_is_refused(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture();
        $this->entry($workspace, $company, $project, '2026-09-10', 700);
        $this->actingAsMcp($owner, self::SCOPES);
        $url = $this->base($workspace).'agreements/'.$agreement->public_id.'/invoices';
        $body = ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true];
        $first = $this->postJson($url, $body, ['Idempotency-Key' => 'month-start'])->assertCreated()->json();
        // The audit trail records every attempt, which is its job; nothing
        // else may change.
        $after = $this->databaseFingerprint(['agent_mutation_audits']);

        $this->assertSame($first, $this->postJson($url, $body, ['Idempotency-Key' => 'month-start'])->assertCreated()->json());
        $this->assertSame($after, $this->databaseFingerprint(['agent_mutation_audits']), 'An identical retry replays the draft and writes no invoice state.');
        $this->postJson($url, $body, ['Idempotency-Key' => 'another-agent-run'])->assertConflict();
        $this->assertSame($after, $this->databaseFingerprint(['agent_mutation_audits']), 'Another key for a generated period writes nothing, not even a receipt.');
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $agreement->id)->where('status', '!=', 'void')->count());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'wrong boundary' => [[], '2026-10-02'];
        yield 'future period' => [[], '2026-11-01'];
        yield 'before agreement' => [[], '2025-12-01'];
        yield 'terminated' => [['status' => 'terminated'], '2026-10-01'];
        yield 'one time' => [['billing_cadence' => 'one_time'], '2026-10-01'];
        yield 'after end' => [['ends_on' => '2026-09-30'], '2026-10-01'];
        yield 'not a date' => [[], '2026-10'];
    }

    /** @param  array<string, mixed>  $facts */
    #[DataProvider('refusals')]
    public function test_preview_refuses_what_generation_refuses_without_writing(array $facts, string $start): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        $agreement->forceFill($facts)->save();
        $this->actingAsMcp($owner, self::SCOPES);
        $before = $this->databaseFingerprint(['agent_mutation_audits']);
        $this->getJson($this->previewUrl($workspace, $agreement, $start))->assertUnprocessable();
        $this->postJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices', ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => $start, 'confirm' => true], ['Idempotency-Key' => 'same-refusal'])->assertUnprocessable();
        $this->assertSame($before, $this->databaseFingerprint(['agent_mutation_audits']));
    }

    public function test_an_engine_refusal_reaches_the_caller_and_is_rolled_back(): void
    {
        [$workspace, $owner, $company, $agreement] = $this->fixture();
        ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-OVERLAP', 'invoice_kind' => 'cadence_period', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 0, 'balance_amount' => 0, 'service_period_start' => '2026-09-10', 'service_period_end' => '2026-09-15']);
        $this->actingAsMcp($owner, self::SCOPES);
        $before = $this->databaseFingerprint();
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertUnprocessable()->assertJsonValidationErrors('billing');
        $this->assertSame($before, $this->databaseFingerprint());
        $this->assertSame(0, DB::transactionLevel() - 1, 'The preview must close the transaction it opened.');
    }

    /**
     * The preview's own reads - its guard, its before-and-after comparison and
     * its presentation, as opposed to the cadence engine it calls - each name
     * the workspace. Enumerated from the queries actually issued, so a read
     * added later by primary key alone fails here.
     */
    public function test_every_read_the_preview_issues_itself_is_workspace_scoped(): void
    {
        [$workspace, $owner, $company, $agreement, $project] = $this->fixture();
        $this->entry($workspace, $company, $project, '2026-09-10', 1500);
        $this->actingAsMcp($owner, self::SCOPES);
        $own = [];
        DB::listen(function (QueryExecuted $query) use (&$own): void {
            $frames = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'class');
            if (in_array(ClientInvoicingService::class, $frames, true)) {
                return;
            }
            if (array_intersect([AgentInvoiceOperations::class, AgentInvoicePresenter::class], $frames) !== [] && preg_match('/^\s*select/i', $query->sql) === 1
                && preg_match('/from\s+["`]?(client_\w+)/i', $query->sql) === 1) {
                $own[] = $query->sql;
            }
        });
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertOk();
        $this->assertGreaterThanOrEqual(5, count($own), 'The preview stopped reading through its own queries, so this checks nothing.');
        foreach ($own as $sql) {
            $this->assertMatchesRegularExpression('/workspace_id/', $sql, $sql);
        }
    }

    public function test_preview_is_for_workspace_managers_with_billing_read_in_their_own_workspace(): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        [$foreign, , , $foreignAgreement] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAsMcp($member, self::SCOPES);
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertNotFound();
        $this->assertNotContains('invoices.preview_period', $this->toolNames($this->initialize()));

        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'billing:write']);
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertForbidden();

        $this->actingAsMcp($owner, self::SCOPES);
        $this->getJson($this->base($workspace).'agreements/'.$foreignAgreement->public_id.'/invoices/preview?period_start=2026-10-01')->assertNotFound();
        $this->getJson($this->previewUrl($foreign, $foreignAgreement, '2026-10-01'))->assertNotFound();
        $this->getJson($this->base($workspace).'agreements/'.$agreement->public_id.'/invoices/preview')->assertUnprocessable();
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01').'&confirm=1')->assertUnprocessable();
    }

    public function test_preview_is_a_read_and_stays_available_while_invoice_writes_are_switched_off(): void
    {
        [$workspace, $owner, , $agreement] = $this->fixture();
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'billing:read']);
        $this->getJson($this->previewUrl($workspace, $agreement, '2026-10-01'))->assertOk();
        $session = $this->initialize();
        $this->assertContains('invoices.preview_period', $this->toolNames($session));
        $this->assertNotContains('invoices.generate_period', $this->toolNames($session));
    }

    /**
     * Every table, row for row. A preview is generation rolled back, so the
     * claim it writes nothing is a claim about the whole database, not the
     * invoice tables alone.
     *
     * @param  list<string>  $ignore  tables a write path legitimately appends to
     * @return array<string, string>
     */
    private function databaseFingerprint(array $ignore = []): array
    {
        $fingerprint = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, $ignore, true)) {
                continue;
            }
            $fingerprint[$name] = hash('sha256', json_encode(DB::table($name)->get()->map(static fn (object $row): array => (array) $row)->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all(), JSON_THROW_ON_ERROR));
        }
        ksort($fingerprint);

        return $fingerprint;
    }

    /**
     * @param  array<string, mixed>  $terms
     * @return array{Workspace, User, ClientCompany, ClientAgreement, ClientProject}
     */
    private function fixture(array $terms = []): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Period Preview', 'slug' => 'synthetic-period-preview-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Preview Client', 'slug' => 'synthetic-preview-client', 'billing_email' => 'billing@synthetic.example.test']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Preview Project']);
        $agreement = ClientAgreement::query()->create($terms + ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Retainer', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 100000]);

        return [$workspace, $owner, $company, $agreement, $project];
    }

    private function entry(Workspace $workspace, ClientCompany $company, ClientProject $project, string $date, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->memberships()->where('role', 'owner')->value('user_id'), 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'worked_on' => $date, 'minutes' => $minutes, 'description' => 'Synthetic approved work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD']);
    }

    private function previewUrl(Workspace $workspace, ClientAgreement $agreement, string $period): string
    {
        return $this->base($workspace).'agreements/'.$agreement->public_id.'/invoices/preview?period_start='.$period;
    }

    private function base(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/';
    }
}
