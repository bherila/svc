<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
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
                // Inside the workspace but not inside one client, so the
                // switcher has options and no selection.
                ->where('workspaceNavigation.current_client_id', null));
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

        // Lineage matters now: an invoice is visible when every project it
        // names is reachable, so the fixture has to name one.
        $this->line($this->invoice($workspace, $mine, 'MINE-LIST-1'), $project);
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
        $this->line($ours, $project);

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

    /**
     * The case the earlier tests missed: two projects, one company.
     *
     * Reaching a client is not reaching its money. A member granted one
     * project must not read an invoice for work on another - and proving that
     * with two *companies* proved something much weaker, which is how this
     * shipped.
     */
    public function test_a_project_scoped_member_sees_only_their_projects_invoices(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Sibling', 'synthetic-sibling', $manager);
        $company = $this->company($workspace, 'Shared Sibling Client', 'shared-sibling-client');

        $mine = $this->project($workspace, $company, 'Mine');
        $theirs = $this->project($workspace, $company, 'Theirs');

        $this->line($this->invoice($workspace, $company, 'SIB-MINE-1'), $mine);
        $this->line($this->invoice($workspace, $company, 'SIB-THEIRS-9999'), $theirs);

        // A mixed invoice is refused rather than partly rendered: its totals,
        // payments and PDF describe the whole document.
        $mixed = $this->invoice($workspace, $company, 'SIB-MIXED-8888');
        $this->line($mixed, $mine);
        $this->line($mixed, $theirs);

        // And one with no lineage at all is manager scope.
        $this->invoice($workspace, $company, 'SIB-UNSCOPED-7777');

        $member = $this->memberOf($workspace, $mine);

        $response = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/invoices")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
        $this->assertInertiaPayloadOmits($response, [
            'SIB-THEIRS-9999',
            'SIB-MIXED-8888',
            'SIB-UNSCOPED-7777',
        ], 'SIB-MINE-1');
    }

    /** And the same three refusals by id, not only in the list. */
    public function test_a_project_scoped_member_cannot_open_a_sibling_projects_invoice(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Sibling Id', 'synthetic-sibling-id', $manager);
        $company = $this->company($workspace, 'Shared Id Client', 'shared-id-client');
        $mine = $this->project($workspace, $company, 'Mine');
        $theirs = $this->project($workspace, $company, 'Theirs');

        $ours = $this->invoice($workspace, $company, 'ID-MINE-1');
        $this->line($ours, $mine);

        $sibling = $this->invoice($workspace, $company, 'ID-THEIRS-1');
        $this->line($sibling, $theirs);

        $unscoped = $this->invoice($workspace, $company, 'ID-UNSCOPED-1');

        $member = $this->memberOf($workspace, $mine);

        $this->actingAs($member)
            ->getJson("/workspaces/{$workspace->public_id}/invoices/{$ours->public_id}")
            ->assertOk();

        foreach ([$sibling, $unscoped] as $refused) {
            $this->actingAs($member)
                ->getJson("/workspaces/{$workspace->public_id}/invoices/{$refused->public_id}")
                ->assertNotFound();

            $this->actingAs($member)
                ->get("/workspaces/{$workspace->public_id}/invoices/{$refused->public_id}/pdf")
                ->assertNotFound();
        }
    }

    private function memberOf(Workspace $workspace, ClientProject $project): User
    {
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);

        return $member;
    }

    private function project(Workspace $workspace, ClientCompany $company, string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function line(ClientInvoice $invoice, ClientProject $project): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_project_id' => $project->id,
            'type' => 'time',
            'description' => 'Synthetic line',
            'quantity' => '1.0000',
            'unit_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'sort_order' => 0,
        ]);
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
