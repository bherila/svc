<?php

namespace Tests\Feature\Mcp;

use App\Mail\AdministratorInvoiceIssuedMail;
use App\Mail\InvoiceMail;
use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceAdministratorNotification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * `invoices.issue` runs through `IssueInvoiceAction`, and can record money
 * already collected elsewhere in the same transaction.
 *
 * The reason for folding the payment in is the automatic client delivery:
 * issuing schedules it and recording a payment cancels it. Done as two calls,
 * the dispatcher can run between them and email a client an unpaid invoice
 * for money they already paid. Done as one, it never sees that state.
 */
final class AgentMcpInvoiceIssueTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const FULL_SCOPES = [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::BILLING_READ,
        AgentApiScopes::BILLING_DELIVER,
        AgentApiScopes::PAYMENTS_RECORD,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.invoice_writes_enabled' => true,
            'agent_api.payment_writes_enabled' => true,
        ]);
    }

    public function test_issue_returns_the_invoice_as_invoices_get_does_and_replays(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $arguments = $this->issueArguments($workspace, $draft, 'issue-plain');

        $issued = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertFalse($issued['result']['isError'] ?? true, json_encode($issued));
        $data = $issued['result']['structuredContent']['data'];
        $this->assertSame('issued', $data['status']);
        $this->assertSame(10000, $data['balance_amount']);

        $read = $this->callTool($session, 'invoices.get', ['workspace_id' => $workspace->public_id, 'invoice_id' => $draft->public_id]);
        $this->assertSame($read['result']['structuredContent'], $issued['result']['structuredContent']);

        $replay = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertSame($issued['result']['structuredContent'], $replay['result']['structuredContent']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.issue', 'outcome' => 'success']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.issue', 'outcome' => 'replay']);
        $this->assertDatabaseMissing('agent_mutation_audits', ['operation' => 'payments.record']);
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    /** Without billing:read, or with invoices.get switched off, the response is the plain mutation shape. */
    public function test_issue_returns_the_mutation_shape_without_the_read(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $session = $this->mcpSession($user, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_DELIVER]);
        $issued = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-no-read'));
        $data = $issued['result']['structuredContent']['data'];
        $this->assertSame(['id', 'status', 'linked_time_state', 'invoice_number', 'version', 'web_url'], array_keys($data));
        $this->assertSame('issued', $data['status']);

        [$user, $workspace, , $draft] = $this->fixture();
        config(['agent_api.mcp_feature_flags' => ['invoices.get' => false]]);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $issued = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-read-off'));
        $this->assertArrayNotHasKey('total_amount', $issued['result']['structuredContent']['data']);
    }

    /**
     * The case this exists for: a draft for money a card autopay already took.
     *
     * The company sends invoices automatically with no delay, so a plain issue
     * would be emailed on the dispatcher's next run. Issued and paid together,
     * nothing reaches the client and the invoice ends paid.
     */
    public function test_issue_with_payment_ends_paid_and_the_dispatcher_sends_the_client_nothing(): void
    {
        Mail::fake();
        [$user, $workspace, $company, $draft] = $this->fixture(automaticEmail: true);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $arguments = $this->issueArguments($workspace, $draft, 'issue-paid', $this->payment());

        $paid = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertFalse($paid['result']['isError'] ?? true, json_encode($paid));
        $data = $paid['result']['structuredContent']['data'];
        $this->assertSame('paid', $data['status']);
        $this->assertSame(10000, $data['paid_amount']);
        $this->assertSame(0, $data['balance_amount']);
        $read = $this->callTool($session, 'invoices.get', ['workspace_id' => $workspace->public_id, 'invoice_id' => $draft->public_id]);
        $this->assertSame($read['result']['structuredContent'], $paid['result']['structuredContent']);

        $invoice = $draft->fresh();
        $this->assertSame('cancelled', $invoice->automatic_delivery_status);
        $this->assertDatabaseHas('client_invoice_payments', [
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'amount' => 10000,
            'currency' => 'USD',
            'method' => 'Credit Card',
            'reference' => 'synthetic-autopay-1',
            'status' => 'succeeded',
            'provider' => null,
        ]);
        $this->assertSame(now()->toDateString(), $invoice->payments()->sole()->received_on->toDateString());
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.issue', 'outcome' => 'success']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'payments.record', 'outcome' => 'success']);
        $this->assertSame(1, AgentMutationReceipt::query()->where('operation', 'invoices.issue')->where('status', 'completed')->count());

        Artisan::call('svc:billing:dispatch-invoice-emails');
        Mail::assertNotSent(InvoiceMail::class);
        $this->assertDatabaseMissing('client_invoice_email_deliveries', ['client_invoice_id' => $invoice->id, 'origin' => 'automatic']);

        $replay = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertSame($paid['result']['structuredContent'], $replay['result']['structuredContent']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'payments.record', 'outcome' => 'replay']);
        $this->assertDatabaseCount('client_invoice_payments', 1);

        // The payment is part of the request: the same key without it is a different request.
        unset($arguments['payment']);
        $changed = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertArrayHasKey('error', $changed);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame($company->id, $invoice->client_company_id);
    }

    /**
     * The administrator's copy is the paid document, not the unpaid one issue() rendered.
     *
     * issue() snapshots the PDF for the administrator notice inside the
     * transaction, before the folded payment lands; the notice is sent after
     * commit from the stored bytes. Each stored snapshot is recorded with the
     * invoice state it was taken in, and the emailed bytes must be the one
     * taken once the invoice was paid.
     */
    public function test_the_administrator_notice_carries_the_paid_document(): void
    {
        Mail::fake();
        [$user, $workspace, , $draft] = $this->fixture();
        $snapshots = [];
        ClientInvoiceAdministratorNotification::saving(function (ClientInvoiceAdministratorNotification $notification) use (&$snapshots): void {
            if ($notification->isDirty('pdf_content_base64') && $notification->pdf_content_base64 !== null) {
                $state = DB::table('client_invoices')->where('id', $notification->client_invoice_id)->first(['status', 'balance_amount']);
                $snapshots[$notification->pdf_content_base64] = [$state->status, (int) $state->balance_amount];
            }
        });
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-admin-copy', $this->payment()));

        Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
        $mail = Mail::sent(AdministratorInvoiceIssuedMail::class)->sole();
        $pdf = (fn (): string => $this->invoicePdf)->call($mail);
        $this->assertSame(['paid', 0], $snapshots[base64_encode($pdf)] ?? null);
    }

    /** A plain issue keeps the snapshot it took at issue. */
    public function test_a_plain_issue_sends_the_document_it_issued(): void
    {
        Mail::fake();
        [$user, $workspace, , $draft] = $this->fixture();
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-admin-plain'));

        Mail::assertSent(AdministratorInvoiceIssuedMail::class, 1);
        $notification = ClientInvoiceAdministratorNotification::query()->where('client_invoice_id', $draft->id)->sole();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->attempt_count);
    }

    /**
     * If the paid document cannot be rendered, the unpaid one is not sent in its place.
     *
     * Issuing and paying still commit - a document failure never blocks
     * issuance - and the notice is left for delivery to render from the
     * committed invoice, which here fails again and is retried later.
     */
    public function test_a_failed_resnapshot_never_sends_the_unpaid_document(): void
    {
        Mail::fake();
        [$user, $workspace, , $draft] = $this->fixture();
        View::composer('invoices.show', function ($view): void {
            if ($view->getData()['invoice']->status === 'paid') {
                throw new \RuntimeException('Synthetic render failure.');
            }
        });
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $result = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-admin-fail', $this->payment()));

        $this->assertSame('paid', $result['result']['structuredContent']['data']['status'] ?? null, json_encode($result));
        Mail::assertNotSent(AdministratorInvoiceIssuedMail::class);
        $notification = ClientInvoiceAdministratorNotification::query()->where('client_invoice_id', $draft->id)->sole();
        $this->assertNull($notification->pdf_content_base64);
        $this->assertSame('failed', $notification->status);
    }

    /** The control for the test above: the fixture does schedule a delivery the dispatcher sends. */
    public function test_a_plain_issue_for_the_same_company_is_delivered_by_the_dispatcher(): void
    {
        Mail::fake();
        [$user, $workspace, , $draft] = $this->fixture(automaticEmail: true);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-control'));
        $this->assertSame('scheduled', $draft->fresh()->automatic_delivery_status);
        // Issuing itself sent the client nothing.
        Mail::assertNotSent(InvoiceMail::class);

        Artisan::call('svc:billing:dispatch-invoice-emails');
        Mail::assertSent(InvoiceMail::class, 1);
    }

    /** A partial payment is recorded as such and still cancels the automatic delivery. */
    public function test_a_partial_payment_leaves_the_invoice_partially_paid(): void
    {
        [$user, $workspace, , $draft] = $this->fixture(automaticEmail: true);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $result = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-partial', ['amount' => 4000] + $this->payment()));
        $this->assertSame('partially_paid', $result['result']['structuredContent']['data']['status']);
        $this->assertSame(6000, $result['result']['structuredContent']['data']['balance_amount']);
        $this->assertSame('cancelled', $draft->fresh()->automatic_delivery_status);
    }

    /**
     * Every refusal writes nothing: the invoice stays a draft, no payment, no
     * receipt, no scheduled delivery, no administrator notice.
     *
     * @return iterable<string, array{0: \Closure(self): void, 1: array<string, mixed>, 2?: list<string>}>
     */
    public static function refusals(): iterable
    {
        $none = static function (self $test): void {};
        yield 'payment cutover off' => [static fn () => config(['agent_api.payment_writes_enabled' => false]), []];
        yield 'outer cutover off' => [static fn () => config(['agent_api.writes_enabled' => false]), []];
        yield 'invoice cutover off' => [static fn () => config(['agent_api.invoice_writes_enabled' => false]), []];
        yield 'payments.record kill switch' => [static fn () => config(['agent_api.mcp_feature_flags' => ['payments.record' => false]]), []];
        yield 'invoices.issue kill switch' => [static fn () => config(['agent_api.mcp_feature_flags' => ['invoices.issue' => false]]), []];
        yield 'without payments:record' => [$none, [], [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ, AgentApiScopes::BILLING_DELIVER]];
        yield 'without billing:deliver' => [$none, [], [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ, AgentApiScopes::PAYMENTS_RECORD]];
        yield 'overpayment' => [$none, ['payment' => ['amount' => 10001]]];
        yield 'currency mismatch' => [$none, ['payment' => ['currency' => 'EUR']]];
        yield 'future payment date' => [$none, ['payment' => ['received_on' => '2099-01-01']]];
        yield 'stale version' => [$none, ['expected_version' => str_repeat('0', 64)]];
        yield 'unconfirmed' => [$none, ['confirm' => false]];
    }

    /**
     * @param  \Closure(self): void  $arrange
     * @param  array<string, mixed>  $change
     * @param  list<string>|null  $scopes
     */
    #[DataProvider('refusals')]
    public function test_each_gate_refuses_and_writes_nothing(\Closure $arrange, array $change, ?array $scopes = null): void
    {
        [$user, $workspace, , $draft] = $this->fixture(automaticEmail: true);
        $arrange($this);
        $session = $this->mcpSession($user, $scopes ?? self::FULL_SCOPES);
        $arguments = $this->issueArguments($workspace, $draft, 'issue-refused', $this->payment());
        if (isset($change['payment'])) {
            $arguments['payment'] = $change['payment'] + $arguments['payment'];
            unset($change['payment']);
        }
        $result = $this->callTool($session, 'invoices.issue', [...$arguments, ...$change]);

        $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), json_encode($result));
        $this->assertNothingWritten($draft);
    }

    /** Only workspace owners and admins may issue, with or without a payment. */
    public function test_a_member_cannot_issue_or_pay(): void
    {
        [$user, $workspace, , $draft] = $this->fixture(automaticEmail: true);
        $workspace->memberships()->where('user_id', $user->id)->update(['role' => 'member']);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        foreach (['member-pay' => $this->payment(), 'member-plain' => null] as $key => $payment) {
            $result = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, $key, $payment));
            $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), json_encode($result));
        }
        $this->assertNothingWritten($draft);
    }

    /** Folding a payment into issue is for drafts; an issued invoice takes payments.record. */
    public function test_a_payment_is_refused_on_an_invoice_that_is_already_issued(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $issued = app(InvoiceLifecycleService::class)->issue($draft, $workspace);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $result = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $issued, 'issue-already', $this->payment()));
        $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), json_encode($result));
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertSame('issued', $issued->fresh()->status);
    }

    /**
     * Another request issues the draft between the action's read and the
     * transition's row lock.
     *
     * The one-shot listener issues the invoice through the lifecycle as soon as
     * the action first reads it, which is exactly the window a concurrent issuer
     * has. issue() then finds a charged row and returns it as a no-op, so a
     * check made on the earlier read would let the payment through on an
     * invoice this request never issued - past both expected_version and the
     * draft-only rule.
     */
    public function test_the_draft_and_version_checks_are_made_on_the_locked_row(): void
    {
        [$user, $workspace, , $draft] = $this->fixture(automaticEmail: true);
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $arguments = $this->issueArguments($workspace, $draft, 'issue-raced', $this->payment());
        $raced = false;
        ClientInvoice::retrieved(function (ClientInvoice $invoice) use (&$raced, $workspace): void {
            if ($raced || $invoice->status !== 'draft') {
                return;
            }
            $raced = true;
            app(InvoiceLifecycleService::class)->issue(ClientInvoice::query()->whereKey($invoice->id)->firstOrFail(), $workspace);
        });

        $result = $this->callTool($session, 'invoices.issue', $arguments);

        $this->assertTrue($raced);
        $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), json_encode($result));
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertSame('conflict', AgentMutationAudit::query()->where('operation', 'invoices.issue')->latest('id')->value('error_category'));
    }

    /** An owner of two workspaces cannot reach one workspace's draft through the other. */
    public function test_a_draft_in_another_workspace_is_not_found(): void
    {
        [$user, $workspace, , $draft] = $this->fixture(automaticEmail: true);
        [, $other, , $foreign] = $this->fixture(automaticEmail: true);
        $other->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $session = $this->mcpSession($user, self::FULL_SCOPES);

        $result = $this->callTool($session, 'invoices.issue', ['invoice_id' => $foreign->public_id] + $this->issueArguments($workspace, $foreign, 'issue-foreign', $this->payment()));
        $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), json_encode($result));
        $this->assertNothingWritten($foreign);
        $this->assertNothingWritten($draft);

        // Not found before anything about the record is compared: a wrong
        // version must not turn the foreign invoice into a conflict.
        $this->callTool($session, 'invoices.issue', ['expected_version' => str_repeat('0', 64)] + $this->issueArguments($workspace, $foreign, 'issue-foreign-stale'));
        $this->assertSame('not_found', AgentMutationAudit::query()->where('workspace_id', $workspace->id)->latest('id')->value('error_category'));
    }

    public static function revocations(): iterable
    {
        yield 'role demoted' => ['role'];
        yield 'payments:record withdrawn' => ['scope'];
        yield 'payment cutover off' => ['flag'];
        yield 'payments.record switched off' => ['switch'];
    }

    /** A completed receipt is not replayed to a caller who no longer holds every gate. */
    #[DataProvider('revocations')]
    public function test_replay_rechecks_authorization(string $revoke): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $arguments = $this->issueArguments($workspace, $draft, 'issue-revoked', $this->payment());
        $this->assertFalse($this->callTool($session, 'invoices.issue', $arguments)['result']['isError'] ?? true);

        match ($revoke) {
            'role' => $workspace->memberships()->where('user_id', $user->id)->update(['role' => 'member']),
            'scope' => null,
            'flag' => config(['agent_api.payment_writes_enabled' => false]),
            'switch' => config(['agent_api.mcp_feature_flags' => ['payments.record' => false]]),
        };
        $session = $this->mcpSession($user, $revoke === 'scope'
            ? [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ, AgentApiScopes::BILLING_DELIVER]
            : self::FULL_SCOPES);

        $replay = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertTrue(isset($replay['error']) || ($replay['result']['isError'] ?? false), json_encode($replay));
        $this->assertDatabaseMissing('agent_mutation_audits', ['operation' => 'invoices.issue', 'outcome' => 'replay']);
        $this->assertDatabaseCount('client_invoice_payments', 1);
    }

    /** A receipt whose payment is no longer the invoice's is not replayed as if it were. */
    public function test_replay_refuses_a_receipt_whose_payment_is_gone(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $arguments = $this->issueArguments($workspace, $draft, 'issue-gone', $this->payment());
        $this->callTool($session, 'invoices.issue', $arguments);
        $draft->payments()->delete();

        $replay = $this->callTool($session, 'invoices.issue', $arguments);
        $this->assertArrayHasKey('error', $replay);
        $this->assertDatabaseMissing('agent_mutation_audits', ['operation' => 'invoices.issue', 'outcome' => 'replay']);
    }

    /** REST and MCP share one receipt, so a retry may switch transport; an omitted reference means null in both. */
    public function test_a_retry_may_switch_from_rest_to_mcp(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $payment = $this->payment();
        unset($payment['reference']);
        $version = AgentApiVersion::for($draft);
        $this->actingAsMcp($user, self::FULL_SCOPES);
        $this->withHeader('Idempotency-Key', 'issue-cross-door')
            ->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft->public_id}/issue", [
                'expected_version' => $version, 'confirm' => true, 'payment' => $payment,
            ])->assertOk()->assertJsonPath('data.status', 'paid');

        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $replay = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-cross-door', [...$payment, 'reference' => null], $version));
        $this->assertSame('paid', $replay['result']['structuredContent']['data']['status'], json_encode($replay));
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame(1, AgentMutationReceipt::query()->where('operation', 'invoices.issue')->where('status', 'completed')->count());
    }

    /** A plain MCP issue hashes exactly as the REST route did, so receipts written before the migration still replay. */
    public function test_a_plain_issue_receipt_from_rest_replays_over_mcp(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $version = AgentApiVersion::for($draft);
        $this->actingAsMcp($user, self::FULL_SCOPES);
        $this->withHeader('Idempotency-Key', 'issue-legacy')
            ->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft->public_id}/issue", [
                'expected_version' => $version, 'confirm' => true,
            ])->assertOk();

        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $replay = $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'issue-legacy', null, $version));
        $this->assertSame('issued', $replay['result']['structuredContent']['data']['status'] ?? null, json_encode($replay));
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.issue', 'outcome' => 'replay']);
    }

    /**
     * The payment's domain idempotency key is namespaced by operation.
     *
     * Reusing a key across two different tools is two different requests.
     * Without the operation in the namespace, a later payments.record with
     * the same key and amount would silently return this payment instead of
     * recording the second one it was asked to.
     */
    public function test_a_payments_record_call_reusing_the_key_records_its_own_payment(): void
    {
        [$user, $workspace, , $draft] = $this->fixture();
        $session = $this->mcpSession($user, self::FULL_SCOPES);
        $payment = ['amount' => 4000] + $this->payment();
        $this->callTool($session, 'invoices.issue', $this->issueArguments($workspace, $draft, 'shared-key', $payment));
        $recorded = $this->callTool($session, 'payments.record', [
            'workspace_id' => $workspace->public_id, 'invoice_id' => $draft->public_id, 'idempotency_key' => 'shared-key', ...$payment,
        ]);
        $this->assertFalse($recorded['result']['isError'] ?? true, json_encode($recorded));
        $this->assertDatabaseCount('client_invoice_payments', 2);
        $this->assertSame(2000, $draft->fresh()->balance_amount);
    }

    public function test_the_payment_option_is_advertised_in_the_input_schema_and_instructions(): void
    {
        [$user] = $this->fixture();
        $this->actingAsMcp($user, self::FULL_SCOPES);
        $init = $this->mcp($this->initializeMessage())->assertOk();
        $this->assertStringContainsString('pass payment to invoices.issue', (string) $init->json('result.instructions'));
        $this->assertStringContainsString('payments.record records money already received', (string) $init->json('result.instructions'));
        $session = (string) $init->headers->get('Mcp-Session-Id');
        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->json('result.tools'))->keyBy('name');
        $schema = $tools->get('invoices.issue')['inputSchema'];
        $this->assertSame(['expected_version', 'confirm'], array_values(array_intersect(['expected_version', 'confirm'], $schema['required'])));
        $this->assertNotContains('payment', $schema['required']);
        $this->assertSame(['amount', 'currency', 'received_on', 'method'], $schema['$defs']['InvoiceIssuePayment']['required']);

        // Without the payment tool the option is not suggested.
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ, AgentApiScopes::BILLING_DELIVER]);
        $init = $this->mcp($this->initializeMessage())->assertOk();
        $this->assertStringNotContainsString('pass payment to invoices.issue', (string) $init->json('result.instructions'));
    }

    /**
     * The invoice prompt is offered without invoices.issue, so it must not steer toward it.
     *
     * prepare-invoice-safely needs billing:write, not billing:deliver. The
     * folded-payment advice lives in the server instructions, which are built
     * from the tools this connection actually has.
     */
    public function test_a_connection_without_issue_is_not_told_to_fold_a_payment_into_it(): void
    {
        [$user] = $this->fixture();
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ, AgentApiScopes::TIME_READ,
            AgentApiScopes::BILLING_READ, AgentApiScopes::BILLING_WRITE, AgentApiScopes::PAYMENTS_RECORD,
        ]);
        $init = $this->mcp($this->initializeMessage())->assertOk();
        $session = (string) $init->headers->get('Mcp-Session-Id');
        $this->assertNotContains('invoices.issue', $this->toolNames($session));
        $this->assertContains('payments.record', $this->toolNames($session));
        $this->assertStringNotContainsString('invoices.issue', (string) $init->json('result.instructions'));

        $prompt = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => ['name' => 'prepare-invoice-safely']], $session)
            ->assertOk()->json('result.messages.0.content.text');
        $this->assertIsString($prompt);
        $this->assertStringContainsString('payments.record', $prompt);
        $this->assertStringNotContainsString('invoices.issue', $prompt);
    }

    private function assertNothingWritten(ClientInvoice $draft): void
    {
        $invoice = $draft->fresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->automatic_delivery_status);
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertDatabaseMissing('agent_mutation_receipts', ['workspace_id' => $invoice->workspace_id, 'status' => 'completed']);
        $this->assertDatabaseMissing('client_invoice_administrator_notifications', ['client_invoice_id' => $invoice->id]);
    }

    /** @return array<string, mixed> */
    private function payment(): array
    {
        return ['amount' => 10000, 'currency' => 'USD', 'received_on' => now()->toDateString(), 'method' => 'Credit Card', 'reference' => 'synthetic-autopay-1'];
    }

    /**
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private function issueArguments(Workspace $workspace, ClientInvoice $invoice, string $key, ?array $payment = null, ?string $version = null): array
    {
        return [
            'workspace_id' => $workspace->public_id,
            'invoice_id' => $invoice->public_id,
            'expected_version' => $version ?? AgentApiVersion::for($invoice->fresh()),
            'confirm' => true,
            'idempotency_key' => $key,
        ] + ($payment === null ? [] : ['payment' => $payment]);
    }

    /** @param list<string> $scopes */
    private function mcpSession(User $user, array $scopes): string
    {
        $this->actingAsMcp($user, $scopes);

        return $this->initialize();
    }

    /** @return array{User, Workspace, ClientCompany, ClientInvoice} */
    private function fixture(bool $automaticEmail = false): array
    {
        $user = User::factory()->create(['email' => 'synthetic-'.str()->uuid().'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic issue', 'slug' => 'synthetic-'.str()->uuid()]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic client',
            'slug' => 'synthetic-client-'.str()->random(8),
            'billing_email' => 'billing@example.test',
        ]);
        if ($automaticEmail) {
            $company->forceFill(['automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 0])->save();
        }
        $draft = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid()], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]);

        return [$user, $workspace, $company, $draft];
    }
}
