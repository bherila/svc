<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Building an invoice from tracked time existed only on the agent API: the web
 * controller drafted straight from posted lines and never called the service
 * that owns allocation. These cover the browser path doing the same work.
 */
final class InvoiceFromTimeOverTheWebTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Web', 'slug' => 'web']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Web Client', 'slug' => 'web-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Web Project',
        ]);
        $this->owner = User::factory()->create();
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => 'owner',
        ]);
    }

    public function test_selected_time_becomes_invoice_lines_and_is_marked_allocated(): void
    {
        $first = $this->entry(90, 'Design review');
        $second = $this->entry(30, 'Follow-up');

        $response = $this->actingAs($this->owner)->postJson($this->url(), [
            'invoice_number' => 'SVC-00001',
            'currency' => 'USD',
            'time_entry_ids' => [$first->public_id, $second->public_id],
        ]);

        $response->assertCreated();

        $invoice = ClientInvoice::query()->firstOrFail();
        $this->assertCount(2, $invoice->lines);
        // 1.5h and 0.5h at 375.00/hour.
        $this->assertSame(75000, (int) $invoice->total_amount);
        $this->assertTrue($first->refresh()->invoiceLines()->exists());
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.generated')->sole();
        $this->assertSame($this->owner->id, $activity->actor_user_id);
        $this->assertSame($invoice->public_id, $activity->subject_public_id);
    }

    public function test_time_and_manual_lines_can_be_combined(): void
    {
        $entry = $this->entry(60, 'Consulting');

        $this->actingAs($this->owner)->postJson($this->url(), [
            'invoice_number' => 'SVC-00002',
            'currency' => 'USD',
            'time_entry_ids' => [$entry->public_id],
            'lines' => [[
                'type' => 'expense', 'description' => 'Travel',
                'quantity' => '1', 'unit_amount' => 5000, 'tax_amount' => 0, 'sort_order' => 0,
            ]],
        ])->assertCreated();

        $invoice = ClientInvoice::query()->firstOrFail();
        $this->assertCount(2, $invoice->lines);
        $this->assertSame(42500, (int) $invoice->total_amount);
    }

    public function test_the_same_time_cannot_be_billed_twice(): void
    {
        $entry = $this->entry(60, 'Consulting');

        $this->actingAs($this->owner)->postJson($this->url(), [
            'invoice_number' => 'SVC-00003', 'currency' => 'USD', 'time_entry_ids' => [$entry->public_id],
        ])->assertCreated();

        $this->actingAs($this->owner)->postJson($this->url(), [
            'invoice_number' => 'SVC-00004', 'currency' => 'USD', 'time_entry_ids' => [$entry->public_id],
        ])->assertStatus(422);

        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_an_invoice_still_needs_time_or_a_line(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), [
            'invoice_number' => 'SVC-00005', 'currency' => 'USD',
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_manual_only_invoices_still_work(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), [
            'invoice_number' => 'SVC-00006',
            'currency' => 'USD',
            'lines' => [[
                'type' => 'service', 'description' => 'Advisory',
                'quantity' => '1', 'unit_amount' => 25000, 'tax_amount' => 0, 'sort_order' => 0,
            ]],
        ])->assertCreated();

        $this->assertSame(25000, (int) ClientInvoice::query()->firstOrFail()->total_amount);
    }

    public function test_reapproved_time_can_return_to_the_same_draft_without_replacing_other_lines(): void
    {
        $time = app(TimeEntryMutationService::class);
        $fromTime = app(InvoiceFromTimeService::class);
        $entry = $this->entry(60, 'Synthetic correction');
        $entry->update(['billing_rate_source' => 'explicit']);
        $other = $this->entry(30, 'Synthetic other work');
        $invoice = $fromTime->create($this->workspace, $this->company, ['currency' => 'USD'], [$entry->public_id, $other->public_id], [
            ['type' => 'service', 'description' => 'Synthetic manual fee', 'quantity' => '1.25', 'unit_amount' => 1200, 'tax_amount' => 75],
        ]);
        $entry = $time->unapprove($this->workspace, $entry, $this->owner, AgentApiVersion::for($entry));
        $entry = $time->update($this->workspace, $entry, $this->owner, ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90]);
        $time->approve($this->workspace, $this->owner, [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]);
        $invoice->refresh();
        $preserved = $invoice->lines()->orderBy('id')->get()->map->getAttributes()->all();
        $version = AgentApiVersion::for($invoice);
        $this->actingAs($this->owner)->post($this->addUrl($invoice), ['expected_version' => $version, 'time_entry_ids' => [$entry->public_id]])
            ->assertRedirect(route('clients.invoice', [$this->workspace, $this->company, $invoice]));
        $invoice->refresh();
        $this->assertSame(76575, $invoice->total_amount);
        $this->assertSame(75, $invoice->tax_amount);
        foreach ($preserved as $attributes) {
            $this->assertSame($attributes, $invoice->lines()->whereKey($attributes['id'])->sole()->getAttributes());
        }
        $this->assertSame(1, $entry->invoiceLines()->count());
        $this->assertSame(1, $other->invoiceLines()->count());
        $this->assertFalse(AgentApiVersion::matches($invoice, $version));
        $this->assertSame(1, ClientCompanyActivity::where('action', 'invoice.updated')->count());
        $this->postJson($this->addUrl($invoice), ['expected_version' => $version, 'time_entry_ids' => [$this->entry(15, 'Synthetic stale attempt')->public_id]])->assertStatus(409);
        $this->postJson($this->addUrl($invoice), ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$entry->public_id]])->assertStatus(422);
        $this->assertSame(76575, $invoice->fresh()->total_amount);
    }

    public function test_addition_is_atomic_and_rejects_ineligible_foreign_or_missing_time(): void
    {
        $invoice = app(InvoiceFromTimeService::class)->create($this->workspace, $this->company, ['currency' => 'USD'], [$this->entry(30, 'Synthetic included')->public_id]);
        $valid = $this->entry(60, 'Synthetic valid');
        $invalid = $this->entry(60, 'Synthetic invalid');
        foreach ([['status' => 'draft'], ['is_deferred' => true], ['currency' => 'EUR'], ['is_billable' => false]] as $change) {
            $invalid->update(['status' => 'approved', 'is_deferred' => false, 'currency' => 'USD', 'is_billable' => true, ...$change]);
            $this->actingAs($this->owner)->postJson($this->addUrl($invoice), ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$valid->public_id, $invalid->public_id]])->assertStatus(422);
            $this->assertFalse($valid->invoiceLines()->exists());
            $this->assertSame(18750, $invoice->fresh()->total_amount);
        }
        $other = ClientCompany::create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other']);
        $invalid->update(['status' => 'approved', 'is_billable' => true, 'client_company_id' => $other->id]);
        $this->postJson($this->addUrl($invoice), ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$invalid->public_id]])->assertStatus(422);
        $foreign = Workspace::create(['name' => 'Synthetic foreign', 'slug' => 'synthetic-foreign']);
        $this->postJson("/workspaces/{$foreign->public_id}/invoices/{$invoice->public_id}/time", ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$valid->public_id]])->assertForbidden();
        $foreign->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->postJson("/workspaces/{$foreign->public_id}/invoices/{$invoice->public_id}/time", ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$valid->public_id]])->assertNotFound();
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->postJson($this->addUrl($invoice), ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$valid->public_id]])->assertForbidden();
    }

    public function test_only_ad_hoc_drafts_accept_time_and_zero_priced_additions_consume_the_version(): void
    {
        $invoice = app(InvoiceFromTimeService::class)->create($this->workspace, $this->company, ['currency' => 'USD'], [$this->entry(30, 'Synthetic included')->public_id]);
        $entry = $this->entry(60, 'Synthetic zero');
        $entry->update(['billing_rate_amount' => 0]);
        foreach ([['status' => 'issued'], ['status' => 'draft', 'invoice_kind' => 'cadence_period']] as $change) {
            $invoice->update($change);
            $this->actingAs($this->owner)->postJson($this->addUrl($invoice), ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => [$entry->public_id]])->assertStatus(422);
        }
        $invoice->update(['invoice_kind' => 'ad_hoc']);
        $version = AgentApiVersion::for($invoice);
        $this->post($this->addUrl($invoice), ['expected_version' => $version, 'time_entry_ids' => [$entry->public_id]])->assertRedirect();
        $this->assertSame(18750, $invoice->fresh()->total_amount);
        $this->assertFalse(AgentApiVersion::matches($invoice->fresh(), $version));
        $this->assertSame(1, $entry->invoiceLines()->count());
    }

    public function test_add_time_capability_and_target_are_scoped_to_managers_and_the_client(): void
    {
        $invoice = app(InvoiceFromTimeService::class)->create($this->workspace, $this->company, ['currency' => 'USD'], [$this->entry(30, 'Synthetic included')->public_id]);
        $target = route('clients.time', [$this->workspace, $this->company, 'draft_invoice' => $invoice->public_id], absolute: false);
        $this->actingAs($this->owner)->get(route('clients.invoice', [$this->workspace, $this->company, $invoice]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('actions.add_time', $target));
        $this->get($target)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice_draft.target.version', AgentApiVersion::for($invoice))
            ->where('invoice_draft.target.number', $invoice->invoice_number));
        $other = ClientCompany::create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other']);
        $this->get(route('clients.time', [$this->workspace, $other, 'draft_invoice' => $invoice->public_id]))->assertNotFound();
        $invoice->update(['status' => 'issued']);
        $this->get($target)->assertNotFound();
        $this->get(route('clients.invoice', [$this->workspace, $this->company, $invoice]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('actions.add_time', null));
    }

    public function test_a_full_selection_uses_one_allocation_read(): void
    {
        $service = app(InvoiceFromTimeService::class);
        $invoice = $service->create($this->workspace, $this->company, ['currency' => 'USD'], [$this->entry(30, 'Synthetic included')->public_id]);
        $ids = [];
        for ($index = 0; $index < 100; $index++) {
            $ids[] = $this->entry(1, 'Synthetic batch '.$index)->public_id;
        }
        $allocationReads = 0;
        DB::listen(function (QueryExecuted $query) use (&$allocationReads): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'client_invoice_line_time_entries')) {
                $allocationReads++;
            }
        });
        $this->actingAs($this->owner)->post($this->addUrl($invoice), ['expected_version' => AgentApiVersion::for($invoice), 'time_entry_ids' => $ids])->assertRedirect();
        $this->assertSame(1, $allocationReads, 'Allocation validation must not query once per selected entry.');
        $this->assertSame(81250, $invoice->fresh()->total_amount);
        $this->assertSame(101, $invoice->lines()->count());
    }

    private function addUrl(ClientInvoice $invoice): string
    {
        return "/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/time";
    }

    private function url(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/invoices";
    }

    private function entry(int $minutes, string $description): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'worked_on' => '2026-03-14',
            'minutes' => $minutes,
            'description' => $description,
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 37500,
            'currency' => 'USD',
        ]);
    }
}
