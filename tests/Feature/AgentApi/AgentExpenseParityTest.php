<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AgentExpenseParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00 UTC'));
        Storage::fake('local');
        config(['svc.filesystem_disk' => 'local']);
    }

    public function test_rest_and_mcp_share_approval_lifecycle_receipts_and_audits(): void
    {
        [$workspace, $company, $owner, $project] = $this->fixture();
        $expense = $this->expense($workspace, $company, $project);
        $this->actingAsMcp($owner, ['mcp:use', 'clients:read', 'expenses:read', 'expenses:write']);
        $url = $this->base($workspace).'/expenses/'.$expense->public_id;
        $body = ['expected_version' => AgentApiVersion::for($expense)];
        $approved = $this->postJson($url.'/approve', $body, ['Idempotency-Key' => 'synthetic-approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->schema('expenses.approve', $approved->json());
        $session = $this->mcpSession();
        $arguments = ['workspace_id' => $workspace->public_id, 'expense_id' => $expense->public_id, 'idempotency_key' => 'synthetic-approve', ...$body];
        $this->mcpCall('expenses.approve', $arguments, $session)->assertJsonPath('result.structuredContent.data', $approved->json('data'));
        $this->postJson($url.'/approve', $body, ['Idempotency-Key' => 'synthetic-stale-approval'])->assertConflict();
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'expenses.approve', 'arguments' => [...$arguments, 'idempotency_key' => 'synthetic-mcp-stale-approval']]],
            ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame('approved', $expense->fresh()->status);

        $returned = $this->mcpCall('expenses.unapprove', [...$arguments, 'expected_version' => $approved->json('data.version'), 'idempotency_key' => 'synthetic-unapprove'], $session)
            ->assertJsonPath('result.structuredContent.data.status', 'draft')->json('result.structuredContent');
        $this->postJson($url.'/unapprove', ['expected_version' => $approved->json('data.version')], ['Idempotency-Key' => 'synthetic-unapprove'])->assertOk()->assertJsonPath('data', $returned['data']);
        $this->assertNull($expense->fresh()->approved_by_user_id);
        $this->assertNull($expense->fresh()->approved_at);
        foreach (['expenses.approve', 'expenses.unapprove'] as $operation) {
            foreach (['success', 'replay'] as $outcome) {
                $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => $operation, 'outcome' => $outcome]);
            }
        }
        $expense->forceFill(['status' => 'invoiced'])->save();
        foreach (['approve', 'unapprove'] as $operation) {
            $this->postJson($url.'/'.$operation, ['expected_version' => AgentApiVersion::for($expense)], ['Idempotency-Key' => 'synthetic-invoiced-'.$operation])->assertConflict();
        }
    }

    public function test_schedules_create_update_generate_and_replay_across_transports(): void
    {
        [$workspace, $company, $owner, $project] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'clients:read', 'expenses:read', 'expenses:write']);
        $url = $this->base($workspace).'/expense-schedules';
        $clientVersion = $this->getJson($this->base($workspace).'/clients/'.$company->public_id)->assertOk()->json('data.version');
        $this->assertIsString($clientVersion);
        $session = $this->mcpSession();
        $this->mcpCall('clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id], $session)
            ->assertJsonPath('result.structuredContent.data.version', $clientVersion);
        $body = $this->facts() + ['company_id' => $company->public_id, 'project_id' => $project->public_id,
            'starts_on' => '2026-08-31', 'cadence' => 'monthly', 'expected_version' => $clientVersion];
        $created = $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-schedule-create'])->assertCreated();
        $this->schema('expense_schedules.create', $created->json());
        $id = $created->json('data.id');
        $this->mcpCall('expense_schedules.create', ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-schedule-create', ...$body], $session)
            ->assertJsonPath('result.structuredContent.data.id', $id);
        $this->assertDatabaseCount('client_expense_schedules', 1);
        $updated = $this->mcpCall('expense_schedules.update', ['workspace_id' => $workspace->public_id, 'schedule_id' => $id, 'idempotency_key' => 'synthetic-schedule-update',
            ...$this->facts(), 'description' => 'Synthetic updated recurrence', 'expected_version' => $created->json('data.version'), 'active' => true], $session)
            ->assertJsonPath('result.structuredContent.data.project_id', null)->json('result.structuredContent');
        $this->schema('expense_schedules.update', $updated);
        $version = $updated['data']['version'];
        $this->assertNotSame($created->json('data.version'), $version);
        $this->patchJson($url.'/'.$id, $this->facts() + ['active' => true, 'expected_version' => $created->json('data.version')], ['Idempotency-Key' => 'synthetic-stale-update'])->assertConflict();
        $generate = ['expected_version' => $version, 'confirm' => true];
        foreach ([false, 0, 1, '1', 'true', 'yes', 'on', null] as $index => $confirm) {
            $this->postJson($url.'/'.$id.'/generate', [...$generate, 'confirm' => $confirm], ['Idempotency-Key' => 'synthetic-unconfirmed-'.$index])->assertUnprocessable();
            $this->assertDatabaseCount('client_expenses', 0);
            $this->assertDatabaseCount('agent_mutation_receipts', 2);
        }
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'expense_schedules.generate', 'outcome' => 'failed', 'error_category' => 'validation']);
        $generated = $this->postJson($url.'/'.$id.'/generate', $generate, ['Idempotency-Key' => 'synthetic-generate'])->assertOk()->assertJsonPath('generated_count', 2);
        $this->schema('expense_schedules.generate', $generated->json());
        $this->mcpCall('expense_schedules.generate', ['workspace_id' => $workspace->public_id, 'schedule_id' => $id, 'idempotency_key' => 'synthetic-generate', ...$generate], $session)
            ->assertJsonPath('result.structuredContent.generated_count', 2);
        $this->assertDatabaseCount('client_expenses', 2);
        $this->assertSame(['2026-08-31', '2026-09-30'], ClientExpense::query()->orderBy('id')->get()->map(fn (ClientExpense $expense): string => $expense->spent_on->toDateString())->all());
        $this->assertSame(['draft'], ClientExpense::query()->pluck('status')->unique()->values()->all());
        $this->postJson($url.'/'.$id.'/generate', $generate, ['Idempotency-Key' => 'synthetic-stale-generate'])->assertConflict();
        $listed = $this->getJson($url.'?company_id='.$company->public_id)->assertOk()->assertJsonCount(1, 'data');
        $this->schema('expense_schedules.list', $listed->json());
        $this->mcpCall('expense_schedules.list', ['workspace_id' => $workspace->public_id, 'company_id' => $company->public_id], $session)
            ->assertJsonPath('result.structuredContent', $listed->json());
        foreach (['expense_schedules.create', 'expense_schedules.update', 'expense_schedules.generate'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => $operation, 'outcome' => 'success']);
        }
        $company->update(['name' => 'Synthetic changed client']);
        $this->postJson($url, $body, ['Idempotency-Key' => 'synthetic-stale-parent'])->assertConflict();
        $this->patchJson($url.'/'.$id, [...$this->facts(), 'active' => true, 'expected_version' => $generated->json('data.version'), 'cadence' => 'annual'], ['Idempotency-Key' => 'synthetic-anchor-injection'])->assertUnprocessable();
    }

    public function test_receipts_upload_list_download_urls_and_idempotent_content(): void
    {
        [$workspace, $company, $owner, $project] = $this->fixture();
        $expense = $this->expense($workspace, $company, $project);
        $this->actingAsMcp($owner, ['mcp:use', 'clients:read', 'expenses:read', 'expenses:write']);
        $session = $this->mcpSession();
        $args = ['workspace_id' => $workspace->public_id, 'expense_id' => $expense->public_id];
        $prepared = $this->mcpCall('expenses.receipts.upload_url', $args, $session)->json('result.structuredContent');
        $this->schema('expenses.receipts.upload_url', $prepared);
        $this->assertDatabaseCount('client_attachments', 0);
        $uploadUrl = $prepared['data']['upload_url'];
        $version = $prepared['data']['expected_version'];
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('synthetic-receipt.txt', 'Synthetic receipt only.');
        $response = $this->post($uploadUrl, ['expected_version' => $version, 'file' => $file()], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-upload'])->assertCreated();
        $this->schema('expenses.receipts.upload', $response->json());
        $receipt = $response->json('data.id');
        $this->assertNotSame($version, $response->json('expense_version'));
        $this->post($uploadUrl, ['expected_version' => $version, 'file' => $file()], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-upload'])->assertCreated()->assertJsonPath('data.id', $receipt);
        $this->post($uploadUrl, ['expected_version' => $version, 'file' => UploadedFile::fake()->createWithContent('synthetic-receipt.txt', 'Different synthetic bytes.')], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-upload'])->assertConflict();
        $this->post($uploadUrl, ['expected_version' => $version, 'file' => $file()], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-stale-upload'])->assertConflict();
        $this->assertDatabaseCount('client_attachments', 1);
        $listed = $this->getJson($this->base($workspace).'/expenses/'.$expense->public_id.'/receipts')->assertOk()->assertJsonPath('data.0.id', $receipt);
        $this->schema('expenses.receipts.list', $listed->json());
        $this->mcpCall('expenses.receipts.list', $args, $session)->assertJsonPath('result.structuredContent', $listed->json());
        $download = $this->mcpCall('expenses.receipts.download', [...$args, 'receipt_id' => $receipt], $session)->json('result.structuredContent');
        $this->schema('expenses.receipts.download', $download);
        $this->get($download['data']['download_url'])->assertOk()->assertStreamedContent('Synthetic receipt only.');
        $this->get(str_replace($receipt, (string) Str::uuid(), $download['data']['download_url']))->assertForbidden();
        $this->travel(11)->minutes();
        $this->get($download['data']['download_url'])->assertForbidden();
        $this->post($uploadUrl, ['expected_version' => $response->json('expense_version'), 'file' => $file()], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-expired-upload'])->assertForbidden();
        foreach (['success', 'replay'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'expenses.receipts.upload', 'outcome' => $outcome]);
        }
    }

    #[DataProvider('workspaceVisibilityModes')]
    public function test_missing_and_inaccessible_workspaces_have_identical_refusals(array $scopes, bool $writesEnabled): void
    {
        [$workspace, , $owner] = $this->fixture();
        [$foreign, $company] = $this->fixture();
        $expense = $this->expense($foreign, $company);
        $schedule = (new WorkspaceExpenseSchedules($foreign))->create($company, null, $this->newFacts(), BillingCadence::Monthly);
        $missing = (string) Str::uuid();
        config(['app.debug' => false, 'agent_api.writes_enabled' => $writesEnabled]);
        $this->actingAsMcp($owner, $scopes);
        $expensePath = 'expenses/'.$expense->public_id;
        $receiptPath = $expensePath.'/receipts';
        $schedulePath = 'expense-schedules/'.$schedule->public_id;
        foreach ([['POST', $expensePath.'/approve'], ['POST', $expensePath.'/unapprove'],
            ['GET', $receiptPath], ['GET', $receiptPath.'/upload-url'], ['POST', $receiptPath],
            ['GET', $receiptPath.'/'.Str::uuid()], ['GET', $receiptPath.'/'.Str::uuid().'/content'],
            ['GET', 'expense-schedules'], ['POST', 'expense-schedules'], ['PATCH', $schedulePath], ['POST', $schedulePath.'/generate']] as [$method, $path]) {
            $existing = $this->json($method, $this->base($foreign).'/'.$path, [], ['Idempotency-Key' => 'synthetic-hidden-workspace'])->assertNotFound();
            $unknown = $this->json($method, '/api/v1/workspaces/'.$missing.'/'.$path, [], ['Idempotency-Key' => 'synthetic-unknown-workspace'])->assertNotFound();
            $this->assertSame($existing->json(), $unknown->json(), $method.' '.$path);
            $this->assertSame($existing->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_attachments', 0);
    }

    public static function workspaceVisibilityModes(): iterable
    {
        yield 'full scopes' => [['clients:read', 'expenses:read', 'expenses:write'], true];
        yield 'missing scopes' => [['mcp:use'], true];
        yield 'disabled writes' => [['clients:read', 'expenses:read', 'expenses:write'], false];
    }

    public function test_versioned_operations_require_the_scope_that_reads_the_version(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $schedule = (new WorkspaceExpenseSchedules($workspace))->create($company, null, $this->newFacts(), BillingCadence::Monthly);
        $this->actingAsMcp($owner, ['mcp:use', 'expenses:write']);
        $base = $this->base($workspace);
        foreach (['approve', 'unapprove'] as $operation) {
            $this->postJson($base.'/expenses/'.$expense->public_id.'/'.$operation, ['expected_version' => AgentApiVersion::for($expense)],
                ['Idempotency-Key' => 'synthetic-no-read-'.$operation])->assertForbidden();
        }
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts/upload-url')->assertForbidden();
        $this->post($base.'/expenses/'.$expense->public_id.'/receipts', ['expected_version' => AgentApiVersion::for($expense),
            'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic refused bytes.')],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-no-read-upload'])->assertForbidden();
        $body = $this->facts() + ['company_id' => $company->public_id, 'expected_version' => AgentApiVersion::for($company), 'starts_on' => '2026-10-01', 'cadence' => 'monthly'];
        $this->postJson($base.'/expense-schedules', $body, ['Idempotency-Key' => 'synthetic-no-read-create'])->assertForbidden();
        $this->patchJson($base.'/expense-schedules/'.$schedule->public_id, $this->facts() + ['expected_version' => AgentApiVersion::for($schedule), 'active' => true],
            ['Idempotency-Key' => 'synthetic-no-read-update'])->assertForbidden();
        $this->postJson($base.'/expense-schedules/'.$schedule->public_id.'/generate', ['expected_version' => AgentApiVersion::for($schedule), 'confirm' => true],
            ['Idempotency-Key' => 'synthetic-no-read-generate'])->assertForbidden();
        $session = $this->mcpSession();
        $tools = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => []],
            ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result.tools'), 'name');
        foreach (['expenses.approve', 'expenses.unapprove', 'expenses.receipts.upload_url', 'expense_schedules.create', 'expense_schedules.update', 'expense_schedules.generate'] as $name) {
            $this->assertNotContains($name, $tools);
        }
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:write']);
        $this->postJson($base.'/expense-schedules', $body, ['Idempotency-Key' => 'synthetic-client-read-create'])->assertCreated();
    }

    public function test_receipt_byte_io_precedes_mutation_and_parent_locks(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        $ambientLevel = DB::transactionLevel();
        $disk = Storage::disk('local');
        $observed = Mockery::mock($disk);
        foreach (['writeStream', 'move', 'readStream'] as $method) {
            $observed->shouldReceive($method)->once()->andReturnUsing(function (...$arguments) use ($disk, $method, $ambientLevel) {
                $this->assertSame($ambientLevel, DB::transactionLevel(), $method.' must happen before the mutation transaction.');
                $this->assertDatabaseCount('agent_mutation_receipts', 0);

                return $disk->{$method}(...$arguments);
            });
        }
        Storage::shouldReceive('disk')->with('local')->andReturn($observed);
        LockOrderRecorder::start();
        try {
            $this->post($this->base($workspace).'/expenses/'.$expense->public_id.'/receipts', ['expected_version' => AgentApiVersion::for($expense),
                'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic verified bytes.')],
                ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-prepared-upload'])->assertCreated();
            $sequences = array_map(static fn (array $sequence): array => array_map(static fn ($resource): string => $resource->name, $sequence), LockOrderRecorder::sequences());
            $this->assertContains(['AgentMutationReceipt', 'ClientExpense', 'ClientAttachment'], $sequences);
        } finally {
            LockOrderRecorder::stop();
        }
    }

    #[DataProvider('preparationChanges')]
    public function test_receipt_publication_rechecks_state_after_byte_preparation(string $change, int $status): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $this->actingAsMcp($owner, ['expenses:read', 'expenses:write']);
        $disk = Storage::disk('local');
        $observed = Mockery::mock($disk);
        $observed->shouldReceive('readStream')->once()->andReturnUsing(function (string $key) use ($disk, $change, $workspace, $owner, $expense) {
            match ($change) {
                'role' => $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']),
                'version' => $expense->update(['description' => 'Synthetic concurrently changed expense.']),
                'deleted' => $expense->delete(),
                'cutover' => config(['agent_api.expense_writes_enabled' => false]),
            };

            return $disk->readStream($key);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($observed);
        $this->post($this->base($workspace).'/expenses/'.$expense->public_id.'/receipts', ['expected_version' => AgentApiVersion::for($expense),
            'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic interrupted preparation.')],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-prepare-change-'.$change])->assertStatus($status);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'expenses.receipts.upload', 'outcome' => 'failed']);
    }

    public static function preparationChanges(): iterable
    {
        yield 'revoked manager' => ['role', 403];
        yield 'changed expense' => ['version', 409];
        yield 'discarded expense' => ['deleted', 404];
        yield 'disabled cutover' => ['cutover', 404];
    }

    public function test_prepared_receipts_remain_repairable_after_termination_or_publication_rollback(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $storage = app(AttachmentStorageService::class);
        $terminated = $storage->prepareForPublication($workspace, $expense, UploadedFile::fake()->createWithContent('synthetic-first.txt', 'Synthetic terminated upload.'), $owner);
        $rolledBack = $storage->prepareForPublication($workspace, $expense, UploadedFile::fake()->createWithContent('synthetic-second.txt', 'Synthetic rolled back upload.'), $owner);
        try {
            DB::transaction(function () use ($storage, $workspace, $expense, $rolledBack): void {
                $storage->publishPrepared($workspace, $expense, $rolledBack);
                throw new \RuntimeException('Synthetic lost mutation transaction.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic lost mutation transaction.', $exception->getMessage());
        }
        foreach ([$terminated, $rolledBack] as $prepared) {
            $fresh = $prepared->fresh();
            $this->assertSame(ClientAttachment::STATE_STAGED, $fresh->lifecycle_state);
            $this->assertNotNull($fresh->staged_object_key);
            Storage::disk('local')->assertExists($fresh->object_key);
        }
        $this->travel(61)->minutes();
        $counts = $storage->repair(true);
        $this->assertSame(2, $counts['staged_rows']);
        $this->assertSame([], Storage::disk('local')->allFiles());
        foreach ([$terminated, $rolledBack] as $prepared) {
            $this->assertSame(ClientAttachment::STATE_DELETED, $prepared->fresh()->lifecycle_state);
        }
    }

    public function test_failed_preparation_cleanup_retains_recovery_and_publication_checks_parent_facts(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $other = $this->expense($workspace, $company);
        $storage = app(AttachmentStorageService::class);
        $prepared = $storage->prepareForPublication($workspace, $expense, UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic recovery.'), $owner);
        try {
            $storage->publishPrepared($workspace, $other, $prepared);
            $this->fail('Prepared bytes must retain their original parent.');
        } catch (ModelNotFoundException) {
            $this->assertSame(ClientAttachment::STATE_STAGED, $prepared->fresh()->lifecycle_state);
        }
        [$foreign] = $this->fixture();
        try {
            $storage->publishPrepared($foreign, $expense, $prepared);
            $this->fail('Prepared bytes must retain their original workspace.');
        } catch (ModelNotFoundException) {
            $this->assertSame(ClientAttachment::STATE_STAGED, $prepared->fresh()->lifecycle_state);
        }
        $manager = Storage::getFacadeRoot();
        $disk = Storage::disk('local');
        $unavailable = Mockery::mock($disk);
        $unavailable->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($unavailable);
        $storage->discardPreparedUpload($workspace, $prepared);
        Storage::swap($manager);
        $this->assertSame(ClientAttachment::STATE_STAGED, $prepared->fresh()->lifecycle_state);
        Storage::disk('local')->assertExists($prepared->object_key);
        $this->travel(61)->minutes();
        $this->assertSame(1, $storage->repair(true)['staged_rows']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_manager_only_expense_tools_disappear_when_manager_membership_is_revoked(): void
    {
        [$workspace, , $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'expenses:read', 'expenses:write', 'clients:read']);
        $names = ['expenses.approve', 'expenses.unapprove', 'expense_schedules.list', 'expense_schedules.create',
            'expense_schedules.update', 'expense_schedules.generate', 'expenses.receipts.list', 'expenses.receipts.download', 'expenses.receipts.upload_url'];
        $list = function (string $session): array {
            return array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => []],
                ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result.tools'), 'name');
        };
        $session = $this->mcpSession();
        $managerTools = $list($session);
        foreach ($names as $name) {
            $this->assertContains($name, $managerTools);
        }
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $memberTools = $list($session);
        foreach ($names as $name) {
            $this->assertNotContains($name, $memberTools);
        }
    }

    public function test_receipt_publication_is_compensated_when_mutation_commit_fails(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        // Fail after object publication but before the enclosing receipt commits.
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'update') && str_contains($query->sql, 'agent_mutation_receipts')) {
                throw new \RuntimeException('Synthetic commit failure.');
            }
        });
        $this->post($this->base($workspace).'/expenses/'.$expense->public_id.'/receipts', ['expected_version' => AgentApiVersion::for($expense),
            'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic receipt.')], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-failed-publication'])->assertServerError();
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_all_new_routes_refuse_foreign_records_members_and_missing_scopes(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        [$foreign, $otherCompany, $otherOwner] = $this->fixture();
        $expense = $this->expense($foreign, $otherCompany);
        $schedule = (new WorkspaceExpenseSchedules($foreign))->create($otherCompany, null, $this->newFacts(), BillingCadence::Monthly);
        $this->actingAsMcp($owner, ['mcp:use', 'clients:read', 'expenses:read', 'expenses:write']);
        $base = $this->base($workspace);
        foreach (['approve', 'unapprove'] as $operation) {
            $this->postJson($base.'/expenses/'.$expense->public_id.'/'.$operation, ['expected_version' => AgentApiVersion::for($expense)], ['Idempotency-Key' => 'synthetic-foreign-'.$operation])->assertNotFound();
        }
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts')->assertNotFound();
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts/upload-url')->assertNotFound();
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts/'.Str::uuid())->assertNotFound();
        $this->post($base.'/expenses/'.$expense->public_id.'/receipts', ['expected_version' => AgentApiVersion::for($expense), 'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic.')], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-foreign-upload'])->assertNotFound();
        $this->getJson($base.'/expense-schedules')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/expense-schedules?company_id='.$otherCompany->public_id)->assertNotFound();
        $this->patchJson($base.'/expense-schedules/'.$schedule->public_id, $this->facts() + ['active' => true, 'expected_version' => AgentApiVersion::for($schedule)], ['Idempotency-Key' => 'synthetic-foreign-update'])->assertNotFound();
        $this->postJson($base.'/expense-schedules/'.$schedule->public_id.'/generate', ['confirm' => true, 'expected_version' => AgentApiVersion::for($schedule)], ['Idempotency-Key' => 'synthetic-foreign-generate'])->assertNotFound();
        $this->postJson($base.'/expense-schedules', $this->facts() + ['company_id' => $otherCompany->public_id, 'starts_on' => '2026-10-01', 'cadence' => 'monthly', 'expected_version' => AgentApiVersion::for($otherCompany)], ['Idempotency-Key' => 'synthetic-foreign-parent'])->assertNotFound();
        $local = $this->expense($workspace, $company);
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAsMcp($member, ['mcp:use', 'clients:read', 'expenses:read', 'expenses:write']);
        $this->getJson($base.'/expense-schedules')->assertForbidden();
        $this->getJson($base.'/expenses/'.$local->public_id.'/receipts')->assertForbidden();
        $this->postJson($base.'/expenses/'.$local->public_id.'/approve', ['expected_version' => AgentApiVersion::for($local)], ['Idempotency-Key' => 'synthetic-member-approve'])->assertForbidden();
        $this->actingAsMcp($owner, ['mcp:use']);
        $this->getJson($base.'/expense-schedules')->assertForbidden();
        $this->getJson($base.'/expenses/'.$local->public_id.'/receipts')->assertForbidden();
        $this->postJson($base.'/expenses/'.$local->public_id.'/approve', ['expected_version' => AgentApiVersion::for($local)], ['Idempotency-Key' => 'synthetic-no-scope'])->assertForbidden();
    }

    #[DataProvider('cutovers')]
    public function test_new_writes_follow_nested_cutovers(bool $outer, bool $inner): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $schedule = (new WorkspaceExpenseSchedules($workspace))->create($company, null, $this->newFacts(), BillingCadence::Monthly);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        config(['agent_api.writes_enabled' => $outer, 'agent_api.expense_writes_enabled' => $inner]);
        $base = $this->base($workspace);
        $this->getJson($base.'/expense-schedules')->assertOk();
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts')->assertOk();
        foreach (['approve', 'unapprove'] as $operation) {
            $this->postJson($base.'/expenses/'.$expense->public_id.'/'.$operation, ['expected_version' => AgentApiVersion::for($expense)], ['Idempotency-Key' => 'synthetic-off-'.$operation])->assertNotFound();
        }
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts/upload-url')->assertNotFound();
        $this->post($base.'/expenses/'.$expense->public_id.'/receipts', [], ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson($base.'/expense-schedules', [], ['Idempotency-Key' => 'synthetic-off-create'])->assertNotFound();
        $this->patchJson($base.'/expense-schedules/'.$schedule->public_id, [], ['Idempotency-Key' => 'synthetic-off-update'])->assertNotFound();
        $this->postJson($base.'/expense-schedules/'.$schedule->public_id.'/generate', [], ['Idempotency-Key' => 'synthetic-off-generate'])->assertNotFound();
    }

    public function test_schedule_pagination_is_bounded_and_bound_to_workspace_and_company(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $schedules = new WorkspaceExpenseSchedules($workspace);
        $first = $schedules->create($company, null, $this->newFacts(), BillingCadence::Monthly);
        $second = $schedules->create($company, null, $this->newFacts(), BillingCadence::Annual);
        $this->actingAsMcp($owner, ['expenses:read']);
        $url = $this->base($workspace).'/expense-schedules';
        $page = $this->getJson($url.'?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->public_id);
        $cursor = urlencode($page->json('meta.next_cursor'));
        $this->getJson($url.'?limit=1&cursor='.$cursor)->assertOk()->assertJsonPath('data.0.id', $second->public_id)->assertJsonPath('meta.next_cursor', null);
        $this->getJson($url.'?limit=1&company_id='.$company->public_id.'&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($url.'?limit=101')->assertUnprocessable();
    }

    public function test_receipt_urls_recheck_parent_ownership_availability_and_current_membership(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $first = $this->expense($workspace, $company);
        $second = $this->expense($workspace, $company);
        $receipt = app(AttachmentStorageService::class)->store($workspace, $first,
            UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic receipt only.'), $owner);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        $base = $this->base($workspace).'/expenses/';
        $download = $this->getJson($base.$first->public_id.'/receipts/'.$receipt->public_id)->assertOk()->json('data.download_url');
        $this->getJson($base.$second->public_id.'/receipts/'.$receipt->public_id)->assertNotFound();
        $receipt->forceFill(['lifecycle_state' => ClientAttachment::STATE_DELETING])->save();
        $this->getJson($base.$first->public_id.'/receipts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.$first->public_id.'/receipts/'.$receipt->public_id)->assertNotFound();
        $this->get($download)->assertNotFound();
        $receipt->forceFill(['lifecycle_state' => ClientAttachment::STATE_AVAILABLE])->save();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->get($download)->assertForbidden();
        $this->getJson($base.$first->public_id.'/receipts/upload-url')->assertForbidden();
    }

    public function test_upload_validation_and_replay_recheck_authorization_and_never_write_foreign_objects(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        $url = $this->base($workspace).'/expenses/'.$expense->public_id.'/receipts';
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic receipt.');
        $version = AgentApiVersion::for($expense);
        $this->post($url, ['file' => $file()], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-missing-version'])->assertUnprocessable();
        $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => 'expenses.receipts.upload', 'outcome' => 'failed', 'error_category' => 'validation']);
        $this->post($url, ['file' => UploadedFile::fake()->create('synthetic-large.txt', 51201), 'expected_version' => $version], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-large-file'])->assertUnprocessable();
        $this->assertDatabaseCount('client_attachments', 0);
        $this->post($url, ['file' => $file(), 'expected_version' => $version], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-auth-replay'])->assertCreated();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->post($url, ['file' => $file(), 'expected_version' => $version], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-auth-replay'])->assertForbidden();
        $this->assertDatabaseCount('client_attachments', 1);
    }

    public function test_schedule_generation_is_bounded_idempotent_and_pausing_refuses_new_work(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $schedules = new WorkspaceExpenseSchedules($workspace);
        $old = $schedules->create($company, null, new NewExpense(CarbonImmutable::parse('2020-01-31'), 500, 'USD', 'Synthetic backlog.'), BillingCadence::Monthly);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        $url = $this->base($workspace).'/expense-schedules/'.$old->public_id;
        $body = ['expected_version' => AgentApiVersion::for($old), 'confirm' => true];
        $generated = $this->postJson($url.'/generate', $body, ['Idempotency-Key' => 'synthetic-bounded-generate'])->assertOk()->assertJsonPath('generated_count', 24);
        $this->assertDatabaseCount('client_expenses', 24);
        $this->postJson($url.'/generate', $body, ['Idempotency-Key' => 'synthetic-bounded-generate'])->assertOk()->assertJsonPath('generated_count', 24);
        $paused = $this->patchJson($url, $this->facts() + ['active' => false, 'expected_version' => $generated->json('data.version')], ['Idempotency-Key' => 'synthetic-pause'])->assertOk();
        $this->postJson($url.'/generate', ['expected_version' => $paused->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-paused-generate'])->assertOk()->assertJsonPath('generated_count', 0);
        $this->assertDatabaseCount('client_expenses', 24);
    }

    public function test_new_operation_graph_keeps_every_tenant_query_scoped(): void
    {
        [$workspace, $company, $owner] = $this->fixture();
        $expense = $this->expense($workspace, $company);
        $this->actingAsMcp($owner, ['clients:read', 'expenses:read', 'expenses:write']);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $base = $this->base($workspace);
        $schedule = $this->postJson($base.'/expense-schedules', $this->facts() + ['company_id' => $company->public_id,
            'starts_on' => '2026-10-01', 'cadence' => 'monthly', 'expected_version' => AgentApiVersion::for($company)], ['Idempotency-Key' => 'synthetic-scoped-create'])->assertCreated();
        $this->postJson($base.'/expense-schedules/'.$schedule->json('data.id').'/generate', ['expected_version' => $schedule->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-scoped-generate'])->assertOk();
        $this->getJson($base.'/expense-schedules')->assertOk();
        $upload = $this->post($base.'/expenses/'.$expense->public_id.'/receipts', ['expected_version' => AgentApiVersion::for($expense),
            'file' => UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic.')], ['Accept' => 'application/json', 'Idempotency-Key' => 'synthetic-scoped-upload'])->assertCreated();
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts')->assertOk();
        $this->getJson($base.'/expenses/'.$expense->public_id.'/receipts/'.$upload->json('data.id'))->assertOk();
        $this->postJson($base.'/expenses/'.$expense->public_id.'/approve', ['expected_version' => $upload->json('expense_version')], ['Idempotency-Key' => 'synthetic-scoped-approve'])->assertOk();
        $touched = [];
        foreach ($queries as $query) {
            $sql = str_replace(['`', '"', '[', ']'], '', strtolower($query));
            preg_match_all('/\b(?:from|into|update|join)\s+([a-z0-9_]+)/i', $sql, $matches);
            foreach (array_unique($matches[1]) as $table) {
                if (in_array($table, ['users', 'workspaces'], true)) {
                    continue;
                }
                $touched[$table] = true;
                $predicate = str_starts_with($sql, 'insert') ? $sql : strstr($sql, ' where ');
                $this->assertIsString($predicate, $sql);
                $this->assertStringContainsString('workspace_id', $predicate, $sql);
            }
        }
        foreach (['client_expenses', 'client_companies', 'client_expense_schedules', 'client_attachments', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertArrayHasKey($table, $touched);
        }
    }

    public static function cutovers(): iterable
    {
        yield [false, false];
        yield [false, true];
        yield [true, false];
    }

    /** @return array{Workspace,ClientCompany,User,ClientProject} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Expense Parity Workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Expense Client', 'slug' => 'synthetic-'.Str::uuid()]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Expense Project']);

        return [$workspace, $company, $owner, $project];
    }

    private function expense(Workspace $workspace, ClientCompany $company, ?ClientProject $project = null): ClientExpense
    {
        return (new WorkspaceExpenses($workspace))->record($company, $project, $this->newFacts());
    }

    private function newFacts(): NewExpense
    {
        return new NewExpense(CarbonImmutable::parse('2026-10-01'), 500, 'USD', 'Synthetic expense.');
    }

    /** @return array<string,mixed> */
    private function facts(): array
    {
        return ['amount' => 500, 'currency' => 'USD', 'description' => 'Synthetic expense.'];
    }

    private function base(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id;
    }

    /** @param array<string,mixed> $response */
    private function schema(string $operation, array $response): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($response, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }

    private function mcpSession(): string
    {
        $session = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic expense parity test', 'version' => '1']]])->assertOk()->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /** @param array<string,mixed> $arguments
     * @return TestResponse<Response> */
    private function mcpCall(string $tool, array $arguments, string $session): TestResponse
    {
        return $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])
            ->assertOk()->assertJsonPath('result.isError', false);
    }
}
