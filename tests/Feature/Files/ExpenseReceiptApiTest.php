<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentExpenseReceiptService;
use App\Services\Files\AttachmentMutationOrigin;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class ExpenseReceiptApiTest extends TestCase
{
    use BuildsSyntheticExpenses, CallsMcp, RefreshDatabase, WritesLegacyCrossTenantRows;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.filesystem_disk' => 'svc_files', 'agent_api.writes_enabled' => true, 'agent_api.expense_writes_enabled' => true, 'agent_api.mcp_enabled' => true]);
        Storage::fake('svc_files');
    }

    public function test_native_dual_manager_uploads_reads_and_deletes_with_agent_writes_disabled_and_replay_rechecks_role(): void
    {
        [$actor, $workspace, $expense] = $this->fixture(true);
        config(['agent_api.writes_enabled' => false, 'agent_api.expense_writes_enabled' => false]);
        $this->sessionActor($actor);
        $url = $this->base($workspace, $expense->public_id);
        $uploaded = $this->withHeader('Idempotency-Key', 'synthetic-native-upload')->postJson($url, ['expected_version' => AgentApiVersion::for($expense),
            'file' => UploadedFile::fake()->createWithContent('synthetic-native.txt', 'Synthetic native receipt bytes')])->assertCreated();
        $file = ClientAttachment::query()->sole();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $file->public_id)->assertJsonPath('data.0.version', AgentApiVersion::for($file));
        $download = $this->getJson($url.'/'.$file->public_id)->assertOk()->json('data.download_url');
        $this->get($download)->assertOk()->assertStreamedContent('Synthetic native receipt bytes');
        $this->getJson($url.'/upload-url')->assertOk();
        $body = ['expected_version' => $uploaded->json('data.version'), 'expected_parent_version' => $uploaded->json('expense_version'), 'confirm' => true];
        $deleted = $this->withHeader('Idempotency-Key', 'synthetic-native-delete')->deleteJson($url.'/'.$file->public_id, $body)->assertOk()->assertJsonPath('data.lifecycle_state', 'deleting');
        $this->deleteJson($url.'/'.$file->public_id, $body)->assertOk()->assertJsonPath('data.version', $deleted->json('data.version'))->assertJsonPath('expense_version', $deleted->json('expense_version'));
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->get($download)->assertNotFound();
        $this->assertSame($expense->amount, $expense->fresh()->amount);
        $this->assertSame('draft', $expense->fresh()->status);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'expenses.receipts.delete', 'outcome' => 'success']);
        $workspace->memberships()->where('user_id', $actor->id)->update(['role' => 'viewer']);
        $this->deleteJson($url.'/'.$file->public_id, $body)->assertForbidden();
    }

    public function test_rest_and_mcp_delete_share_canonical_receipt_and_versions(): void
    {
        [$actor, $workspace, $expense] = $this->fixture();
        $receipt = $this->uploaded($actor, $workspace, $expense);
        $body = $this->deleteBody($expense, $receipt);
        $this->actingAsMcp($actor, ['mcp:use', 'expenses:read', 'expenses:write']);
        $this->deleteJson($this->base($workspace, $expense->public_id).'/'.$receipt->public_id, $body, ['Idempotency-Key' => 'synthetic-cross-delete'])->assertOk();
        $session = $this->mcp($this->initializeMessage())->headers->get('Mcp-Session-Id');
        $result = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'expenses.receipts.delete', 'arguments' => [
            'workspace_id' => $workspace->public_id, 'expense_id' => $expense->public_id, 'receipt_id' => $receipt->public_id, 'idempotency_key' => 'synthetic-cross-delete', ...$body]]], $session);
        $this->assertFalse($result->json('result.isError') ?? false);
        $this->assertDatabaseCount('agent_mutation_receipts', 2); // Upload and one deletion shared by REST/MCP.
        $this->assertSame(ClientAttachment::STATE_DELETING, $receipt->fresh()->lifecycle_state);
    }

    public function test_delete_checks_both_versions_confirmation_closed_fields_and_tenant_parent_without_effects(): void
    {
        [$actor, $workspace, $expense] = $this->fixture();
        $receipt = $this->uploaded($actor, $workspace, $expense);
        $body = $this->deleteBody($expense, $receipt);
        $this->actingAsMcp($actor, ['mcp:use', 'expenses:read', 'expenses:write']);
        $url = $this->base($workspace, $expense->public_id).'/'.$receipt->public_id;
        foreach ([['expected_version' => str_repeat('b', 64)], ['expected_parent_version' => str_repeat('c', 64)]] as $index => $change) {
            $this->withHeader('Idempotency-Key', 'synthetic-stale-'.$index)->deleteJson($url, [...$body, ...$change])->assertConflict();
        }
        foreach ([['confirm' => false], ['confirm' => 'true'], ['extra' => 'synthetic-spoof']] as $index => $change) {
            $this->withHeader('Idempotency-Key', 'synthetic-invalid-'.$index)->deleteJson($url, [...$body, ...$change])->assertUnprocessable();
        }
        $otherExpense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'wrong-parent'));
        $this->withHeader('Idempotency-Key', 'synthetic-wrong-parent')->deleteJson($this->base($workspace, $otherExpense->public_id).'/'.$receipt->public_id, $body)->assertNotFound();
        [, $otherWorkspace, $foreign] = $this->fixture();
        $this->withHeader('Idempotency-Key', 'synthetic-foreign')->deleteJson($this->base($workspace, $foreign->public_id).'/'.$receipt->public_id, $body)->assertNotFound();
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $receipt->fresh()->lifecycle_state);
        $this->assertSame($body['expected_parent_version'], AgentApiVersion::for($expense->fresh()));
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    public function test_oauth_and_mcp_dual_identity_cannot_claim_native_origin_for_any_receipt_route(): void
    {
        [$actor, $workspace, $expense] = $this->fixture(true);
        $receipt = $this->uploaded($actor, $workspace, $expense);
        $this->actingAsMcp($actor, ['mcp:use', 'expenses:read', 'expenses:write']);
        $url = $this->base($workspace, $expense->public_id);
        $this->withHeader('svc_first_party_session', 'true')->getJson($url)->assertForbidden();
        $this->getJson($url.'/'.$receipt->public_id)->assertForbidden();
        $this->getJson($url.'/upload-url')->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-agent-upload')->postJson($url, ['expected_version' => AgentApiVersion::for($expense->fresh()),
            'file' => UploadedFile::fake()->createWithContent('synthetic-forbidden.txt', 'Synthetic forbidden bytes')])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-agent-delete')->deleteJson($url.'/'.$receipt->public_id, $this->deleteBody($expense, $receipt))->assertForbidden();
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $receipt->fresh()->lifecycle_state);
        $this->assertDatabaseCount('client_attachments', 1);
    }

    public function test_legacy_foreign_company_lineage_is_refused_before_receipt_reads_or_writes(): void
    {
        [$actor, $workspace, $expense] = $this->fixture();
        $receipt = $this->uploaded($actor, $workspace, $expense);
        [, $otherWorkspace, $foreignExpense] = $this->fixture();
        $this->writingLegacyCrossTenantRows(fn () => $expense->forceFill(['client_company_id' => $foreignExpense->client_company_id])->save());
        $this->sessionActor($actor);
        $url = $this->base($workspace, $expense->public_id);
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'/'.$receipt->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-legacy-lineage')->deleteJson($url.'/'.$receipt->public_id, $this->deleteBody($expense, $receipt))->assertNotFound();
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $receipt->fresh()->lifecycle_state);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    public function test_native_cli_preview_has_no_effects_and_apply_requires_confirmation_and_replays(): void
    {
        [$actor, $workspace, $expense] = $this->fixture(true);
        $receipt = $this->uploaded($actor, $workspace, $expense);
        $body = $this->deleteBody($expense, $receipt);
        config(['agent_api.writes_enabled' => false]);
        $arguments = ['workspace' => $workspace->public_id, 'expense' => $expense->public_id, 'receipt' => $receipt->public_id, '--actor' => $actor->public_id,
            '--expected-version' => $body['expected_version'], '--expected-parent-version' => $body['expected_parent_version'], '--format' => 'json'];
        $beforeAudits = DB::table('agent_mutation_audits')->count();
        $this->assertSame(0, Artisan::call('svc:attachments:delete-expense-receipt', $arguments));
        $this->assertTrue(json_decode(Artisan::output(), true)['dry_run']);
        $this->assertSame($beforeAudits, DB::table('agent_mutation_audits')->count());
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $receipt->fresh()->lifecycle_state);
        $this->assertSame(1, Artisan::call('svc:attachments:delete-expense-receipt', [...$arguments, '--apply' => true, '--key' => 'synthetic-cli-delete']));
        $this->assertSame(0, Artisan::call('svc:attachments:delete-expense-receipt', [...$arguments, '--apply' => true, '--confirm' => true, '--key' => 'synthetic-cli-delete']));
        $version = AgentApiVersion::for($expense->fresh());
        $this->assertSame(0, Artisan::call('svc:attachments:delete-expense-receipt', [...$arguments, '--apply' => true, '--confirm' => true, '--key' => 'synthetic-cli-delete']));
        $this->assertSame($version, AgentApiVersion::for($expense->fresh()));
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
    }

    /** @return array{User, Workspace, ClientExpense} */
    private function fixture(bool $dual = false): array
    {
        $workspace = $this->syntheticWorkspace('receipt-api-'.str()->random(8));
        $actor = $this->syntheticMember($workspace, 'receipt-api-'.str()->random(8), 'owner');
        $company = $this->syntheticCompany($workspace, 'receipt-api-'.str()->random(8));
        if ($dual) {
            ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'client']);
        }

        return [$actor, $workspace, $this->recordSyntheticExpense($workspace, $company)];
    }

    private function sessionActor(User $actor): void
    {
        $this->actingAs($actor)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str()->random(40));
    }

    private function uploaded(User $actor, Workspace $workspace, ClientExpense $expense): ClientAttachment
    {
        app(AgentExpenseReceiptService::class)->upload($actor, $workspace, 'synthetic-setup', 'synthetic-upload', $expense->public_id,
            ['expected_version' => AgentApiVersion::for($expense)], UploadedFile::fake()->createWithContent('synthetic-receipt.txt', 'Synthetic receipt bytes'), AttachmentMutationOrigin::Native);

        return ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
    }

    /** @return array{expected_version:string,expected_parent_version:string,confirm:true} */
    private function deleteBody(ClientExpense $expense, ClientAttachment $receipt): array
    {
        return ['expected_version' => AgentApiVersion::for($receipt->fresh()), 'expected_parent_version' => AgentApiVersion::for($expense->fresh()), 'confirm' => true];
    }

    private function base(Workspace $workspace, string $expenseId): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/expenses/'.$expenseId.'/receipts';
    }
}
