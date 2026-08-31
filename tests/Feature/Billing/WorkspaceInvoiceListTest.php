<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
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
