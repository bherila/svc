<?php

namespace Tests\Feature\AgentApi;

use App\Mail\InvoiceMail;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** Real commits: the receipt's afterCommit delivery must resolve before the API projects its result. */
final class InvoiceSendOutcomeTest extends TestCase
{
    use CallsMcp;
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            $this->truncateTablesForAllConnections();
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'queue.default' => 'database']);
        Mail::fake();
    }

    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_committed_send_returns_its_accepted_delivery_and_replay_never_resends(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $body = $this->body($invoice);
        $this->actingAsMcp($owner, ['mcp:use', AgentApiScopes::BILLING_DELIVER]);
        $sent = $this->send($transport, $workspace, $invoice, $body, 'synthetic-sent');
        $delivery = ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(['id' => $delivery->public_id, 'status' => 'sent', 'error_summary' => null], $sent['delivery']);
        $this->assertSame($invoice->public_id, $sent['id']);
        $this->assertSame([$invoice->public_id, $delivery->public_id], AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.send')->where('status', 'completed')->sole()->result_public_ids);
        $this->assertSame($sent, $this->send($transport, $workspace, $invoice, $body, 'synthetic-sent'));
        Mail::assertSent(InvoiceMail::class, 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('transports')]
    public function test_preparation_failure_is_explicit_and_requires_a_new_confirmed_attempt(string $transport): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $body = $this->body($invoice);
        $this->actingAsMcp($owner, ['mcp:use', AgentApiScopes::BILLING_DELIVER]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic private preparation detail'));
        $failed = $this->send($transport, $workspace, $invoice, $body, 'synthetic-preparation');
        $this->assertSame('failed', $failed['delivery']['status']);
        $this->assertSame('Email delivery failed (RuntimeException).', $failed['delivery']['error_summary']);
        $this->assertSame($failed, $this->send($transport, $workspace, $invoice, $body, 'synthetic-preparation'));
        $this->assertSame(1, ClientInvoiceEmailDelivery::query()->count());

        Mail::clearResolvedInstance('mail.manager');
        app()->forgetInstance('mail.manager');
        Mail::fake();
        $accepted = $this->send($transport, $workspace, $invoice->fresh(), $this->body($invoice->fresh()), 'synthetic-explicit-retry');
        $this->assertSame('sent', $accepted['delivery']['status']);
        $this->assertNotSame($failed['delivery']['id'], $accepted['delivery']['id']);
        Mail::assertSent(InvoiceMail::class, 1);
    }

    public function test_lost_handoff_response_stays_unresolved_and_blocks_any_new_attempt(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $body = $this->body($invoice);
        $this->actingAsMcp($owner, ['mcp:use', AgentApiScopes::BILLING_DELIVER]);
        $mailer = \Mockery::mock(PendingMail::class);
        $mailer->shouldReceive('send')->once()->andThrow(new TransportException('Synthetic accepted then response lost; private detail'));
        Mail::shouldReceive('to')->once()->andReturn($mailer);
        $unknown = $this->send('rest', $workspace, $invoice, $body, 'synthetic-unknown');
        $this->assertSame('sending', $unknown['delivery']['status']);
        $this->assertNull($unknown['delivery']['error_summary']);
        $this->assertSame($unknown, $this->send('rest', $workspace, $invoice, $body, 'synthetic-unknown'));
        $this->postJson($this->url($workspace, $invoice), $this->body($invoice->fresh()), ['Idempotency-Key' => 'synthetic-unsafe-new-attempt'])->assertUnprocessable();
        $delivery = ClientInvoiceEmailDelivery::query()->sole();
        $this->assertSame('sending', $delivery->status);
        $this->assertNotNull($delivery->claimed_at);
        $this->assertNull($delivery->failed_at);
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_surrounding_transaction_projects_pending_and_rollback_never_hands_off(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $body = $this->body($invoice);
        $this->actingAsMcp($owner, ['mcp:use', AgentApiScopes::BILLING_DELIVER]);
        DB::transaction(function () use ($workspace, $invoice, $body): void {
            $pending = $this->send('rest', $workspace, $invoice, $body, 'synthetic-outer-commit');
            $this->assertSame('pending', $pending['delivery']['status']);
            Mail::assertNotSent(InvoiceMail::class);
        });
        $this->assertSame('sent', $this->send('rest', $workspace, $invoice, $body, 'synthetic-outer-commit')['delivery']['status']);
        Mail::assertSent(InvoiceMail::class, 1);

        try {
            DB::transaction(function () use ($workspace, $invoice): void {
                $pending = $this->send('rest', $workspace, $invoice, $this->body($invoice->fresh()), 'synthetic-outer-rollback');
                $this->assertSame('pending', $pending['delivery']['status']);
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException $rollback) {
            $this->assertSame('Synthetic rollback', $rollback->getMessage());
        }
        $this->assertSame(1, ClientInvoiceEmailDelivery::query()->count());
        $this->assertSame(0, AgentMutationReceipt::query()->where('idempotency_key', 'synthetic-outer-rollback')->count());
        Mail::assertSent(InvoiceMail::class, 1);
    }

    public function test_legacy_or_foreign_delivery_identity_never_projects_an_unrelated_delivery(): void
    {
        [$owner, $workspace, $invoice] = $this->fixture();
        $body = $this->body($invoice);
        $this->actingAsMcp($owner, ['mcp:use', AgentApiScopes::BILLING_DELIVER]);
        $this->send('rest', $workspace, $invoice, $body, 'synthetic-old-receipt');
        $receipt = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('operation', 'invoices.send')->where('status', 'completed')->sole();
        $receipt->forceFill(['result_public_ids' => [$invoice->public_id]])->save();
        $this->assertNull($this->send('rest', $workspace, $invoice, $body, 'synthetic-old-receipt')['delivery']);

        [, $foreignWorkspace, $foreignInvoice] = $this->fixture();
        $foreign = $foreignInvoice->emailDeliveries()->create(['workspace_id' => $foreignWorkspace->id, 'origin' => 'manual', 'invoice_revision' => 1, 'attempt_number' => 1, 'recipients' => ['foreign@example.test'], 'subject' => 'Synthetic foreign invoice', 'status' => 'failed']);
        $receipt->forceFill(['result_public_ids' => [$invoice->public_id, $foreign->public_id]])->save();
        $this->assertNull($this->send('rest', $workspace, $invoice, $body, 'synthetic-old-receipt')['delivery']);
        $sameTenantOtherInvoice = app(InvoiceLifecycleService::class)->issue(app(InvoiceLifecycleService::class)->createDraft($workspace, $invoice->clientCompany, ['currency' => 'USD', 'invoice_number' => 'INV-SYN-'.str()->random(12)], [['type' => 'adjustment', 'description' => 'Synthetic other invoice', 'quantity' => 1, 'unit_amount' => 100]]), $workspace);
        $otherDelivery = $sameTenantOtherInvoice->emailDeliveries()->create(['workspace_id' => $workspace->id, 'origin' => 'manual', 'invoice_revision' => 1, 'attempt_number' => 1, 'recipients' => ['other@example.test'], 'subject' => 'Synthetic other invoice', 'status' => 'failed']);
        $receipt->forceFill(['result_public_ids' => [$invoice->public_id, $otherDelivery->public_id]])->save();
        $this->assertNull($this->send('rest', $workspace, $invoice, $body, 'synthetic-old-receipt')['delivery']);
        Mail::assertSent(InvoiceMail::class, 1);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic send outcome', 'slug' => 'synthetic-send-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic outcome client', 'slug' => 'synthetic-outcome-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'INV-SYN-'.str()->random(12)], [['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 100]]), $workspace);

        return [$owner, $workspace, $invoice];
    }

    private function body(ClientInvoice $invoice): array
    {
        return ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true, 'recipients' => ['synthetic-billing@example.test']];
    }

    private function url(Workspace $workspace, ClientInvoice $invoice): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/invoices/'.$invoice->public_id.'/send';
    }

    private function send(string $transport, Workspace $workspace, ClientInvoice $invoice, array $body, string $key): array
    {
        if ($transport === 'rest') {
            return $this->postJson($this->url($workspace, $invoice), $body, ['Idempotency-Key' => $key])->assertOk()->json('data');
        }
        $result = $this->callTool($this->initialize(), 'invoices.send', ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id, 'idempotency_key' => $key, ...$body]);
        $this->assertFalse($result['result']['isError'] ?? true, json_encode($result));

        return $result['result']['structuredContent']['data'];
    }
}
