<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceCorrectionRepository;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InvoiceCorrectionAuditTest extends TestCase
{
    use RefreshDatabase;

    public static function surfaces(): iterable
    {
        yield 'browser' => [false];
        yield 'REST' => [true];
    }

    #[DataProvider('surfaces')]
    public function test_one_hundred_money_changed_lines_succeed_with_complete_recoverable_evidence(bool $rest): void
    {
        [$owner, $workspace, $invoice] = $this->invoice(100);
        $lines = $invoice->lines()->where('workspace_id', $workspace->id)->orderBy('sort_order')->get();
        $submitted = $lines->map(fn ($line): array => [
            'id' => $line->public_id,
            'description' => 'Synthetic corrected '.$line->sort_order,
            'quantity' => '2.5',
            'unit_amount' => 2000,
            'tax_amount' => 125,
        ])->all();
        $reason = trim(str_repeat('Synthetic correction. ', 22));
        if ($rest) {
            config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
            $this->actingAsMcp($owner, [AgentApiScopes::BILLING_DELIVER, AgentApiScopes::BILLING_READ]);
            $summary = $this->withHeader('Idempotency-Key', 'synthetic-100-lines')->postJson(
                "/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/correct",
                ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true, 'reason' => $reason, 'lines' => $submitted],
            )->assertOk()->json('data.correction');
        } else {
            $this->actingAs($owner)->post(
                "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/correct",
                ['expected_revision' => 1, 'reason' => $reason, 'lines' => $submitted],
            )->assertRedirect();
            $page = $this->get(route('clients.invoice', [$workspace, $invoice->clientCompany, $invoice]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->has('corrections', 1));
            $summary = $page->viewData('page')['props']['corrections'][0];
        }

        $this->assertSame(512500, $invoice->fresh()->total_amount);
        $this->assertSame(2, $invoice->fresh()->document_revision);
        $activity = ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.corrected')->sole();
        $this->assertLessThan(2000, strlen(json_encode($activity->payload, JSON_THROW_ON_ERROR)));
        $this->assertSame(100, $activity->payload['changed_line_count']);
        $this->assertIsArray($summary, 'The supported response must expose a recovery capability.');
        $evidence = $this->getJson($summary['href'])->assertOk()->json('data');
        $this->assertSame($summary['id'], $evidence['id']);
        $this->assertSame($reason, $evidence['reason']);
        $this->assertSame(1, $evidence['from_revision']);
        $this->assertSame(2, $evidence['to_revision']);
        $this->assertSame('2026-10-15', $evidence['previous_due_date']);
        $this->assertSame('2026-10-15', $evidence['due_date']);
        $this->assertCount(100, $evidence['line_diffs']);
        foreach ($evidence['line_diffs'] as $index => $diff) {
            $line = $lines[$index];
            $this->assertSame($line->public_id, $diff['line']);
            $this->assertTrue($diff['description_changed']);
            $this->assertSame([
                'description' => $line->description, 'quantity' => (string) $line->quantity,
                'unit_amount' => 1000, 'tax_amount' => 0,
            ], $diff['before']);
            $this->assertSame([
                'description' => $submitted[$index]['description'], 'quantity' => '2.5',
                'unit_amount' => 2000, 'tax_amount' => 125,
            ], $diff['after']);
        }
        if ($rest) {
            $this->actingAsMcp($owner, [AgentApiScopes::BILLING_DELIVER]);
            $this->getJson($summary['href'])->assertForbidden();
        }

    }

    public function test_evidence_reads_and_writes_cannot_cross_workspace_or_invoice_boundaries(): void
    {
        [$owner, $workspace, $invoice] = $this->invoice(1);
        app(InvoiceCorrectionService::class)->correct($invoice, $workspace, 1, true, '2026-10-20', 'Synthetic due date', null);
        $evidence = ClientInvoiceCorrection::query()->where('workspace_id', $workspace->id)->sole();
        $sameWorkspaceInvoice = $invoice->replicate(['public_id']);
        $sameWorkspaceInvoice->invoice_number = 'INV-SYN-'.Str::random(8);
        $sameWorkspaceInvoice->save();
        $this->actingAs($owner)->getJson(
            "/workspaces/{$workspace->public_id}/invoices/{$sameWorkspaceInvoice->public_id}/corrections/{$evidence->public_id}",
        )->assertNotFound();
        [$otherOwner, $otherWorkspace, $otherInvoice] = $this->invoice(1);
        $url = "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/corrections/{$evidence->public_id}";
        $this->actingAs($otherOwner)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson(
            "/workspaces/{$workspace->public_id}/invoices/{$otherInvoice->public_id}/corrections/{$evidence->public_id}",
        )->assertNotFound();
        $this->actingAs($otherOwner)->getJson(
            "/workspaces/{$otherWorkspace->public_id}/invoices/{$otherInvoice->public_id}/corrections/{$evidence->public_id}",
        )->assertNotFound();
        try {
            app(InvoiceCorrectionRepository::class)->append($otherWorkspace, $invoice, 1, 'Synthetic refusal', null, []);
            $this->fail('Cross-workspace evidence was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('workspace', $exception->getMessage());
        }
        $this->assertSame(1, ClientInvoiceCorrection::query()->count());
    }

    public function test_agent_history_keeps_prior_evidence_and_refuses_non_managers_and_foreign_workspaces(): void
    {
        [$owner, $workspace, $invoice] = $this->invoice(1);
        app(InvoiceCorrectionService::class)->correct($invoice, $workspace, 1, true, '2026-10-20', 'Synthetic first correction', null);
        $this->actingAsMcp($owner, [AgentApiScopes::BILLING_READ]);
        $path = "/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}";
        $first = $this->getJson($path)->assertOk()->json('data.corrections.0');
        $this->getJson($first['href'])->assertOk()->assertJsonPath('data.due_date', '2026-10-20');
        app(InvoiceCorrectionService::class)->correct($invoice, $workspace, 2, true, '2026-10-25', 'Synthetic second correction', null);
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data.corrections');
        $this->getJson($first['href'])->assertOk()->assertJsonPath('data.due_date', '2026-10-20');
        $viewer = User::factory()->create(['email' => 'evidence-viewer@synthetic.test']);
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAsMcp($viewer, [AgentApiScopes::BILLING_READ]);
        $this->getJson($first['href'])->assertForbidden();
        [$otherOwner, $otherWorkspace, $otherInvoice] = $this->invoice(1);
        $this->actingAsMcp($otherOwner, [AgentApiScopes::BILLING_READ]);
        $this->getJson($first['href'])->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$otherWorkspace->public_id}/invoices/{$otherInvoice->public_id}/corrections/{$first['id']}")
            ->assertNotFound();
    }

    public function test_mcp_recovers_evidence_from_the_supported_invoice_history(): void
    {
        [$owner, $workspace, $invoice] = $this->invoice(1);
        app(InvoiceCorrectionService::class)->correct($invoice, $workspace, 1, true, '2026-10-20', 'Synthetic MCP recovery', null);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ]);
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $session = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic evidence test', 'version' => '1']],
        ], $headers)->assertOk()->headers->get('Mcp-Session-Id');
        $headers['Mcp-Session-Id'] = $session;
        $arguments = ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id];
        $result = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'invoices.get', 'arguments' => $arguments],
        ], $headers)->assertOk()->json('result');
        $this->assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        $history = $result['structuredContent']['data']['corrections'] ?? null;
        $this->assertIsArray($history, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $history);
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'invoices.corrections.get', 'arguments' => $arguments + ['correction_id' => $history[0]['id']]],
        ], $headers)->assertOk()->assertJsonPath('result.structuredContent.data.due_date', '2026-10-20');
    }

    public function test_evidence_rolls_back_with_the_correction_and_activity(): void
    {
        [, $workspace, $invoice] = $this->invoice(1);
        DB::beginTransaction();
        app(InvoiceCorrectionService::class)->correct($invoice, $workspace, 1, true, '2026-10-20', 'Synthetic rollback', null);
        $this->assertSame(1, ClientInvoiceCorrection::query()->where('workspace_id', $workspace->id)->count());
        DB::rollBack();
        $this->assertSame(1, $invoice->fresh()->document_revision);
        $this->assertSame(0, ClientInvoiceCorrection::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.corrected')->count());
    }

    /** @return array{User, Workspace, ClientInvoice} */
    private function invoice(int $lineCount): array
    {
        Mail::fake();
        $owner = User::factory()->create(['email' => 'audit-'.Str::random(8).'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Correction Audit', 'slug' => 'synthetic-audit-'.Str::random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Synthetic Audit Client',
            'slug' => 'synthetic-client-'.Str::random(8), 'billing_email' => 'billing@synthetic.test',
        ]);
        $draft = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYN-'.Str::random(8), 'currency' => 'USD',
            'issue_date' => '2026-09-15', 'due_date' => '2026-10-15',
        ], array_map(fn (int $i): array => [
            'type' => 'fee', 'description' => 'Synthetic service '.$i,
            'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0,
        ], range(1, $lineCount)));

        return [$owner, $workspace, app(InvoiceLifecycleService::class)->issue($draft, $workspace)];
    }
}
