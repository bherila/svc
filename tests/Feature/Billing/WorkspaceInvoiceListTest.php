<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The workspace-wide invoice list.
 *
 * It sits above any one client, so it renders outside the client chrome - a
 * switcher naming a client would be lying about where the reader is. What it
 * must not do is widen what a scoped member can see: it reads the same
 * reachability rule the directory does, since otherwise the one screen that
 * lists every invoice would be the way around #157.
 *
 * Fixtures are synthetic throughout.
 */
class WorkspaceInvoiceListTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_a_manager_sees_every_invoice_in_the_workspace(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic List', 'synthetic-list', $manager);
        $first = $this->company($workspace, 'Aa Synthetic List Client', 'aa-list-client');
        $second = $this->company($workspace, 'Bb Synthetic List Client', 'bb-list-client');

        $this->invoice($workspace, $first, 'SYN-LIST-1');
        $this->invoice($workspace, $second, 'SYN-LIST-2');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/invoices")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('invoices/index')
                ->has('invoices', 2)
                // No client in context, so no switcher naming one.
                ->where('clientContext', null));
    }

    /**
     * The list that shows everything must not show more than the directory.
     *
     * Without this it would be the single screen that undoes the scoping -
     * every client's name and every invoice number, to a member who reaches
     * one project.
     */
    public function test_a_scoped_member_sees_only_their_clients_invoices(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Narrow', 'synthetic-narrow', $manager);
        $mine = $this->company($workspace, 'Reachable List Client', 'reachable-list-client');
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $mine->id,
            'name' => 'Reachable List Project',
            'status' => 'active',
        ]);
        $theirs = $this->company($workspace, 'Unreachable List Client', 'unreachable-list-client');

        $this->invoice($workspace, $mine, 'MINE-LIST-1');
        $this->invoice($workspace, $theirs, 'THEIRS-LIST-9999');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        $response = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/invoices")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
        $this->assertInertiaPayloadOmits($response, [
            'THEIRS-LIST-9999',
            'Unreachable List Client',
        ], 'MINE-LIST-1');
    }

    /**
     * A legacy invoice whose client belongs to another workspace.
     *
     * `client_company_id` is unconstrained lineage, so a row migrated from
     * before #113's composite keys can name a company in another tenant. The
     * eager load is constrained to this workspace, so the row still appears -
     * a list claiming completeness must not drop it - while the foreign
     * client's name and id do not.
     */
    public function test_a_foreign_clients_name_never_reaches_the_payload(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Lineage', 'synthetic-lineage', $manager);
        $mine = $this->company($workspace, 'Local Lineage Client', 'local-lineage-client');
        $this->invoice($workspace, $mine, 'LOCAL-LINEAGE-1');

        $foreign = Workspace::query()->create(['name' => 'Foreign Lineage Tenant', 'slug' => 'foreign-lineage']);
        $foreignCompany = $this->company($foreign, 'Foreign Lineage Client Name', 'foreign-lineage-client');

        // Refused by the composite keys since #113, so seeded with enforcement
        // suspended: the subject is what the payload does with a row that a
        // migrated database can still hold.
        $stray = $this->writingLegacyCrossTenantRows(
            fn () => $this->invoice($workspace, $foreignCompany, 'STRAY-LINEAGE-9999'),
        );

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/invoices")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('invoices', 2));
        $this->assertInertiaPayloadOmits(
            $response,
            ['Foreign Lineage Client Name', 'Foreign Lineage Tenant'],
            'LOCAL-LINEAGE-1',
        );

        // And it links nowhere, rather than to a client screen that would
        // refuse the reader anyway.
        $response->assertInertia(fn (Assert $page) => $page
            ->where('invoices.0.href', null)
            ->where('invoices.0.invoice_number', $stray->invoice_number));
    }

    /**
     * A portal viewer's rows lead somewhere they are allowed to go.
     *
     * They reach this list through the non-member branch, and the client-scoped
     * invoice screen authorizes on workspace membership - so linking there
     * would hand every row a 403.
     */
    public function test_a_portal_viewers_rows_link_to_a_route_that_admits_them(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Portal', 'synthetic-portal', $manager);
        $company = $this->company($workspace, 'Synthetic Portal Client', 'synthetic-portal-client');
        $invoice = $this->invoice($workspace, $company, 'PORTAL-LIST-1');
        $invoice->forceFill(['is_visible_to_client' => true])->save();

        $client = User::factory()->create();
        $client->clientCompanies()->attach($company->id, [
            'workspace_id' => $workspace->id,
            'public_id' => (string) Str::uuid(),
            'role' => 'client',
        ]);

        $this->actingAs($client)
            ->get("/workspaces/{$workspace->public_id}/invoices")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoices', 1)
                ->where('invoices.0.href', "/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}"));
    }

    /**
     * Narrowing the list is not narrowing the invoice.
     *
     * Review finding, and the sharper half of #157: the list was scoped and
     * the direct routes were not, so a scoped member read any client's invoice
     * - and its PDF, which is the same disclosure with a filename - by pasting
     * an id. Membership admits them to the workspace, not to every client in
     * it.
     */
    public function test_a_scoped_member_cannot_open_an_unreachable_clients_invoice(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Direct', 'synthetic-direct-invoice', $manager);

        $mine = $this->company($workspace, 'Reachable Direct Client', 'reachable-direct-client');
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $mine->id,
            'name' => 'Reachable Direct Project',
            'status' => 'active',
        ]);
        $ours = $this->invoice($workspace, $mine, 'MINE-DIRECT-1');

        $theirs = $this->company($workspace, 'Unreachable Direct Client', 'unreachable-direct-client');
        $hidden = $this->invoice($workspace, $theirs, 'THEIRS-DIRECT-9999');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        // Their own client's invoice is fine.
        $this->actingAs($member)
            ->getJson("/workspaces/{$workspace->public_id}/invoices/{$ours->public_id}")
            ->assertOk();

        // The other client's is not - by id, and by PDF, which shares the check.
        $this->actingAs($member)
            ->getJson("/workspaces/{$workspace->public_id}/invoices/{$hidden->public_id}")
            ->assertNotFound();

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/invoices/{$hidden->public_id}/pdf")
            ->assertNotFound();
    }

    /** A manager still reaches every invoice, including on a projectless client. */
    public function test_a_manager_still_opens_any_invoice_directly(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Direct Admin', 'synthetic-direct-admin', $manager);
        $company = $this->company($workspace, 'Projectless Direct Client', 'projectless-direct-client');
        $invoice = $this->invoice($workspace, $company, 'ADMIN-DIRECT-1');

        $this->actingAs($manager)
            ->getJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}")
            ->assertOk();
    }

    private function workspace(string $name, string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'admin']);

        return $workspace;
    }

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'balance_amount' => 10000,
        ]);
    }
}
