<?php

namespace Tests\Feature\Portal;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * One invoice, as the client it was sent to.
 *
 * A client-reachable route, so the refusals are the feature rather than the
 * edge cases. The portal's list already decides what a client may see - this
 * screen resolves through the same query rather than restating it, because a
 * detail that admitted one invoice the list would not is exactly the bug: the
 * client never sees the row and reaches it by id.
 *
 * Fixtures are synthetic throughout.
 */
final class PortalInvoiceDetailTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic Portal WS',
            'slug' => 'synthetic-portal-ws',
        ]);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Synthetic Portal Client',
            'slug' => 'synthetic-portal-client',
        ]);
    }

    public function test_a_client_sees_the_lines_of_an_invoice_sent_to_them(): void
    {
        $invoice = $this->invoice('SENT-1', ['is_visible_to_client' => true, 'status' => 'issued']);
        $this->line($invoice, 'Synthetic consulting work', '2.5000');

        $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/invoice')
                ->where('invoice.invoice_number', 'SENT-1')
                ->has('lines', 1)
                ->where('lines.0.description', 'Synthetic consulting work'));
    }

    /**
     * A draft is working arithmetic nobody has committed to.
     *
     * The list excludes it, so the detail must too - otherwise the client
     * never sees the row and reaches it by id, and argues about a number that
     * was never sent.
     */
    public function test_a_draft_invoice_is_not_reachable_by_id(): void
    {
        $draft = $this->invoice('DRAFT-1', ['is_visible_to_client' => true, 'status' => 'draft']);
        $this->line($draft, 'Synthetic draft line', '9.0000');

        $response = $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$draft->public_id}");

        $response->assertNotFound();
        $this->assertStringNotContainsString('Synthetic draft line', (string) $response->getContent());
    }

    /**
     * An operator hiding an invoice is a disclosure decision, not a display one.
     */
    public function test_an_invoice_hidden_from_the_client_is_not_reachable_by_id(): void
    {
        $hidden = $this->invoice('HIDDEN-1', ['is_visible_to_client' => false, 'status' => 'issued']);

        $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$hidden->public_id}")
            ->assertNotFound();
    }

    /**
     * Another client's invoice, through a portal this user legitimately holds.
     *
     * Invoices bind by a public id unique across every workspace, so passing
     * the portal gate for your own company is not passing a check on the
     * invoice named in the URL.
     */
    public function test_another_companys_invoice_is_not_reachable(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Portal WS',
            'slug' => 'foreign-portal-ws',
        ]);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Portal Client Name',
            'slug' => 'foreign-portal-client',
        ]);

        $foreign = ClientInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'FOREIGN-PORTAL-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'balance_amount' => 5000,
        ]);
        $foreign->forceFill(['is_visible_to_client' => true])->save();

        $this->actingAs($this->portalUser())
            ->get("/portal/{$this->company->public_id}/invoices/{$foreign->public_id}")
            ->assertNotFound();
    }

    /** Someone with no portal membership at all reaches nothing. */
    public function test_a_stranger_cannot_open_the_invoice(): void
    {
        $invoice = $this->invoice('SENT-2', ['is_visible_to_client' => true, 'status' => 'issued']);

        $this->actingAs(User::factory()->create())
            ->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")
            ->assertForbidden();
    }

    /**
     * Nothing on a client-reachable route mutates.
     *
     * The screen offers no action, but "there is no button" is not the
     * property - the route refusing a write is.
     */
    public function test_the_invoice_route_refuses_a_write(): void
    {
        $invoice = $this->invoice('SENT-3', ['is_visible_to_client' => true, 'status' => 'issued']);
        $path = "/portal/{$this->company->public_id}/invoices/{$invoice->public_id}";

        foreach (['post', 'patch', 'put', 'delete'] as $verb) {
            $this->actingAs($this->portalUser())
                ->{$verb}($path)
                ->assertStatus(405);
        }
    }

    /**
     * A project-scoped client sees invoices for their projects and no others.
     *
     * The portal scoped projects, proposals and agreements and then listed
     * invoices company-wide, so a client admitted to one project of their own
     * company read every invoice it had - including work they were
     * deliberately not shown anywhere else on the same page.
     */
    public function test_a_project_scoped_client_sees_only_their_projects_invoices(): void
    {
        $mine = $this->project('Mine');
        $theirs = $this->project('Theirs');

        $ours = $this->invoice('SCOPED-MINE-1', ['is_visible_to_client' => true, 'status' => 'issued']);
        $this->line($ours, 'Synthetic mine line', $mine);

        $sibling = $this->invoice('SCOPED-THEIRS-9999', ['is_visible_to_client' => true, 'status' => 'issued']);
        $this->line($sibling, 'Synthetic theirs line', $theirs);

        // No lineage at all: not theirs to read either.
        $unscoped = $this->invoice('SCOPED-NONE-8888', ['is_visible_to_client' => true, 'status' => 'issued']);

        $client = $this->portalUser(ClientCompanyMembership::SCOPE_PROJECTS);
        $this->grant($client, $mine);

        $this->actingAs($client)
            ->get("/portal/{$this->company->public_id}/invoices/{$ours->public_id}")
            ->assertOk();

        foreach ([$sibling, $unscoped] as $refused) {
            $this->actingAs($client)
                ->get("/portal/{$this->company->public_id}/invoices/{$refused->public_id}")
                ->assertNotFound();
        }

        // And the list agrees with the detail.
        $response = $this->actingAs($client)->get("/portal/{$this->company->public_id}")->assertOk();
        $body = (string) $response->getContent();
        $this->assertStringContainsString('SCOPED-MINE-1', $body);
        $this->assertStringNotContainsString('SCOPED-THEIRS-9999', $body);
        $this->assertStringNotContainsString('SCOPED-NONE-8888', $body);
    }

    /**
     * An ordinary workspace member cannot preview a client's portal at all.
     *
     * The policy admitted any workspace membership, and `PortalAccess` then
     * returned unrestricted access for the same reason - so the portal was a
     * way around every project scope the operator screens apply. Owners and
     * admins keep the preview, since they already reach everything.
     */
    public function test_an_ordinary_workspace_member_cannot_preview_the_portal(): void
    {
        $invoice = $this->invoice('PREVIEW-1', ['is_visible_to_client' => true, 'status' => 'issued']);

        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($member)
            ->get("/portal/{$this->company->public_id}")
            ->assertForbidden();

        $this->actingAs($member)
            ->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")
            ->assertForbidden();

        $admin = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);

        $this->actingAs($admin)
            ->get("/portal/{$this->company->public_id}/invoices/{$invoice->public_id}")
            ->assertOk();
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => $name,
            'status' => 'active',
            'is_visible_to_client' => true,
        ]);
    }

    private function grant(User $user, ClientProject $project): void
    {
        $membership = ClientCompanyMembership::query()
            ->where('client_company_id', $this->company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        DB::table('client_portal_project_access')->insert([
            'workspace_id' => $this->workspace->id,
            'client_company_membership_id' => $membership->id,
            'client_project_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reading an invoice does not make you able to pay it.
     *
     * The payment-intent route was gated on the same check as opening an
     * invoice, so any workspace member could create a real Stripe intent - and
     * the recorded pending payment reserves the remaining balance, so an
     * abandoned or unauthorised one blocks a genuine payment.
     *
     * Nothing covered this route at all before, which is why the split broke
     * no test when it landed.
     */
    public function test_internal_staff_cannot_start_a_payment(): void
    {
        $invoice = $this->invoice('PAYABLE-1', ['is_visible_to_client' => true, 'status' => 'issued']);

        foreach (['member', 'admin', 'owner'] as $role) {
            $staff = User::factory()->create();
            $this->workspace->memberships()->create(['user_id' => $staff->id, 'role' => $role]);

            $this->actingAs($staff)
                ->postJson(
                    "/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/stripe-payment-intent",
                    ['idempotency_key' => 'synthetic-'.$role],
                )
                ->assertForbidden();
        }
    }

    /**
     * And neither does being a client of a different company.
     */
    public function test_a_client_of_another_company_cannot_start_a_payment(): void
    {
        $invoice = $this->invoice('PAYABLE-2', ['is_visible_to_client' => true, 'status' => 'issued']);

        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other Payable Client',
            'slug' => 'other-payable-client',
        ]);

        $outsider = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $otherCompany->id,
            'user_id' => $outsider->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        $this->actingAs($outsider)
            ->postJson(
                "/workspaces/{$this->workspace->public_id}/invoices/{$invoice->public_id}/stripe-payment-intent",
                ['idempotency_key' => 'synthetic-outsider'],
            )
            ->assertForbidden();
    }

    /**
     * A paid invoice cannot take another intent either.
     *
     * The status list here is narrower than the one for reading: an invoice
     * that is settled has nothing to pay, and reserving its balance again is
     * the same blocking failure by a different door.
     */
    public function test_a_settled_invoice_cannot_take_a_payment(): void
    {
        $paid = $this->invoice('PAYABLE-3', ['is_visible_to_client' => true, 'status' => 'paid']);

        $this->actingAs($this->portalUser())
            ->postJson(
                "/workspaces/{$this->workspace->public_id}/invoices/{$paid->public_id}/stripe-payment-intent",
                ['idempotency_key' => 'synthetic-paid'],
            )
            ->assertForbidden();
    }

    private function portalUser(?string $scope = null): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id,
            'user_id' => $user->id,
            'role' => 'client',
            'access_scope' => $scope ?? ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(string $number, array $overrides): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => $number,
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'balance_amount' => 10000,
        ]);

        $invoice->forceFill($overrides)->save();

        return $invoice;
    }

    private function line(ClientInvoice $invoice, string $description, ClientProject|string $quantity = '1.0000'): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'client_project_id' => $quantity instanceof ClientProject ? $quantity->id : null,
            'type' => 'time',
            'description' => $description,
            'quantity' => $quantity instanceof ClientProject ? '1.0000' : $quantity,
            'unit_amount' => 4000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'sort_order' => 0,
        ]);
    }
}
