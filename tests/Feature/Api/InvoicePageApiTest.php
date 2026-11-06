<?php

namespace Tests\Feature\Api;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InvoicePageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_draft_creation_uses_operation_and_time_page_capabilities_and_replays(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false]);
        [$owner, $workspace, $company] = $this->fixture();
        [, $otherWorkspace, $otherCompany] = $this->fixture();
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic draft project', 'slug' => 'synthetic-draft-project']);
        $this->signedIn($owner);
        $create = route('agent-api.v1.invoices.store', $workspace, absolute: false);
        $this->get(route('workspaces.operations', $workspace))->assertOk()->assertInertia(fn (Assert $view) => $view->where('workspace.clients.0.invoice_create_href', $create));
        $this->get(route('clients.time', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $view) => $view->where('invoice_draft.url', $create)->where('invoice_draft.company_id', $company->public_id));
        $body = ['company_id' => $company->public_id, 'invoice_number' => 'INV-SYNTHETIC-API-CREATE', 'currency' => 'USD', 'is_visible_to_client' => false, 'manual_lines' => [['type' => 'service', 'description' => 'Synthetic draft service', 'quantity' => '1', 'unit_amount' => 12345, 'tax_amount' => 0]]];
        $created = $this->postJson($create, $body, ['Idempotency-Key' => 'synthetic-create-manual'])->assertCreated()->json('data.id');
        $this->postJson($create, $body, ['Idempotency-Key' => 'synthetic-create-manual'])->assertCreated()->assertJsonPath('data.id', $created);
        $draft = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $created)->sole();
        $this->assertSame(['draft', 12345, false], [$draft->status, $draft->total_amount, $draft->is_visible_to_client]);
        $this->postJson($create, ['company_id' => $otherCompany->public_id, 'invoice_number' => 'INV-SYNTHETIC-FOREIGN-CREATE'] + $body, ['Idempotency-Key' => 'synthetic-create-foreign-company'])->assertNotFound();
        $this->postJson(route('agent-api.v1.invoices.store', $otherWorkspace, absolute: false), $body, ['Idempotency-Key' => 'synthetic-create-foreign-workspace'])->assertNotFound();

        $entry = ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id, 'worked_on' => now()->toDateString(), 'minutes' => 60, 'description' => 'Synthetic approved work', 'status' => 'approved', 'is_billable' => true, 'billing_rate_amount' => 12000, 'currency' => 'USD']);
        $timeBody = ['company_id' => $company->public_id, 'invoice_number' => 'INV-SYNTHETIC-API-TIME', 'currency' => 'USD', 'is_visible_to_client' => false, 'time_entry_ids' => [$entry->public_id]];
        $timeDraft = $this->postJson($create, $timeBody, ['Idempotency-Key' => 'synthetic-create-time'])->assertCreated()->json('data.id');
        $this->postJson($create, $timeBody, ['Idempotency-Key' => 'synthetic-create-time'])->assertCreated()->assertJsonPath('data.id', $timeDraft);
        $this->assertSame(1, $entry->invoiceLines()->where('client_invoice_lines.workspace_id', $workspace->id)->count());
        $this->assertSame(12000, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $timeDraft)->sole()->total_amount);
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->signedIn($member);
        $this->get(route('workspaces.operations', $workspace))->assertOk()->assertInertia(fn (Assert $view) => $view->where('workspace.clients.0.invoice_create_href', null));
        $this->postJson($create, $body, ['Idempotency-Key' => 'synthetic-create-member'])->assertForbidden();
        $this->assertSame(3, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_invoice_page_capabilities_drive_session_bookkeeping_with_versions_and_replays(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false, 'agent_api.payment_writes_enabled' => false]);
        [$owner, $workspace, $company, $invoice] = $this->fixture();
        $page = route('clients.invoice', [$workspace, $company, $invoice]);
        $issue = route('agent-api.v1.invoices.issue', [$workspace, $invoice->public_id], absolute: false);
        $this->actingAs($owner)->get($page)->assertOk()->assertInertia(fn (Assert $view) => $view
            ->where('actions.issue', $issue)->where('version', AgentApiVersion::for($invoice->fresh())));
        $this->get(route('workspaces.operations', $workspace))->assertOk()->assertInertia(fn (Assert $view) => $view
            ->where('workspace.clients.0.invoices.0.issue_href', $issue)
            ->where('workspace.clients.0.invoices.0.payment_href', null)
            ->where('workspace.clients.0.invoices.0.version', AgentApiVersion::for($invoice->fresh())));
        $this->signedIn($owner);
        $body = ['expected_version' => AgentApiVersion::for($invoice->fresh()), 'confirm' => true];
        $this->postJson($issue, $body)->assertUnprocessable();
        $this->postJson($issue, $body, ['Idempotency-Key' => 'synthetic-page-issue'])->assertOk()->assertJsonPath('data.status', 'issued');
        $this->postJson($issue, $body, ['Idempotency-Key' => 'synthetic-page-issue'])->assertOk()->assertJsonPath('data.status', 'issued');
        $this->assertSame('issued', $invoice->fresh()->status);

        $record = route('agent-api.v1.payments.store', $workspace, absolute: false);
        $this->actingAs($owner, 'web')->get($page)->assertOk()->assertInertia(fn (Assert $view) => $view->where('actions.payment', $record));
        $paymentBody = ['invoice_id' => $invoice->public_id, 'expected_version' => AgentApiVersion::for($invoice->fresh()), 'confirm' => true, 'amount' => 300, 'currency' => 'USD', 'method' => 'wire'];
        $this->postJson($record, ['expected_version' => str_repeat('0', 64)] + $paymentBody, ['Idempotency-Key' => 'synthetic-page-stale-payment'])->assertConflict();
        $this->postJson($record, $paymentBody, ['Idempotency-Key' => 'synthetic-page-payment'])->assertCreated();
        $this->postJson($record, $paymentBody, ['Idempotency-Key' => 'synthetic-page-payment'])->assertCreated();
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame([300, 700], [$invoice->fresh()->paid_amount, $invoice->fresh()->balance_amount]);
        $correct = route('agent-api.v1.payments.update', [$workspace, $payment->public_id], absolute: false);
        $this->actingAs($owner, 'web')->get($page)->assertOk()->assertInertia(fn (Assert $view) => $view
            ->where('payments.0.correct_date_href', $correct)->where('payments.0.version', AgentApiVersion::for($payment)));
        $dateBody = ['expected_version' => AgentApiVersion::for($payment), 'confirm' => true, 'reason' => 'Synthetic bank statement', 'received_on' => now()->subDay()->toDateString()];
        $this->patchJson($correct, $dateBody, ['Idempotency-Key' => 'synthetic-page-date'])->assertOk()->assertJsonPath('data.received_on', $dateBody['received_on']);
        $this->patchJson($correct, $dateBody, ['Idempotency-Key' => 'synthetic-page-date'])->assertOk();
        $this->assertSame([300, 700], [$invoice->fresh()->paid_amount, $invoice->fresh()->balance_amount]);
    }

    public function test_page_capabilities_and_api_writes_preserve_tenant_and_manager_isolation(): void
    {
        [$owner, $workspace, $company, $invoice] = $this->fixture();
        [, $foreignWorkspace, , $foreignInvoice] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->signedIn($member);
        $this->get(route('workspaces.operations', $workspace))->assertOk()->assertInertia(fn (Assert $view) => $view
            ->where('workspace.clients.0.invoices.0.issue_href', null)->where('workspace.clients.0.invoices.0.payment_href', null));
        $this->postJson(route('agent-api.v1.invoices.issue', [$workspace, $invoice->public_id], absolute: false), [
            'expected_version' => AgentApiVersion::for($invoice->fresh()), 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-member-issue'])->assertForbidden();
        $this->signedIn($owner);
        $this->postJson(route('agent-api.v1.invoices.issue', [$workspace, $foreignInvoice->public_id], absolute: false), [
            'expected_version' => AgentApiVersion::for($foreignInvoice->fresh()), 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-foreign-invoice'])->assertNotFound();
        $this->postJson(route('agent-api.v1.invoices.issue', [$foreignWorkspace, $foreignInvoice->public_id], absolute: false), [
            'expected_version' => AgentApiVersion::for($foreignInvoice->fresh()), 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-foreign-workspace'])->assertNotFound();
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('draft', $foreignInvoice->fresh()->status);
    }

    private function signedIn(User $user): static
    {
        return $this->actingAs($user, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str()->random(40));
    }

    /** @return array{User, Workspace, ClientCompany, ClientInvoice} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic invoice API workspace', 'slug' => 'synthetic-invoice-api-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic invoice API client', 'slug' => 'synthetic-invoice-api-client-'.str()->random(8)]);
        $invoice = app(InvoiceLifecycleService::class)->createDraft($workspace, $company, ['invoice_number' => 'SYNTHETIC-'.str()->uuid(), 'currency' => 'USD'], [
            ['type' => 'manual', 'description' => 'Synthetic invoice API service', 'quantity' => 1, 'unit_amount' => 1000],
        ]);

        return [$owner, $workspace, $company, $invoice];
    }
}
