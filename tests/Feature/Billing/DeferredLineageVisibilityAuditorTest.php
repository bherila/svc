<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentBillingAuditReadService;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Billing\DeferredLineageVisibilityAuditor;
use App\Services\Billing\InvoiceDeferredWorkStatements;
use App\Support\Billing\DeferredLineageVisibilityCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The read-only audit of invoices #610's lineage rule hides from project-scoped
 * portal members: the same query the portal applies, one workspace at a time
 * for a tenant, every workspace for the operator, and nothing written.
 */
final class DeferredLineageVisibilityAuditorTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array LEGACY = ['version' => 1, 'entries' => [['id' => 1, 'workedOn' => '2026-04-01', 'minutes' => 60, 'description' => 'Synthetic legacy audit work']]];

    public function test_it_counts_and_lists_only_invoices_the_lineage_rule_hides_in_the_workspace(): void
    {
        [$workspace, $company, $granted, $ungranted, $member] = $this->tenant('mine');
        $hidden = $this->invoice($workspace, $company, 'SYN-AUDIT-HIDDEN', $granted, self::LEGACY);
        $this->invoice($workspace, $company, 'SYN-AUDIT-UNGRANTED-LINE', $ungranted, self::LEGACY);
        $this->invoice($workspace, $company, 'SYN-AUDIT-EMPTY-LEGACY', $granted, ['version' => 1, 'entries' => []]);
        $this->invoice($workspace, $company, 'SYN-AUDIT-DRAFT', $granted, self::LEGACY, status: 'draft');
        $this->invoice($workspace, $company, 'SYN-AUDIT-PRIVATE', $granted, self::LEGACY, visible: false);
        $modern = $this->invoice($workspace, $company, 'SYN-AUDIT-MODERN', $granted, ['version' => 2, 'entries' => [['id' => 1, 'workedOn' => '2026-04-01', 'minutes' => 60, 'description' => null, 'projectId' => $granted->id]]]);
        DB::table(InvoiceDeferredWorkStatements::PROJECTS_TABLE)->insert(['workspace_id' => $workspace->id, 'client_invoice_id' => $modern->id, 'client_project_id' => $granted->id]);
        [$theirs, $theirCompany, $theirGranted] = $this->tenant('theirs');
        $this->invoice($theirs, $theirCompany, 'SYN-AUDIT-THEIRS', $theirGranted, self::LEGACY);

        $before = $this->fingerprint();
        $counts = app(DeferredLineageVisibilityAuditor::class)->count($workspace, 10);

        $this->assertSame([
            'with_unverifiable_lineage' => 2,
            'hidden_from_project_scoped_members' => 1,
            'affected_project_scoped_memberships' => 1,
            'hidden_invoices' => [['id' => $hidden->public_id, 'invoice_number' => 'SYN-AUDIT-HIDDEN']],
            'hidden_invoices_truncated' => false,
            'remedy' => DeferredLineageVisibilityCounts::REMEDY,
        ], $counts->toArray());
        $this->assertSame($before, $this->fingerprint(), 'The audit writes nothing.');
        // The audit agrees with the refusal it explains.
        $this->assertFalse(app(PortalInvoiceQuery::class)->visibleTo($company, $member)->whereKey($hidden->id)->exists());
        $this->assertTrue(app(PortalInvoiceQuery::class)->visibleTo($company, $member)->whereKey($modern->id)->exists());

        // Unscoped is the operator's count across every workspace, naming none.
        $all = app(DeferredLineageVisibilityAuditor::class)->count(listLimit: 10);
        $this->assertSame([3, 2, 2, [], false], [$all->withUnverifiableLineage, $all->hiddenFromProjectScopedMembers, $all->affectedProjectScopedMemberships, $all->hiddenInvoices, $all->hiddenInvoicesTruncated]);
        $theirCounts = app(DeferredLineageVisibilityAuditor::class)->count($theirs, 10);
        $this->assertSame(['SYN-AUDIT-THEIRS'], array_column($theirCounts->hiddenInvoices, 'invoice_number'));
    }

    public function test_a_company_wide_member_alone_is_not_affected_and_the_list_is_bounded(): void
    {
        [$workspace, $company, $granted, , $member] = $this->tenant('bounded');
        foreach (['SYN-AUDIT-1', 'SYN-AUDIT-2', 'SYN-AUDIT-3'] as $number) {
            $this->invoice($workspace, $company, $number, $granted, self::LEGACY);
        }

        $bounded = app(DeferredLineageVisibilityAuditor::class)->count($workspace, 2);
        $this->assertSame(['SYN-AUDIT-1', 'SYN-AUDIT-2'], array_column($bounded->hiddenInvoices, 'invoice_number'));
        $this->assertTrue($bounded->hiddenInvoicesTruncated);
        $this->assertSame(3, $bounded->hiddenFromProjectScopedMembers);

        ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $member->id)->update(['access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $none = app(DeferredLineageVisibilityAuditor::class)->count($workspace, 2);
        $this->assertSame([3, 0, 0, [], false], [$none->withUnverifiableLineage, $none->hiddenFromProjectScopedMembers, $none->affectedProjectScopedMemberships, $none->hiddenInvoices, $none->hiddenInvoicesTruncated]);
        $this->assertFalse($none->isLive());
    }

    public function test_rest_and_mcp_return_the_workspaces_audit_to_its_managers_only(): void
    {
        [$workspace, $company, $granted, , , $owner] = $this->tenant('rest');
        $hidden = $this->invoice($workspace, $company, 'SYN-AUDIT-REST', $granted, self::LEGACY);
        [$theirs, , , , , $theirOwner] = $this->tenant('rest-theirs');
        $url = '/api/v1/workspaces/'.$workspace->public_id.'/billing-audits/deferred-lineage-visibility';

        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'billing:read']);
        $rest = $this->getJson($url)->assertOk();
        $rest->assertJsonPath('data.hidden_from_project_scoped_members', 1)
            ->assertJsonPath('data.hidden_invoices.0.id', $hidden->public_id)
            ->assertJsonPath('data.remedy', DeferredLineageVisibilityCounts::REMEDY);
        $this->assertStringNotContainsString('Synthetic legacy audit work', (string) $rest->getContent());
        $this->assertSame(AgentBillingAuditReadService::LINEAGE_LIST_LIMIT, 100);
        $mcp = $this->callTool($this->initialize(), 'billing.audit_deferred_lineage_visibility', ['workspace_id' => $workspace->public_id]);
        $this->assertSame($rest->json(), $mcp['result']['structuredContent']);
        $this->getJson('/api/v1/workspaces/'.$theirs->public_id.'/billing-audits/deferred-lineage-visibility')->assertNotFound();

        $this->actingAsMcp($theirOwner, ['mcp:use', 'identity:read', 'billing:read']);
        $this->getJson($url)->assertNotFound();
    }

    public function test_the_command_prints_counts_and_the_remedy_without_naming_an_invoice(): void
    {
        [$workspace, $company, $granted] = $this->tenant('command');
        $this->invoice($workspace, $company, 'SYN-AUDIT-COMMAND', $granted, self::LEGACY);

        Artisan::call('svc:billing:audit-deferred-lineage-visibility', ['--format' => 'json']);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $json['summary']['hidden_from_project_scoped_members']);
        $this->assertSame([], $json['summary']['hidden_invoices']);

        Artisan::call('svc:billing:audit-deferred-lineage-visibility');
        $text = Artisan::output();
        $this->assertStringContainsString('reopen and reissue', $text);
        $this->assertStringNotContainsString('SYN-AUDIT-COMMAND', $text);
    }

    /** @return array{Workspace, ClientCompany, ClientProject, ClientProject, User, User} */
    private function tenant(string $slug): array
    {
        $owner = User::factory()->create(['email' => 'audit-owner-'.$slug.'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic audit '.$slug, 'slug' => 'synthetic-audit-'.$slug]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic audit client '.$slug, 'slug' => 'synthetic-audit-client-'.$slug]);
        $granted = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic granted '.$slug]);
        $ungranted = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic ungranted '.$slug]);
        $member = User::factory()->create(['email' => 'audit-member-'.$slug.'@example.test']);
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $member->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $granted->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$workspace, $company, $granted, $ungranted, $member, $owner];
    }

    /** @param array<string, mixed> $statement */
    private function invoice(Workspace $workspace, ClientCompany $company, string $number, ClientProject $project, array $statement, string $status = 'issued', bool $visible = true): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => $number, 'status' => $status,
            'currency' => 'USD', 'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'balance_amount' => 10000, 'is_visible_to_client' => $visible]);
        $invoice->forceFill(['deferred_work_statement' => $statement])->save();
        ClientInvoiceLine::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'client_project_id' => $project->id, 'type' => 'time',
            'description' => 'Synthetic '.$number.' work', 'quantity' => '1', 'unit_amount' => 10000, 'total_amount' => 10000, 'tax_amount' => 0, 'sort_order' => 0]);

        return $invoice;
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        $tables = [];
        foreach (['client_invoices', 'client_invoice_lines', 'client_company_memberships', 'client_portal_project_access', InvoiceDeferredWorkStatements::PROJECTS_TABLE] as $table) {
            $tables[$table] = hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()));
        }

        return $tables;
    }
}
