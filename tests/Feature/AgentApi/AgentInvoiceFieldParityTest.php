<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `invoices.create_draft` and `invoices.send` take the invoice screen's fields
 * (#454), through REST and MCP alike, and refuse what the screen refuses.
 */
final class AgentInvoiceFieldParityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        $this->owner = User::factory()->create(['email' => 'synthetic-owner-'.Str::lower(Str::random(6)).'@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic parity', 'slug' => 'synthetic-parity-'.Str::lower(Str::random(8))]);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client', 'billing_email' => 'billing@example.test']);
    }

    /** @return iterable<string, array{string}> */
    public static function transports(): iterable
    {
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('transports')]
    public function test_a_draft_takes_its_number_issue_date_service_period_and_visibility(string $transport): void
    {
        $body = [
            'company_id' => $this->company->public_id,
            'manual_lines' => [$this->line()],
            'invoice_number' => 'INV-SYN-PARITY-1',
            'issue_date' => '2026-09-30',
            'service_period_start' => '2026-09-01',
            'service_period_end' => '2026-09-30',
            'is_visible_to_client' => true,
        ];

        $created = $this->createDraft($transport, $body, 'synthetic-parity-create');
        $this->assertSame('INV-SYN-PARITY-1', $created['invoice_number']);
        $draft = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('public_id', $created['id'])->sole();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('2026-09-30', $draft->issue_date?->toDateString());
        $this->assertSame('2026-09-01', $draft->service_period_start?->toDateString());
        $this->assertSame('2026-09-30', $draft->service_period_end?->toDateString());
        $this->assertTrue($draft->is_visible_to_client);

        // An identical retry is the same draft.
        $this->assertSame($created, $this->createDraft($transport, $body, 'synthetic-parity-create'));
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_omitted_fields_default_as_the_screen_form_does(): void
    {
        $created = $this->createDraft('rest', ['company_id' => $this->company->public_id, 'manual_lines' => [$this->line()]], 'synthetic-parity-defaults');
        $draft = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('public_id', $created['id'])->sole();
        $this->assertNotSame('', (string) $draft->invoice_number);
        $this->assertNull($draft->issue_date);
        $this->assertNull($draft->service_period_start);
        $this->assertNull($draft->service_period_end);
        $this->assertFalse($draft->is_visible_to_client);
    }

    /** Either end of the service period may be given alone, as the screen's form allows. */
    public function test_an_open_ended_service_period_is_accepted(): void
    {
        foreach ([['service_period_start' => '2026-09-01'], ['service_period_end' => '2026-09-30']] as $index => $period) {
            $created = $this->createDraft('rest', ['company_id' => $this->company->public_id, 'manual_lines' => [$this->line()], ...$period], 'synthetic-parity-open-'.$index);
            $draft = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('public_id', $created['id'])->sole();
            $this->assertSame($period['service_period_start'] ?? null, $draft->service_period_start?->toDateString());
            $this->assertSame($period['service_period_end'] ?? null, $draft->service_period_end?->toDateString());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedDrafts(): iterable
    {
        yield 'a period ending before it starts' => [['service_period_start' => '2026-09-30', 'service_period_end' => '2026-09-01']];
        yield 'an ambiguous issue date' => [['issue_date' => '09/30/2026']];
        yield 'a number too long' => [['invoice_number' => str_repeat('n', 81)]];
        yield 'a visibility that is not a boolean' => [['is_visible_to_client' => 'yes']];
        yield 'a field the operation does not take' => [['status' => 'issued']];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedDrafts')]
    public function test_malformed_draft_fields_are_refused_before_anything_is_written(array $change): void
    {
        $this->createDraft('rest', ['company_id' => $this->company->public_id, 'manual_lines' => [$this->line()], ...$change], 'synthetic-parity-refused', 422);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_a_number_already_used_in_the_workspace_is_a_field_error_and_another_workspaces_is_not(): void
    {
        $this->createDraft('rest', ['company_id' => $this->company->public_id, 'manual_lines' => [$this->line()], 'invoice_number' => 'INV-SYN-TAKEN'], 'synthetic-parity-first');
        $this->actingAsMcp($this->owner, [AgentApiScopes::BILLING_WRITE]);
        $this->withHeader('Idempotency-Key', 'synthetic-parity-taken')
            ->postJson($this->url(), ['company_id' => $this->company->public_id, 'manual_lines' => [$this->line()], 'invoice_number' => 'INV-SYN-TAKEN'])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_number');

        // The same number in another tenant is that tenant's business.
        $other = Workspace::query()->create(['name' => 'Synthetic other', 'slug' => 'synthetic-other-'.Str::lower(Str::random(8))]);
        $other->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client']);
        $this->withHeader('Idempotency-Key', 'synthetic-parity-other')
            ->postJson('/api/v1/workspaces/'.$other->public_id.'/invoices', ['company_id' => $otherCompany->public_id, 'manual_lines' => [$this->line()], 'invoice_number' => 'INV-SYN-TAKEN'])
            ->assertCreated();
    }

    public function test_another_workspaces_company_is_not_found(): void
    {
        $other = Workspace::query()->create(['name' => 'Synthetic foreign', 'slug' => 'synthetic-foreign-'.Str::lower(Str::random(8))]);
        $foreign = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign-client']);
        $this->createDraft('rest', ['company_id' => $foreign->public_id, 'manual_lines' => [$this->line()]], 'synthetic-parity-foreign', 404);
        $this->assertSame(0, ClientInvoice::query()->count());
    }

    #[DataProvider('transports')]
    public function test_a_send_takes_the_compose_dialogs_subject_message_and_blind_copy(string $transport): void
    {
        $invoice = $this->issuedInvoice();
        $body = [
            'expected_version' => AgentApiVersion::for($invoice),
            'confirm' => true,
            'recipients' => ['billing@example.test'],
            'subject' => 'Synthetic subject for September',
            'message' => 'Synthetic covering note.',
            'bcc_self' => true,
        ];

        $this->send($transport, $invoice, $body, 'synthetic-parity-send');
        $delivery = ClientInvoiceEmailDelivery::query()->where('workspace_id', $this->workspace->id)->sole();
        $this->assertSame('Synthetic subject for September', $delivery->subject);
        $this->assertSame('Synthetic covering note.', $delivery->body);
        $this->assertSame(['billing@example.test'], $delivery->recipients);
        // The sender's own address, and only theirs.
        $this->assertSame([(string) $this->owner->email], $delivery->bcc);

        // A retry sends nothing more.
        $this->send($transport, $invoice, $body, 'synthetic-parity-send');
        $this->assertSame(1, ClientInvoiceEmailDelivery::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_a_plain_send_keeps_the_default_subject_and_no_blind_copy(): void
    {
        $invoice = $this->issuedInvoice();
        $this->send('rest', $invoice, ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true, 'recipients' => ['billing@example.test']], 'synthetic-parity-plain');
        $delivery = ClientInvoiceEmailDelivery::query()->where('workspace_id', $this->workspace->id)->sole();
        $this->assertStringContainsString((string) $invoice->invoice_number, (string) $delivery->subject);
        $this->assertNull($delivery->body);
        $this->assertSame([], $delivery->bcc);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedSends(): iterable
    {
        yield 'a named blind copy' => [['bcc' => ['someone@example.test']]];
        yield 'a blind-copy flag that is not a boolean' => [['bcc_self' => 'true']];
        yield 'a subject too long' => [['subject' => str_repeat('s', 256)]];
        yield 'a message too long' => [['message' => str_repeat('m', 5001)]];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedSends')]
    public function test_malformed_compose_fields_are_refused_and_nothing_is_sent(array $change): void
    {
        $invoice = $this->issuedInvoice();
        $this->send('rest', $invoice, ['expected_version' => AgentApiVersion::for($invoice), 'confirm' => true, 'recipients' => ['billing@example.test'], ...$change], 'synthetic-parity-bad-send', 422);
        $this->assertSame(0, ClientInvoiceEmailDelivery::query()->where('workspace_id', $this->workspace->id)->count());
    }

    /** The screen and the API compose the same message from the same fields. */
    public function test_the_screen_and_the_api_compose_the_same_delivery(): void
    {
        $viaApi = $this->issuedInvoice();
        $viaScreen = $this->issuedInvoice();
        $fields = ['recipients' => ['billing@example.test'], 'subject' => 'Synthetic same subject', 'message' => 'Synthetic same note.', 'bcc_self' => true];

        $this->send('rest', $viaApi, ['expected_version' => AgentApiVersion::for($viaApi), 'confirm' => true, ...$fields], 'synthetic-parity-api');
        $this->actingAs($this->owner)
            ->post('/workspaces/'.$this->workspace->public_id.'/invoices/'.$viaScreen->public_id.'/send', [...$fields, 'idempotency_key' => 'synthetic-parity-screen'])
            ->assertRedirect();

        $shape = fn (ClientInvoice $invoice): array => ClientInvoiceEmailDelivery::query()->where('workspace_id', $this->workspace->id)
            ->where('client_invoice_id', $invoice->id)->get(['recipients', 'bcc', 'subject', 'body'])->map(fn (ClientInvoiceEmailDelivery $delivery): array => [
                $delivery->recipients, $delivery->bcc, $delivery->subject, $delivery->body,
            ])->all();
        $this->assertSame($shape($viaScreen), $shape($viaApi));
        $this->assertCount(1, $shape($viaApi));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function createDraft(string $transport, array $body, string $key, int $status = 201): array
    {
        $this->actingAsMcp($this->owner, ['mcp:use', AgentApiScopes::BILLING_WRITE]);
        if ($transport === 'rest') {
            return (array) $this->withHeader('Idempotency-Key', $key)->postJson($this->url(), $body)->assertStatus($status)->json('data');
        }

        return $this->tool('invoices.create_draft', [...$body, 'workspace_id' => $this->workspace->public_id, 'idempotency_key' => $key], $status);
    }

    /** @param array<string, mixed> $body */
    private function send(string $transport, ClientInvoice $invoice, array $body, string $key, int $status = 200): void
    {
        $this->actingAsMcp($this->owner, ['mcp:use', AgentApiScopes::BILLING_DELIVER]);
        if ($transport === 'rest') {
            $this->withHeader('Idempotency-Key', $key)->postJson($this->url().'/'.$invoice->public_id.'/send', $body)->assertStatus($status);

            return;
        }
        $this->tool('invoices.send', [...$body, 'workspace_id' => $this->workspace->public_id, 'invoice_id' => $invoice->public_id, 'idempotency_key' => $key], $status);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments, int $status): array
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $session = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic parity test', 'version' => '1']],
        ], $headers)->assertOk()->headers->get('Mcp-Session-Id');
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
            [...$headers, 'Mcp-Session-Id' => (string) $session])->assertOk()->json();
        if ($status >= 300) {
            $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), (string) json_encode($result));

            return [];
        }
        $this->assertFalse($result['result']['isError'] ?? false, (string) json_encode($result));

        return (array) ($result['result']['structuredContent']['data'] ?? []);
    }

    private function issuedInvoice(): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);

        return $service->issue($service->createDraft($this->workspace, $this->company, ['currency' => 'USD', 'invoice_number' => 'INV-SYN-'.Str::upper(Str::random(8))], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $this->workspace);
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        return ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 10000];
    }

    private function url(): string
    {
        return '/api/v1/workspaces/'.$this->workspace->public_id.'/invoices';
    }
}
