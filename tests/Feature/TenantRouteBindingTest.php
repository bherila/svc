<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TenantRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_company_is_never_materialized_by_a_real_workspace_request(): void
    {
        [$user, $workspace, $company] = $this->fixture();
        $other = Workspace::query()->create(['name' => 'Other synthetic workspace', 'slug' => 'other']);
        $company->update(['workspace_id' => $other->id]);
        $queries = $this->capture();

        $this->actingAs($user)->getJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")->assertNotFound();

        $this->assertScopedReads($queries, 'client_companies', $workspace->id);
    }

    public function test_workspace_membership_is_checked_before_any_company_read(): void
    {
        [, $workspace, $company] = $this->fixture();
        $user = User::factory()->create();
        $queries = $this->capture();

        $this->actingAs($user)->getJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")->assertForbidden();

        $this->assertCount(0, $this->reads($queries, 'client_companies'));
    }

    public function test_nested_project_is_scoped_to_both_workspace_and_company_before_read(): void
    {
        [$user, $workspace, $company] = $this->fixture();
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic client', 'slug' => 'other-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $other->id, 'name' => 'Synthetic project']);
        $queries = $this->capture();

        $this->actingAs($user)->getJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects/{$project->public_id}")->assertNotFound();

        $this->assertScopedReads($queries, 'client_projects', $workspace->id);
        foreach ($this->reads($queries, 'client_projects') as $query) {
            $this->assertStringContainsString('client_company_id', $query->sql);
            $this->assertContains($company->id, $query->bindings);
        }
    }

    public function test_portal_company_binding_constrains_access_in_sql(): void
    {
        [, , $company] = $this->fixture();
        $outsider = User::factory()->create();
        $queries = $this->capture();

        $this->actingAs($outsider)->getJson("/portal/{$company->public_id}")->assertNotFound();

        $reads = $this->reads($queries, 'client_companies');
        $this->assertCount(1, $reads);
        $this->assertStringContainsString('workspace_id', $reads[0]->sql);
        $this->assertStringContainsString('client_company_memberships', $reads[0]->sql);
        $this->assertContains($outsider->id, $reads[0]->bindings);
    }

    public function test_portal_membership_in_one_company_does_not_grant_another_in_the_same_workspace(): void
    {
        [, $workspace, $company] = $this->fixture();
        $user = User::factory()->create();
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic client', 'slug' => 'other-client']);
        $other->portalUsers()->attach($user->id, ['role' => 'client']);
        $this->actingAs($user)->getJson("/portal/{$company->public_id}")->assertNotFound();
        $this->getJson("/portal/{$other->public_id}")->assertOk();
    }

    public function test_a_binding_without_a_tenant_context_fails_before_reading(): void
    {
        [$user, , $company] = $this->fixture();
        Route::middleware(['web', 'auth'])->get('/binding-probe/{clientCompany}',
            fn (ClientCompany $clientCompany) => response()->noContent());
        $queries = $this->capture();
        $this->actingAs($user)->getJson('/binding-probe/'.$company->public_id)->assertNotFound();
        $this->assertCount(0, $this->reads($queries, 'client_companies'));
    }

    public function test_including_soft_deleted_records_cannot_bypass_tenant_binding(): void
    {
        [$user, $workspace] = $this->fixture();
        Route::middleware(['web', 'auth'])->get('/workspaces/{workspace}/binding-probe/{expense}',
            fn (Workspace $workspace, ClientExpense $expense) => response()->noContent())->withTrashed();
        $queries = $this->capture();
        $this->actingAs($user)->getJson('/workspaces/'.$workspace->public_id.'/binding-probe/00000000-0000-4000-8000-000000000001')->assertNotFound();
        $this->assertScopedReads($queries, 'client_expenses', $workspace->id);
    }

    #[DataProvider('tenantRoutes')]
    public function test_each_implicit_binding_surface_queries_with_its_workspace(string $method, string $suffix, string $table): void
    {
        [$user, $workspace] = $this->fixture();
        Sanctum::actingAs($user, ['finance.reconcile']);
        $queries = $this->capture();
        $prefix = str_starts_with($suffix, '/api/') ? '/api/v1' : '';
        $suffix = str_replace('/api/', '/', $suffix);
        $this->actingAs($user)->json($method, $prefix.'/workspaces/'.$workspace->public_id.$suffix)->assertNotFound();
        $this->assertScopedReads($queries, $table, $workspace->id);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function tenantRoutes(): iterable
    {
        $id = '00000000-0000-4000-8000-000000000001';
        yield 'company' => ['GET', "/clients/{$id}", 'client_companies'];
        yield 'invoice' => ['POST', "/invoices/{$id}/issue", 'client_invoices'];
        yield 'project' => ['POST', "/projects/{$id}/time-entries", 'client_projects'];
        yield 'agreement' => ['POST', "/agreements/{$id}/activate", 'client_agreements'];
        yield 'proposal' => ['POST', "/proposals/{$id}/send", 'client_proposals'];
        yield 'task' => ['PATCH', "/tasks/{$id}", 'client_tasks'];
        yield 'schedule' => ['POST', "/billing-schedules/{$id}/generate", 'client_billing_schedules'];
        yield 'reconciliation' => ['DELETE', "/api/invoice-payments/{$id}/reconciliations/synthetic/{$id}", 'client_invoice_payments'];
    }

    /** @return array{User, Workspace, ClientCompany} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic workspace', 'slug' => 'synthetic']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);

        return [$user, $workspace, $company];
    }

    /** @return \ArrayObject<int, QueryExecuted> */
    private function capture(): \ArrayObject
    {
        $queries = new \ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries[] = $query;
        });

        return $queries;
    }

    /** @param \ArrayObject<int, QueryExecuted> $queries
     * @return list<QueryExecuted>
     */
    private function reads(\ArrayObject $queries, string $table): array
    {
        return array_values(array_filter($queries->getArrayCopy(), static fn (QueryExecuted $query): bool => preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?\s/i', $query->sql) === 1));
    }

    /** @param \ArrayObject<int, QueryExecuted> $queries */
    private function assertScopedReads(\ArrayObject $queries, string $table, int $workspace): void
    {
        $reads = $this->reads($queries, $table);
        $this->assertNotEmpty($reads);
        foreach ($reads as $query) {
            $this->assertStringContainsString('workspace_id', $query->sql);
            $this->assertContains($workspace, $query->bindings);
        }
    }
}
