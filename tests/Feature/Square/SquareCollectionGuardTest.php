<?php

namespace Tests\Feature\Square;

use App\Exceptions\CollectionBelongsToSquare;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Square\SquareCollectionGuard;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Square collects what Square bills (#438), at every client-facing collection
 * path {@see SquareCollectionGuard::SITES} names.
 *
 * Each path is driven from its real entry point - the website, REST, MCP, the
 * console command the scheduler runs - for both reasons an invoice is
 * Square-collected: an active link to a Square invoice, and an agreement
 * marked as billed in Square. `SquareCollectionSitesTest` proves nothing else
 * in the application reaches a collection primitive.
 */
final class SquareCollectionGuardTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read', 'billing:write', 'billing:deliver'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 12:00:00');
        Mail::fake();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
    }

    /** @return iterable<string, array{string}> */
    public static function reasons(): iterable
    {
        yield 'linked to a Square invoice' => ['linked'];
        yield 'agreement billed in Square' => ['agreement'];
    }

    #[DataProvider('reasons')]
    public function test_the_website_send_is_refused(string $reason): void
    {
        [$workspace, $owner, , $invoice] = $this->squareCollected($reason);

        $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send", ['recipients' => ['billing@synthetic.example.test']])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Square collects it'));

        $this->assertNothingCollected($invoice);
    }

    /** @return iterable<string, array{string, string}> */
    public static function agentTransports(): iterable
    {
        foreach (['rest', 'mcp'] as $transport) {
            foreach (['linked', 'agreement'] as $reason) {
                yield "{$transport}, {$reason}" => [$transport, $reason];
            }
        }
    }

    #[DataProvider('agentTransports')]
    public function test_invoices_send_is_refused_over_rest_and_mcp(string $transport, string $reason): void
    {
        [$workspace, $owner, , $invoice] = $this->squareCollected($reason);
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($invoice->refresh()), 'recipients' => ['billing@synthetic.example.test'], 'confirm' => true];

        if ($transport === 'rest') {
            $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send", $body, ['Idempotency-Key' => 'synthetic-send'])
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Square collects it'));
        } else {
            $result = $this->callTool($this->initialize(), 'invoices.send', ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id, 'idempotency_key' => 'synthetic-send', ...$body]);
            $this->assertTrue($result['result']['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('Square collects it', json_encode($result, JSON_THROW_ON_ERROR));
        }

        $this->assertNothingCollected($invoice);
    }

    #[DataProvider('reasons')]
    public function test_a_keyed_retry_of_a_failed_send_is_refused(string $reason): void
    {
        [$workspace, , , $invoice] = $this->issuedInvoice();
        $draft = InvoiceEmailDraft::of(['billing@synthetic.example.test'], [], 'Invoice SYN-1', null);
        ClientInvoiceEmailDelivery::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'origin' => 'manual',
            'invoice_revision' => $invoice->document_revision, 'attempt_number' => 1, 'idempotency_key' => 'synthetic-retry',
            'recipients' => $draft->recipients, 'subject' => $draft->subject, 'status' => 'failed', 'queued_at' => now(), 'failed_at' => now(),
        ]);
        $this->makeSquareCollected($reason, $workspace, $invoice);

        $this->expectException(CollectionBelongsToSquare::class);
        try {
            app(InvoiceEmailService::class)->send($invoice->refresh(), $draft, $workspace, 'synthetic-retry');
        } finally {
            Mail::assertNothingSent();
        }
    }

    #[DataProvider('reasons')]
    public function test_an_email_registered_before_the_link_is_not_sent_after_commit(string $reason): void
    {
        [$workspace, , , $invoice] = $this->issuedInvoice();
        $draft = InvoiceEmailDraft::of(['billing@synthetic.example.test'], [], 'Invoice SYN-1', null);
        $this->makeSquareCollected($reason, $workspace, $invoice);
        // Registered as sendAfterCommit() registers it, by an API transaction
        // whose snapshot predates the hand-off, and sent once that commits.
        $delivery = ClientInvoiceEmailDelivery::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'origin' => 'manual',
            'invoice_revision' => $invoice->refresh()->document_revision, 'attempt_number' => 1,
            'recipients' => $draft->recipients, 'subject' => $draft->subject, 'status' => 'pending', 'queued_at' => now(),
        ]);

        app(InvoiceEmailService::class)->deliverRegistered($invoice, $delivery->id, $draft);

        Mail::assertNothingSent();
        $this->assertSame('failed', $delivery->refresh()->status);
        $this->assertSame('Not sent: Square collects this invoice.', $delivery->error_summary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function deliveryOrigins(): iterable
    {
        foreach (['manual', 'automatic'] as $origin) {
            foreach (['linked', 'agreement'] as $reason) {
                yield "{$origin}, {$reason}" => [$origin, $reason];
            }
        }
    }

    /**
     * A hand-off committed after a delivery was claimed but before the message
     * left: the claim commits outside the invoice lock, so the last check is
     * made in deliver() itself, and nothing is sent.
     */
    #[DataProvider('deliveryOrigins')]
    public function test_a_hand_off_between_claim_and_send_stops_the_email(string $origin, string $reason): void
    {
        [$workspace, , $company, $invoice, $agreement] = $this->issuedInvoice();
        if ($origin === 'automatic') {
            $company->forceFill(['automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 0])->save();
            $invoice->forceFill(['automatic_delivery_status' => 'scheduled', 'automatic_delivery_delay_days' => 0, 'automatic_delivery_due_at' => now()->subMinute()])->save();
        }
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $reason, $workspace, $invoice, $agreement): void {
            // Unquoted, so it matches SQLite's "..." and MariaDB's `...` alike.
            $sql = str_replace(['"', '`'], '', strtolower($query->sql));
            if (! $armed || ! str_starts_with($sql, 'update client_invoice_email_deliveries') && ! str_starts_with($sql, 'insert into client_invoice_email_deliveries')) {
                return;
            }
            $armed = false;
            // Written as a concurrent request would leave it, without that
            // request's checks: they are what the hand-off side tests cover.
            if ($reason === 'linked') {
                InvoiceSquareLink::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'square_invoice_id' => 'inv:0-fixturelate', 'square_invoice_number' => '000009-R-0001', 'series_key' => '000009', 'currency' => 'USD', 'match_method' => 'manual', 'active_invoice_slot' => $invoice->id, 'active_square_invoice_slot' => 'inv:0-fixturelate', 'linked_at' => now()]);
            } else {
                $agreement->forceFill(['square_series_key' => '000009'])->save();
            }
        });
        $draft = InvoiceEmailDraft::of(['billing@synthetic.example.test'], [], 'Invoice SYN-1', null);

        if ($origin === 'manual') {
            try {
                app(InvoiceEmailService::class)->send($invoice, $draft, $workspace);
                $this->fail('The manual send went out after the hand-off.');
            } catch (CollectionBelongsToSquare $refused) {
                $this->assertSame('Not sent: Square collects this invoice.', $refused->getMessage());
            }
        } else {
            app(InvoiceEmailService::class)->sendAutomatic($invoice);
            $this->assertSame('cancelled', $invoice->refresh()->automatic_delivery_status);
        }

        Mail::assertNothingSent();
        $delivery = ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Not sent: Square collects this invoice.', $delivery->error_summary);
    }

    #[DataProvider('reasons')]
    public function test_the_scheduled_dispatcher_cancels_automatic_delivery(string $reason): void
    {
        [$workspace, , , $invoice] = $this->issuedInvoice();
        $invoice->forceFill(['automatic_delivery_status' => 'scheduled', 'automatic_delivery_delay_days' => 0, 'automatic_delivery_due_at' => now()->subMinute()])->save();
        $this->makeSquareCollected($reason, $workspace, $invoice);

        $commands = array_map(static fn (Event $event): string => (string) $event->command, app(Schedule::class)->events());
        $this->assertNotEmpty(array_filter($commands, static fn (string $command): bool => str_contains($command, 'svc:billing:dispatch-invoice-emails')), 'The scheduler runs the dispatcher');
        Artisan::call('svc:billing:dispatch-invoice-emails', ['--limit' => 50]);

        Mail::assertNothingSent();
        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->automatic_delivery_status);
        $this->assertSame('Square collects this invoice, so SVC does not email it to the client.', $invoice->automatic_delivery_note);
        $this->assertSame(0, ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)->count());
    }

    /** @return iterable<string, array{string, string}> */
    public static function issueTransports(): iterable
    {
        foreach (['web', 'rest'] as $transport) {
            foreach (['linked', 'agreement'] as $reason) {
                yield "{$transport}, {$reason}" => [$transport, $reason];
            }
        }
    }

    #[DataProvider('issueTransports')]
    public function test_issuing_still_works_but_schedules_no_client_email(string $transport, string $reason): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $company->forceFill(['automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 2])->save();
        $this->makeSquareCollected($reason, $workspace, $invoice);

        if ($transport === 'web') {
            $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/issue")->assertOk();
        } else {
            $this->actingAsMcp($owner, self::SCOPES);
            $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/issue", ['expected_version' => AgentApiVersion::for($invoice->refresh()), 'confirm' => true], ['Idempotency-Key' => 'synthetic-issue'])->assertOk();
        }

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('cancelled', $invoice->automatic_delivery_status);
        $this->assertNull($invoice->automatic_delivery_due_at);
        $this->assertSame('Square collects this invoice, so SVC does not email it to the client.', $invoice->automatic_delivery_note);
    }

    #[DataProvider('issueTransports')]
    public function test_releasing_a_held_delivery_is_refused(string $transport, string $reason): void
    {
        [$workspace, $owner, , $invoice] = $this->issuedInvoice();
        $invoice->forceFill(['automatic_delivery_status' => 'held', 'automatic_delivery_delay_days' => 0, 'automatic_delivery_due_at' => now()->addDay()])->save();
        $this->makeSquareCollected($reason, $workspace, $invoice);

        if ($transport === 'web') {
            $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/automatic-delivery/release")->assertStatus(422);
        } else {
            $this->actingAsMcp($owner, self::SCOPES);
            $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/automatic-delivery/release", ['expected_version' => AgentApiVersion::for($invoice->refresh()), 'confirm' => true], ['Idempotency-Key' => 'synthetic-release'])
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Square collects it'));
        }

        $this->assertSame('held', $invoice->refresh()->automatic_delivery_status);
    }

    #[DataProvider('reasons')]
    public function test_the_client_cannot_start_a_payment(string $reason): void
    {
        [$workspace, , $company, $invoice] = $this->squareCollected($reason);
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->actingAs($client)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/stripe-payment-intent", ['idempotency_key' => 'synthetic-intent'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Square collects it'));

        $this->assertNothingCollected($invoice);
    }

    /**
     * The null branch of the marker: an agreement nobody marked leaves its
     * invoices to SVC, and the ordinary send goes out.
     */
    public function test_an_invoice_of_an_unmarked_agreement_is_collected_by_svc(): void
    {
        [$workspace, $owner, , $invoice, $agreement] = $this->issuedInvoice();
        $this->assertNull($agreement->refresh()->square_series_key);
        $this->assertSame($agreement->id, (int) $invoice->client_agreement_id);
        $this->assertNull(app(SquareCollectionGuard::class)->refusal($invoice));

        $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send", ['recipients' => ['billing@synthetic.example.test']])
            ->assertOk();

        $this->assertSame(1, ClientInvoiceEmailDelivery::query()->where('workspace_id', $workspace->id)->where('status', 'sent')->count());
    }

    /** Unlinking, or clearing the marker, hands collection back to SVC. */
    #[DataProvider('reasons')]
    public function test_undoing_the_reason_restores_svc_collection(string $reason): void
    {
        [$workspace, $owner, , $invoice, $agreement] = $this->squareCollected($reason);
        $links = app(SquareLinkService::class);
        if ($reason === 'linked') {
            $links->unlink($workspace, $invoice, $owner, AgentApiVersion::for($invoice->refresh()));
        } else {
            $links->clearAgreement($workspace, $agreement, $owner, AgentApiVersion::for($agreement->refresh()));
        }

        $this->assertNull(app(SquareCollectionGuard::class)->refusal($invoice->refresh()));
        $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send", ['recipients' => ['billing@synthetic.example.test']])
            ->assertOk();
    }

    /** Recording a payment Square collected is bookkeeping, not collection. */
    #[DataProvider('reasons')]
    public function test_recording_a_payment_stays_open(string $reason): void
    {
        [$workspace, $owner, , $invoice] = $this->squareCollected($reason);

        $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments", [
                'amount' => 5000, 'currency' => 'USD', 'received_on' => '2026-10-08', 'method' => 'card', 'reference' => 'Synthetic Square payment',
            ])
            ->assertCreated();

        $this->assertSame('paid', $invoice->refresh()->status);
    }

    private function assertNothingCollected(ClientInvoice $invoice): void
    {
        Mail::assertNothingSent();
        $this->assertSame(0, ClientInvoiceEmailDelivery::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->count());
        $this->assertSame(0, ClientInvoicePayment::query()->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->count());
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice, ClientAgreement} */
    private function squareCollected(string $reason): array
    {
        $fixture = $this->issuedInvoice();
        $this->makeSquareCollected($reason, $fixture[0], $fixture[3]);

        return $fixture;
    }

    private function makeSquareCollected(string $reason, Workspace $workspace, ClientInvoice $invoice): void
    {
        $owner = User::query()->whereKey($workspace->memberships()->where('role', 'owner')->value('user_id'))->firstOrFail();
        $links = app(SquareLinkService::class);
        if ($reason === 'linked') {
            $links->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice->refresh()), new SquareInvoiceReference('inv:0-fixture1', '000001-R-0001', 'USD', SquareMatchMethod::Manual), false);
        } else {
            $agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_agreement_id)->firstOrFail();
            $links->markAgreement($workspace, $agreement, $owner, AgentApiVersion::for($agreement), '000001');
        }
        $this->assertNotNull(app(SquareCollectionGuard::class)->refusal($invoice->refresh()));
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice, ClientAgreement} */
    private function issuedInvoice(): array
    {
        $fixture = $this->fixture();
        $fixture[3]->forceFill(['status' => 'issued', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'issued_at' => now(), 'document_revision' => 1, 'is_visible_to_client' => true])->save();

        return $fixture;
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice, ClientAgreement} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Square Guard', 'slug' => 'synthetic-square-guard-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Square Client', 'slug' => 'synthetic-square-client', 'billing_email' => 'billing@synthetic.example.test']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Monthly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 5000]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id, 'invoice_number' => 'SYN-1', 'invoice_kind' => 'ad_hoc', 'status' => 'draft', 'currency' => 'USD', 'subtotal_amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000, 'paid_amount' => 0, 'balance_amount' => 5000]);

        return [$workspace, $owner, $company, $invoice, $agreement];
    }
}
