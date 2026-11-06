<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceDeferredWorkStatements;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceEmailService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Immutable project lineage for deferred snapshots (#610).
 *
 * A project-scoped portal member reads an invoice only when every project it
 * discloses is granted: the agreement's, the lines', and the frozen projects of
 * the deferred work its document lists. A legacy snapshot that lists work
 * without recorded projects counts as ungranted for them; company-wide members
 * keep access. The one rule decides the portal list and screen, the PDF, REST,
 * MCP and automatic recipients, each refusing with not-found.
 */
final class DeferredSnapshotLineageVisibilityTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const string PROJECT_B_TEXT = 'Synthetic project B deferred work';

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientAgreement $agreement;

    private ClientProject $projectA;

    private ClientProject $projectB;

    private User $owner;

    private User $scoped;

    private User $wholeCompany;

    private ClientCompanyMembership $scopedMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-15 12:00:00'));
        $this->owner = User::factory()->create(['email' => 'lineage-owner@example.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic lineage workspace', 'slug' => 'synthetic-lineage']);
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic lineage client', 'slug' => 'synthetic-lineage-client']);
        $this->company->forceFill(['billing_email' => null, 'automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 0])->save();
        $this->projectA = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic project A', 'is_visible_to_client' => true]);
        $this->projectB = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic project B', 'is_visible_to_client' => true]);
        // Company-wide: the agreement names no project.
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic lineage retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-04-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
            // Opted in, so these visibility tests see the list wherever lineage allows it.
            'show_deferred_backlog_to_client' => true,
        ]);
        $this->entry($this->projectA, '2026-05-10', 600, deferred: false);

        $this->scoped = User::factory()->create(['email' => 'lineage-scoped@example.test']);
        $this->wholeCompany = User::factory()->create(['email' => 'lineage-company@example.test']);
        $this->scopedMembership = ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'user_id' => $this->scoped->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'user_id' => $this->wholeCompany->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->grant($this->projectA);
    }

    /** The exact probe from #610, which returned the invoice with B's text before. */
    public function test_ungranted_deferred_work_in_the_snapshot_hides_an_otherwise_granted_invoice(): void
    {
        $issued = $this->issueWithDeferred($this->projectB, self::PROJECT_B_TEXT);

        $this->assertSame([$this->projectB->id], $issued->deferredWorkSnapshot()?->projectIds());
        $this->assertSame(self::PROJECT_B_TEXT, $issued->deferredWorkSnapshot()?->entries[0]['description']);
        $this->assertVisibility($this->scoped, $issued, false);
        $this->assertVisibility($this->wholeCompany, $issued, true);
        $this->assertRecipients($issued, [$this->wholeCompany->email]);

        // The rule reads grants, so granting B is what restores the member.
        $this->grant($this->projectB);
        $this->assertVisibility($this->scoped, $issued, true);
        $this->assertRecipients($issued, [$this->scoped->email, $this->wholeCompany->email]);
    }

    public function test_lineage_is_frozen_at_capture_and_never_read_from_the_live_entry(): void
    {
        $issued = $this->issueWithDeferred($this->projectB, self::PROJECT_B_TEXT);
        // Still unallocated, so still editable: moving it to A must not
        // change who may read the document that already disclosed it.
        ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)
            ->where('client_visible_description', self::PROJECT_B_TEXT)->sole()
            ->update(['client_project_id' => $this->projectA->id]);

        $this->assertVisibility($this->scoped, $issued->fresh() ?? $issued, false);

        // And the other way round: work captured on A stays A's.
        $granted = $this->issueWithDeferred($this->projectA, 'Synthetic project A deferred work', period: '2026-06');
        ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)
            ->where('client_visible_description', 'Synthetic project A deferred work')->sole()
            ->update(['client_project_id' => $this->projectB->id]);
        $this->assertVisibility($this->scoped, $granted->fresh() ?? $granted, true);
    }

    public function test_a_modern_snapshot_of_granted_work_keeps_the_scoped_member(): void
    {
        $issued = $this->issueWithDeferred($this->projectA, 'Synthetic granted deferred work');

        $this->assertFalse($issued->deferredWorkSnapshot()?->hasUnverifiableLineage());
        $this->assertVisibility($this->scoped, $issued, true);
        $this->actingAs($this->scoped)
            ->get("/portal/{$this->company->public_id}/invoices/{$issued->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('invoice.deferred_work.entries.0.description', 'Synthetic granted deferred work'));
        $this->assertRecipients($issued, [$this->scoped->email, $this->wholeCompany->email]);
    }

    public function test_a_legacy_snapshot_listing_work_is_refused_to_scoped_members_and_still_shown_company_wide(): void
    {
        $issued = $this->issueWithDeferred($this->projectA, 'Synthetic legacy deferred work');
        $this->makeLegacy($issued);
        $issued = $issued->fresh() ?? $issued;

        $snapshot = $issued->deferredWorkSnapshot();
        $this->assertNotNull($snapshot, 'Version 1 still reads.');
        $this->assertTrue($snapshot->hasUnverifiableLineage());
        $this->assertVisibility($this->scoped, $issued, false);
        $this->assertVisibility($this->wholeCompany, $issued, true);
        $this->assertRecipients($issued, [$this->wholeCompany->email]);

        // Granting every project cannot verify what was never recorded.
        $this->grant($this->projectB);
        $this->assertVisibility($this->scoped, $issued, false);

        // The company-wide reader still gets the whole "Still deferred" section.
        $this->actingAs($this->wholeCompany)
            ->get("/portal/{$this->company->public_id}/invoices/{$issued->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.deferred_work.entry_count', 1)
                ->where('invoice.deferred_work.total_hours', 1)
                ->where('invoice.deferred_work.entries.0.description', 'Synthetic legacy deferred work'));
        $html = app(InvoiceDocumentService::class)->html($issued)->render();
        $this->assertStringContainsString('Still deferred', $html);
        $this->assertStringContainsString('Synthetic legacy deferred work', $html);
    }

    public function test_a_legacy_snapshot_with_no_entries_discloses_nothing_and_hides_nothing(): void
    {
        $issued = $this->issueWithDeferred($this->projectA, 'Synthetic emptied work');
        $issued->forceFill(['deferred_work_statement' => ['version' => 1, 'entries' => []]])->save();
        DB::table(InvoiceDeferredWorkStatements::PROJECTS_TABLE)->where('workspace_id', $this->workspace->id)->delete();

        $this->assertNull($issued->fresh()?->deferredWorkSnapshot());
        $this->assertVisibility($this->scoped, $issued, true);
    }

    public function test_a_legacy_snapshot_the_document_cannot_render_is_still_unverifiable(): void
    {
        $issued = $this->issueWithDeferred($this->projectA, 'Synthetic malformed work');
        $this->makeLegacy($issued);
        $stored = $issued->fresh()?->deferred_work_statement ?? [];
        $stored['entries'][0]['minutes'] = 'malformed';
        $issued->forceFill(['deferred_work_statement' => $stored])->save();

        // The section is gone; that must not be what admits the member.
        $this->assertNull($issued->fresh()?->deferredWorkSnapshot());
        $this->assertVisibility($this->scoped, $issued, false);
        foreach ([['version' => 7, 'entries' => [['id' => 1]]], ['entries' => [['id' => 1]]]] as $unknown) {
            $issued->forceFill(['deferred_work_statement' => $unknown])->save();
            $this->assertVisibility($this->scoped, $issued, false);
        }
    }

    public function test_mcp_invoice_reads_apply_the_same_lineage_rule(): void
    {
        $modern = $this->issueWithDeferred($this->projectB, self::PROJECT_B_TEXT);
        $legacy = $this->issueWithDeferred($this->projectA, 'Synthetic legacy MCP work', period: '2026-06');
        $this->makeLegacy($legacy);

        foreach ([[$this->scoped, false], [$this->wholeCompany, true]] as [$viewer, $visible]) {
            $this->actingAsMcp($viewer, ['mcp:use', 'billing:read', 'identity:read']);
            $session = $this->initialize();
            $listed = array_column($this->callTool($session, 'invoices.list', ['workspace_id' => $this->workspace->public_id])['result']['structuredContent']['data'] ?? [], 'id');
            foreach ([$modern, $legacy] as $invoice) {
                $this->assertSame($visible, in_array($invoice->public_id, $listed, true));
                $read = $this->callTool($session, 'invoices.get', ['workspace_id' => $this->workspace->public_id, 'invoice_id' => $invoice->public_id]);
                if ($visible) {
                    $this->assertSame($invoice->public_id, $read['result']['structuredContent']['data']['id'] ?? null);
                } else {
                    $this->assertNull($read['result']['structuredContent'] ?? null);
                    $this->assertStringNotContainsString(self::PROJECT_B_TEXT, (string) json_encode($read));
                    $this->assertStringNotContainsString('Synthetic legacy MCP work', (string) json_encode($read));
                }
            }
        }
    }

    public function test_the_lineage_index_follows_every_snapshot_write_inside_the_workspace(): void
    {
        $issued = $this->issueWithDeferred($this->projectB, self::PROJECT_B_TEXT);
        $other = Workspace::query()->create(['name' => 'Synthetic other lineage workspace', 'slug' => 'synthetic-other-lineage']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Synthetic other lineage client', 'slug' => 'synthetic-other-lineage-client']);
        $otherInvoice = ClientInvoice::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'invoice_number' => 'SYN-LINEAGE-OTHER', 'status' => 'draft', 'currency' => 'USD', 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'balance_amount' => 0]);
        $otherProject = ClientProject::query()->create(['workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other lineage project']);
        $otherEntry = new ClientTimeEntry;
        $otherEntry->forceFill(['id' => 1, 'worked_on' => '2026-04-01', 'minutes' => 60, 'client_project_id' => $otherProject->id, 'is_visible_to_client' => false]);
        app(InvoiceDeferredWorkStatements::class)->write($otherInvoice, InvoiceDeferredWorkSnapshot::fromEntries([$otherEntry]));

        $this->assertSame([$this->projectB->id], $this->indexed($issued));
        $this->assertSame([$otherProject->id], $this->indexed($otherInvoice));

        $draft = ClientInvoice::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'invoice_number' => 'SYN-LINEAGE-ADHOC', 'status' => 'draft', 'currency' => 'USD', 'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'balance_amount' => 0]);
        app(InvoiceDeferredWorkStatements::class)->write($draft, $issued->deferredWorkSnapshot());
        $this->assertSame([$this->projectB->id], $this->indexed($draft));
        // Replacing a draft's lines by hand withdraws its snapshot and index.
        app(InvoiceLifecycleService::class)->updateDraft($draft, $this->workspace, [], []);
        $this->assertNull($draft->fresh()?->deferred_work_statement);
        $this->assertSame([], $this->indexed($draft));
        $this->assertSame([$otherProject->id], $this->indexed($otherInvoice), 'Another workspace is untouched.');
    }

    private function assertVisibility(User $viewer, ClientInvoice $invoice, bool $visible): void
    {
        $label = $viewer->email.' / '.$invoice->invoice_number;
        $policy = app(PortalInvoiceQuery::class);
        $this->assertSame($visible, $policy->visibleTo($this->company, $viewer)->whereKey($invoice->id)->exists(), $label);
        $this->assertSame($visible, $policy->strictlyVisibleInWorkspace($this->workspace, $viewer)->whereKey($invoice->id)->exists(), $label);
        $this->assertSame($visible, app(AgentAccess::class)->canViewInvoice($viewer, $invoice), $label);

        $portal = $this->actingAs($viewer)->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}");
        $visible ? $portal->assertOk() : $portal->assertNotFound();
        $list = (string) $this->actingAs($viewer)->get("/portal/{$this->company->public_id}/invoices")->assertOk()->getContent();
        $this->assertSame($visible, str_contains($list, (string) $invoice->public_id), $label);
        $pdf = $this->actingAs($viewer)->get("/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/pdf");
        $visible ? $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf') : $pdf->assertNotFound();

        $this->actingAsMcp($viewer, ['billing:read', 'identity:read']);
        $base = '/api/v1/workspaces/'.$this->workspace->public_id.'/invoices';
        $rest = $this->getJson($base.'/'.$invoice->public_id);
        $visible ? $rest->assertOk() : $rest->assertNotFound();
        if (! $visible) {
            $this->assertStringNotContainsString(self::PROJECT_B_TEXT, (string) $rest->getContent());
        }
        $this->assertSame($visible, in_array($invoice->public_id, array_column($this->getJson($base)->assertOk()->json('data'), 'id'), true), $label);
    }

    /** @param list<string> $expected */
    private function assertRecipients(ClientInvoice $invoice, array $expected): void
    {
        $suggested = app(InvoiceEmailService::class)->suggestedRecipients($invoice->fresh(['clientCompany']) ?? $invoice);
        $this->assertEqualsCanonicalizing($expected, array_column($suggested, 'email'));
    }

    private function issueWithDeferred(ClientProject $project, string $description, string $period = '2026-05'): ClientInvoice
    {
        $start = Carbon::parse($period.'-01');
        $draft = app(ClientInvoicingService::class)->generateInvoice($this->company, $start, $start->copy()->endOfMonth(), $this->agreement);
        // A synthetic zero-price line attributed to granted project A gives
        // the company-wide invoice its only project attribution.
        ClientInvoiceLine::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $draft->id, 'client_project_id' => $this->projectA->id,
            'type' => 'adjustment', 'description' => 'Synthetic project A service', 'quantity' => '1', 'unit_amount' => 0, 'total_amount' => 0, 'tax_amount' => 0, 'sort_order' => 99]);
        $this->entry($project, '2026-04-01', 60, description: $description);

        $issued = app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);
        $issued->forceFill(['is_visible_to_client' => true])->save();

        return $issued;
    }

    /** Model an invoice issued before lineage was captured: version 1, no index rows. */
    private function makeLegacy(ClientInvoice $invoice): void
    {
        $stored = $invoice->fresh()?->deferred_work_statement;
        $this->assertIsArray($stored);
        $this->assertIsArray($stored['entries']);
        $invoice->forceFill(['deferred_work_statement' => ['version' => 1, 'entries' => array_map(
            static fn (array $entry): array => array_diff_key($entry, ['projectId' => true]),
            $stored['entries'],
        )]])->save();
        DB::table(InvoiceDeferredWorkStatements::PROJECTS_TABLE)->where('workspace_id', $invoice->workspace_id)->where('client_invoice_id', $invoice->id)->delete();
    }

    /** @return list<int> */
    private function indexed(ClientInvoice $invoice): array
    {
        return DB::table(InvoiceDeferredWorkStatements::PROJECTS_TABLE)->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)->orderBy('client_project_id')->pluck('client_project_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private function grant(ClientProject $project): void
    {
        DB::table('client_portal_project_access')->insert(['workspace_id' => $this->workspace->id, 'client_company_membership_id' => $this->scopedMembership->id,
            'client_project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(ClientProject $project, string $date, int $minutes, bool $deferred = true, ?string $description = null): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $project->id, 'user_id' => $this->owner->id,
            'worked_on' => $date, 'minutes' => $minutes, 'description' => 'Synthetic private internal work',
            'is_billable' => true, 'is_deferred' => $deferred, 'status' => 'approved',
            'billing_rate_amount' => 10000, 'currency' => 'USD',
            'is_visible_to_client' => $description !== null, 'client_visible_description' => $description,
        ]);
    }
}
