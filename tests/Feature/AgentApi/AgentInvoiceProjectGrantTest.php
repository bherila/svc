<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\AgentReadService;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class AgentInvoiceProjectGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_grants_constrain_rest_list_detail_summary_and_helper(): void
    {
        [$w, $c, $u, $m, $a, $invoices] = $this->fixtures();
        $this->assertSame([$invoices['granted']->id], app(PortalInvoiceQuery::class)->visibleTo($c, $u)->pluck('id')->all());
        Passport::actingAs(AgentPrincipal::query()->findOrFail($u->id), ['billing:read', 'identity:read']);
        $base = '/api/v1/workspaces/'.$w->public_id.'/invoices';
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoices['granted']->public_id);
        foreach ($invoices as $name => $i) {
            $allowed = $name === 'granted';
            $this->assertSame($allowed, app(AgentAccess::class)->canViewInvoice($u, $i));
            $this->getJson($base.'/'.$i->public_id)->assertStatus($allowed ? 200 : 404);
        }
        $this->getJson('/api/v1/workspaces/'.$w->public_id.'/summary')->assertOk()
            ->assertJsonPath('data.invoices.collectible_balances.0.amount', 10000);
    }

    public function test_mcp_list_detail_and_summary_apply_the_same_project_grants(): void
    {
        [$workspace, , $viewer, , , $invoices] = $this->fixtures();
        $this->actingAsMcp($viewer, ['mcp:use', 'billing:read', 'identity:read']);
        $initialized = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic grant audit', 'version' => '1']]])->assertOk();
        $headers = ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $initialized->headers->get('Mcp-Session-Id')];
        $call = fn (string $name, array $arguments) => $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $headers)->assertOk();
        $list = $call('invoices.list', ['workspace_id' => $workspace->public_id]);
        $this->assertSame([$invoices['granted']->public_id], array_column($list->json('result.structuredContent.data'), 'id'));
        foreach ($invoices as $name => $invoice) {
            $response = $call('invoices.get', ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id]);
            if ($name === 'granted') {
                $response->assertJsonPath('result.structuredContent.data.id', $invoice->public_id);
            } else {
                $this->assertNull($response->json('result.structuredContent'));
                $this->assertStringNotContainsString('Synthetic '.$name.' work', $response->getContent());
                $this->assertNotNull($response->json('error'));
            }
        }
        $summary = $call('operations.summary', ['workspace_id' => $workspace->public_id]);
        $summary->assertJsonPath('result.structuredContent.data.invoices.collectible_balances.0.amount', 10000);
    }

    public function test_revoking_the_only_grant_hides_every_invoice_immediately(): void
    {
        [$workspace, $company, $viewer, $membership, , $invoices] = $this->fixtures();
        Passport::actingAs(AgentPrincipal::query()->findOrFail($viewer->id), ['billing:read']);
        DB::table('client_portal_project_access')->where('workspace_id', $workspace->id)->where('client_company_membership_id', $membership->id)->delete();
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/invoices';
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        foreach ($invoices as $invoice) {
            $this->getJson($base.'/'.$invoice->public_id)->assertNotFound();
            $this->assertFalse(app(AgentAccess::class)->canViewInvoice($viewer, $invoice));
        }
        $this->assertSame([], app(PortalInvoiceQuery::class)->visibleTo($company, $viewer)->pluck('id')->all());
        $this->assertSame([], app(PortalInvoiceQuery::class)->visibleTo($company, null)->pluck('id')->all());
    }

    public function test_company_wide_portal_and_workspace_managers_keep_their_existing_visibility(): void
    {
        [$workspace, $company, $viewer, $membership, , $invoices] = $this->fixtures();
        $membership->forceFill(['access_scope' => 'company'])->save();
        $reads = app(AgentReadService::class);
        $this->assertCount(4, $reads->invoices($viewer, $workspace, null, 25, null)['data']);
        $hidden = $invoices['ungranted'];
        $hidden->forceFill(['is_visible_to_client' => false, 'status' => 'draft'])->save();
        $this->assertCount(3, $reads->invoices($viewer, $workspace, null, 25, null)['data']);
        $this->assertFalse(app(AgentAccess::class)->canViewInvoice($viewer, $hidden));
        foreach (['owner', 'admin'] as $role) {
            $manager = User::factory()->create();
            WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $manager->id, 'role' => $role]);
            $this->assertCount(4, $reads->invoices($manager, $workspace, null, 25, null)['data']);
            $this->assertTrue(app(AgentAccess::class)->canViewInvoice($manager, $hidden));
            // Portal visibility still excludes hidden drafts even for managers.
            $this->assertCount(3, app(PortalInvoiceQuery::class)->visibleTo($company, $manager)->get());
        }
    }

    public function test_workspace_wide_visibility_is_one_query_and_preserves_each_company_scope(): void
    {
        [$workspace, $company, $viewer, , , $invoices] = $this->fixtures();
        $otherWorkspace = Workspace::query()->create(['name' => 'Synthetic other workspace', 'slug' => 'synthetic-other']);
        foreach (range(1, 4) as $index) {
            $tenant = $index === 4 ? $otherWorkspace : $workspace;
            $client = ClientCompany::query()->create(['workspace_id' => $tenant->id, 'name' => 'Synthetic company '.$index, 'slug' => 'synthetic-company-'.$index]);
            if ($index !== 3) {
                ClientCompanyMembership::query()->create(['workspace_id' => $tenant->id, 'client_company_id' => $client->id, 'user_id' => $viewer->id, 'role' => 'client', 'access_scope' => 'company']);
            }
            ClientInvoice::query()->create(['workspace_id' => $tenant->id, 'client_company_id' => $client->id, 'invoice_number' => 'SYN-OTHER-'.$index, 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'balance_amount' => 100, 'is_visible_to_client' => true]);
        }
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $visible = app(PortalInvoiceQuery::class)->visibleInWorkspace($workspace, AgentPrincipal::query()->findOrFail($viewer->id));
        $queries = [];
        $numbers = $visible->orderBy('invoice_number')->pluck('invoice_number')->all();
        // Visibility is independent of the database's collation.
        sort($numbers, SORT_STRING);
        $this->assertSame(['SYN-OTHER-1', 'SYN-OTHER-2', 'SYN-granted'], $numbers);
        $this->assertCount(1, $queries);
        $queries = [];
        $this->assertCount(1, app(PortalInvoiceQuery::class)->visibleTo($company, $viewer)->get());
        $this->assertCount(1, $queries);
    }

    public function test_agreement_and_line_projects_both_determine_whole_invoice_visibility(): void
    {
        [$workspace, $company, $viewer, , $grantedProject, $fixtures] = $this->fixtures();
        $ungrantedProject = ClientProject::query()->where('workspace_id', $workspace->id)->whereKeyNot($grantedProject->id)->firstOrFail();
        $agreements = [];
        foreach (['granted' => $grantedProject, 'ungranted' => $ungrantedProject] as $name => $project) {
            $agreements[$name] = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'title' => 'Synthetic '.$name.' agreement', 'starts_on' => '2026-01-01', 'status' => 'active', 'currency' => 'USD']);
        }
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic wrong company', 'slug' => 'synthetic-wrong-company']);
        $agreements['wrong-company'] = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'title' => 'Synthetic malformed attribution', 'starts_on' => '2026-01-01', 'currency' => 'USD']);
        $cases = [
            'agreement-only' => ['granted', null, true],
            'both-granted' => ['granted', $grantedProject, true],
            'ungranted-agreement' => ['ungranted', $grantedProject, false],
            'ungranted-line' => ['granted', $ungrantedProject, false],
            'wrong-company-agreement' => ['wrong-company', $grantedProject, false],
        ];
        Passport::actingAs(AgentPrincipal::query()->findOrFail($viewer->id), ['billing:read']);
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/invoices';
        $expectedIds = [$fixtures['granted']->public_id];
        $created = [];
        foreach ($cases as $name => [$agreement, $lineProject, $allowed]) {
            $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreements[$agreement]->id, 'invoice_number' => 'SYN-'.$name, 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'balance_amount' => 10000, 'is_visible_to_client' => true]);
            // Cadence retainer lines commonly carry their project only through
            // the parent agreement, so even the agreement-only case has a line.
            ClientInvoiceLine::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_project_id' => $lineProject?->id, 'type' => 'retainer', 'description' => 'Synthetic '.$name.' retainer', 'quantity' => '1', 'unit_amount' => 10000, 'total_amount' => 10000, 'tax_amount' => 0, 'sort_order' => 0]);
            $created[] = [$invoice, $allowed];
            $this->assertSame($allowed, app(PortalInvoiceQuery::class)->visibleTo($company, $viewer)->whereKey($invoice->id)->exists(), $name);
            $this->assertSame($allowed, app(AgentAccess::class)->canViewInvoice($viewer, $invoice), $name);
            $this->getJson($base.'/'.$invoice->public_id)->assertStatus($allowed ? 200 : 404);
            if ($allowed) {
                $expectedIds[] = $invoice->public_id;
            }
        }
        $this->assertSame($expectedIds, array_column($this->getJson($base)->assertOk()->json('data'), 'id'));
        $this->assertSame(30000, app(AgentReadService::class)->summary($viewer, $workspace, fn (string $scope): bool => $scope === 'billing:read')['invoices']['collectible_balances'][0]['amount']);
        $this->actingAsMcp($viewer, ['mcp:use', 'billing:read', 'identity:read']);
        $initialized = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic agreement audit', 'version' => '1']]])->assertOk();
        $headers = ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $initialized->headers->get('Mcp-Session-Id')];
        $call = fn (string $name, array $arguments) => $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $headers)->assertOk();
        $this->assertSame($expectedIds, array_column($call('invoices.list', ['workspace_id' => $workspace->public_id])->json('result.structuredContent.data'), 'id'));
        foreach ($created as [$invoice, $allowed]) {
            $response = $call('invoices.get', ['workspace_id' => $workspace->public_id, 'invoice_id' => $invoice->public_id]);
            if ($allowed) {
                $response->assertJsonPath('result.structuredContent.data.id', $invoice->public_id);
            } else {
                $this->assertNotNull($response->json('error'));
                $this->assertNull($response->json('result.structuredContent'));
            }
        }
        $call('operations.summary', ['workspace_id' => $workspace->public_id])->assertJsonPath('result.structuredContent.data.invoices.collectible_balances.0.amount', 30000);

    }

    /** @return array{Workspace, ClientCompany, User, ClientCompanyMembership, ClientProject, array<string, ClientInvoice>} */
    private function fixtures(): array
    {
        $w = Workspace::query()->create(['name' => 'Synthetic audit workspace', 'slug' => 'synthetic-audit']);
        $c = ClientCompany::query()->create(['workspace_id' => $w->id, 'name' => 'Synthetic audit client', 'slug' => 'synthetic-audit-client']);
        $u = User::factory()->create(['name' => 'Synthetic audit viewer', 'email' => 'audit-viewer@example.test']);
        $m = ClientCompanyMembership::query()->create(['client_company_id' => $c->id, 'user_id' => $u->id, 'role' => 'client', 'access_scope' => 'projects']);
        $a = ClientProject::query()->create(['workspace_id' => $w->id, 'client_company_id' => $c->id, 'name' => 'Synthetic granted']);
        $b = ClientProject::query()->create(['workspace_id' => $w->id, 'client_company_id' => $c->id, 'name' => 'Synthetic ungranted']);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $w->id, 'client_company_membership_id' => $m->id, 'client_project_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
        $invoices = [];
        foreach (['granted' => [$a], 'ungranted' => [$b], 'mixed' => [$a, $b], 'no-lineage' => []] as $name => $projects) {
            $i = ClientInvoice::query()->create(['workspace_id' => $w->id, 'client_company_id' => $c->id, 'invoice_number' => 'SYN-'.$name, 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'balance_amount' => 10000, 'is_visible_to_client' => true]);
            foreach ($projects as $p) {
                ClientInvoiceLine::query()->create(['workspace_id' => $w->id, 'client_invoice_id' => $i->id, 'client_project_id' => $p->id, 'type' => 'time', 'description' => 'Synthetic '.$name.' work', 'quantity' => '1', 'unit_amount' => 10000, 'total_amount' => 10000, 'tax_amount' => 0, 'sort_order' => 0]);
            }
            $invoices[$name] = $i;
        }

        return [$w, $c, $u, $m, $a, $invoices];
    }
}
