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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
