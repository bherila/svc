<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceEmailDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AgentMutationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_reservation_canonicalizes_object_keys_and_replays_without_running_the_callback(): void
    {
        [$user, $workspace] = $this->tenant();
        $executor = app(AgentMutationExecutor::class);
        $resultId = (string) Str::uuid();
        $calls = 0;

        $first = $executor->run($user, $workspace, 'test-client', 'test.create', 'same-key', [
            'outer' => ['beta' => 2, 'alpha' => 1],
            'ordered' => [['second' => 2, 'first' => 1]],
        ], function () use (&$calls, $resultId): array {
            $calls++;

            return [$resultId];
        });
        $replay = $executor->run($user, $workspace, 'test-client', 'test.create', 'same-key', [
            'ordered' => [['first' => 1, 'second' => 2]],
            'outer' => ['alpha' => 1, 'beta' => 2],
        ], function () use (&$calls): array {
            $calls++;

            return [(string) Str::uuid()];
        });

        $this->assertSame([$resultId], $first);
        $this->assertSame($first, $replay);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertDatabaseHas('agent_mutation_receipts', ['status' => 'completed', 'workspace_id' => $workspace->id]);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'test.create', 'outcome' => 'success', 'error_category' => null]);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'test.create', 'outcome' => 'replay', 'error_category' => null]);

        try {
            $executor->run($user, $workspace, 'test-client', 'test.create', 'same-key', ['outer' => ['alpha' => 9]], fn (): array => []);
            $this->fail('A reused idempotency key with a different request must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'test.create', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    public function test_pending_receipt_blocks_the_concurrent_loser_before_its_callback(): void
    {
        [$user, $workspace] = $this->tenant();
        $payload = ['name' => 'reserved'];
        AgentMutationReceipt::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'oauth_client_id' => 'test-client',
            'operation' => 'test.concurrent',
            'idempotency_key' => 'race-key',
            'request_digest' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => 'pending',
            'result_public_ids' => [],
        ]);
        $called = false;

        try {
            app(AgentMutationExecutor::class)->run(
                $user,
                $workspace,
                'test-client',
                'test.concurrent',
                'race-key',
                $payload,
                function () use (&$called): array {
                    $called = true;

                    return [];
                },
            );
            $this->fail('A concurrent loser must wait for or conflict with the pending reservation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertFalse($called);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'test.concurrent', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    public function test_the_same_idempotency_key_is_independent_across_workspaces(): void
    {
        [$user, $firstWorkspace] = $this->tenant();
        $secondWorkspace = Workspace::query()->create(['name' => 'Second Integrity Workspace', 'slug' => 'second-integrity-'.Str::lower(Str::random(8))]);
        $secondWorkspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $executor = app(AgentMutationExecutor::class);
        $calls = 0;
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        $first = $executor->run(
            $user,
            $firstWorkspace,
            'test-client',
            'time_entries.log',
            'shared-key',
            ['entries' => [['minutes' => 30]]],
            function () use (&$calls, $firstId): array {
                $calls++;

                return [$firstId];
            },
        );
        $second = $executor->run(
            $user,
            $secondWorkspace,
            'test-client',
            'time_entries.log',
            'shared-key',
            ['entries' => [['minutes' => 30]]],
            function () use (&$calls, $secondId): array {
                $calls++;

                return [$secondId];
            },
        );

        $this->assertSame([$firstId], $first);
        $this->assertSame([$secondId], $second);
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
        $this->assertDatabaseHas('agent_mutation_receipts', ['workspace_id' => $firstWorkspace->id, 'idempotency_key' => 'shared-key']);
        $this->assertDatabaseHas('agent_mutation_receipts', ['workspace_id' => $secondWorkspace->id, 'idempotency_key' => 'shared-key']);
    }

    public function test_a_failure_halfway_through_a_time_batch_rolls_back_the_first_entry_and_receipt(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$user, $workspace, , $project] = $this->tenant();
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'contributor',
        ]);
        $this->actingAsAgent($user, [AgentApiScopes::TIME_WRITE]);

        $this->withHeader('Idempotency-Key', 'partial-batch')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/time-entries",
            ['entries' => [
                ['project_id' => $project->public_id, 'worked_on' => '2026-08-23', 'minutes' => 30, 'description' => 'Would otherwise commit'],
                ['project_id' => (string) Str::uuid(), 'worked_on' => '2026-08-23', 'minutes' => 15, 'description' => 'Missing project'],
            ]],
        )->assertNotFound();

        $this->assertDatabaseCount('client_time_entries', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseHas('agent_mutation_audits', [
            'operation' => 'time_entries.log',
            'outcome' => 'failed',
            'error_category' => 'not_found',
        ]);
    }

    public function test_task_and_invoice_side_effects_are_replay_safe_and_audited(): void
    {
        config(['agent_api.writes_enabled' => true]);
        Queue::fake();
        [$user, $workspace, $company, $project] = $this->tenant();
        $this->actingAsAgent($user, [
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::BILLING_WRITE,
            AgentApiScopes::BILLING_DELIVER,
        ]);

        $taskPath = "/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}/tasks";
        $taskPayload = ['title' => 'Replay-safe task'];
        $task = $this->withHeader('Idempotency-Key', 'task-create')->postJson($taskPath, $taskPayload)->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'task-create')->postJson($taskPath, $taskPayload)->assertCreated()->assertJsonPath('data.id', $task['id']);
        $taskUpdatePath = "/api/v1/workspaces/{$workspace->public_id}/tasks/{$task['id']}";
        $taskUpdate = ['expected_version' => $task['version'], 'title' => 'Updated once'];
        $updatedTask = $this->withHeader('Idempotency-Key', 'task-update')->patchJson($taskUpdatePath, $taskUpdate)->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'task-update')->patchJson($taskUpdatePath, $taskUpdate)->assertOk()->assertJsonPath('data.version', $updatedTask['version']);

        $invoicePath = "/api/v1/workspaces/{$workspace->public_id}/invoices";
        $invoicePayload = [
            'company_id' => $company->public_id,
            'manual_lines' => [[
                'project_id' => $project->public_id,
                'type' => 'service',
                'description' => 'Replay-safe service',
                'quantity' => '1',
                'unit_amount' => 10000,
                'tax_amount' => 0,
            ]],
        ];
        $invoice = $this->withHeader('Idempotency-Key', 'invoice-create')->postJson($invoicePath, $invoicePayload)->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'invoice-create')->postJson($invoicePath, $invoicePayload)->assertCreated()->assertJsonPath('data.id', $invoice['id']);
        $invoicePath .= "/{$invoice['id']}";

        $issue = ['expected_version' => $invoice['version'], 'confirm' => true];
        $issued = $this->withHeader('Idempotency-Key', 'invoice-issue')->postJson("{$invoicePath}/issue", $issue)->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'invoice-issue')->postJson("{$invoicePath}/issue", $issue)->assertOk()->assertJsonPath('data.version', $issued['version']);

        $send = ['expected_version' => $issued['version'], 'recipients' => ['client@example.test'], 'confirm' => true];
        $sent = $this->withHeader('Idempotency-Key', 'invoice-send')->postJson("{$invoicePath}/send", $send)->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'invoice-send')->postJson("{$invoicePath}/send", $send)->assertOk()->assertJsonPath('data.version', $sent['version']);
        // One delivery, registered rather than dispatched. The agent path
        // sends after its mutation transaction commits - so the receipt and the
        // email land together, and a rollback does not leave an email that has
        // already gone.
        $this->assertDatabaseCount('client_invoice_email_deliveries', 1);

        $void = ['expected_version' => $sent['version'], 'reason' => 'Customer requested cancellation', 'confirm' => true];
        $voided = $this->withHeader('Idempotency-Key', 'invoice-void')->postJson("{$invoicePath}/void", $void)->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'invoice-void')->postJson("{$invoicePath}/void", $void)->assertOk()->assertJsonPath('data.version', $voided['version']);

        $this->assertDatabaseCount('client_tasks', 1);
        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertDatabaseHas('client_invoices', ['public_id' => $invoice['id'], 'status' => 'void', 'void_reason' => 'Customer requested cancellation']);
        foreach (['tasks.create', 'tasks.update', 'invoices.create_draft', 'invoices.issue', 'invoices.send', 'invoices.void'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'success']);
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'replay']);
        }
    }

    public function test_a_web_task_mutation_invalidates_an_agent_version(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$user, $workspace, , $project] = $this->tenant();
        $task = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Web-owned task',
        ]);
        $staleVersion = AgentApiVersion::for($task);

        $this->actingAs($user)->patch("/workspaces/{$workspace->public_id}/tasks/{$task->public_id}", [
            'status' => 'completed',
        ])->assertRedirect();
        $this->assertNotSame($staleVersion, AgentApiVersion::for($task->fresh()));

        $this->actingAsAgent($user, [AgentApiScopes::TASKS_WRITE]);
        $this->withHeader('Idempotency-Key', 'stale-after-web')->patchJson(
            "/api/v1/workspaces/{$workspace->public_id}/tasks/{$task->public_id}",
            ['expected_version' => $staleVersion, 'title' => 'Stale agent update'],
        )->assertConflict();
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'tasks.update', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    public function test_invoice_lifecycle_payment_and_delivery_job_each_advance_the_revision(): void
    {
        Queue::fake();
        Mail::fake();
        [, $workspace, $company] = $this->tenant();
        $lifecycle = app(InvoiceLifecycleService::class);
        $invoice = $lifecycle->createDraft($workspace, $company, [
            'invoice_number' => 'REVISION-1',
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Revision test',
            'quantity' => '1',
            'unit_amount' => 10000,
            'tax_amount' => 0,
            'sort_order' => 0,
        ]]);
        $draftVersion = AgentApiVersion::for($invoice);

        $invoice = $lifecycle->issue($invoice, $workspace);
        $issuedVersion = AgentApiVersion::for($invoice);
        $this->assertNotSame($draftVersion, $issuedVersion);

        // Registering the delivery advances the revision, and so does the send
        // itself: an agent that read the invoice before either would be acting
        // on a version that no longer describes it.
        Mail::fake();
        $draft = InvoiceEmailDraft::of(['client@example.test'], [], 'Invoice', null);
        app(InvoiceEmailService::class)->sendAfterCommit($invoice, $draft, $workspace);
        $registeredVersion = AgentApiVersion::for($invoice->fresh());
        $this->assertNotSame($issuedVersion, $registeredVersion);

        app(InvoiceEmailService::class)->send($invoice, $draft, $workspace);
        $deliveredVersion = AgentApiVersion::for($invoice->fresh());
        $this->assertNotSame($registeredVersion, $deliveredVersion);

        $lifecycle->applyPayment($invoice, [
            'amount' => 1000,
            'currency' => 'USD',
            'method' => 'wire',
            'status' => 'succeeded',
            'idempotency_key' => 'revision-payment',
        ], $workspace);
        $this->assertNotSame($deliveredVersion, AgentApiVersion::for($invoice->fresh()));
    }

    /** @return array{User, Workspace, ClientCompany, ClientProject} */
    private function tenant(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Integrity Workspace', 'slug' => 'integrity-'.Str::lower(Str::random(8))]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Integrity Client', 'slug' => 'integrity-client-'.Str::lower(Str::random(8))]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Integrity Project']);

        return [$user, $workspace, $company, $project];
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
