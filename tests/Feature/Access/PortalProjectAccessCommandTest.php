<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * The portal scope command, now a system-actor caller of the access boundary,
 * keeps the output and exit codes it had.
 */
final class PortalProjectAccessCommandTest extends TestCase
{
    use BuildsAccessFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private ClientProject $first;

    private ClientProject $second;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $workspace = Workspace::query()->create(['name' => 'Command', 'slug' => 'command']);
        $this->company = $this->company($workspace, 'command-client');
        $this->first = $this->project($this->company, 'First');
        $this->second = $this->project($this->company, 'Second');
        $this->client = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id,
            'user_id' => $this->client->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);
    }

    public function test_narrowing_reports_the_distinct_projects_granted_and_is_audited_as_the_system(): void
    {
        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id,
            'email' => $this->client->email,
            '--project' => [$this->first->public_id, $this->second->public_id, $this->first->public_id],
        ])->expectsOutputToContain('That user now sees 2 project(s) and nothing else.')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $event = AccessAuditEvent::query()->sole();
        $this->assertSame(['portal.scope_changed', 'system', null], [$event->action, $event->actor_type, $event->actor_user_id]);
    }

    public function test_restoring_company_wide_access_reports_it(): void
    {
        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id, 'email' => $this->client->email, '--project' => [$this->first->public_id],
        ])->assertSuccessful();

        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id, 'email' => $this->client->email, '--company-wide' => true,
        ])->expectsOutputToContain('That user sees every client-visible project again.')->assertSuccessful();

        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
    }

    public function test_a_project_outside_the_company_fails_with_the_same_message_and_changes_nothing(): void
    {
        $sibling = $this->project($this->company($this->company->workspace, 'sibling-client'), 'Sibling');

        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id, 'email' => $this->client->email, '--project' => [$this->first->public_id, $sibling->public_id],
        ])->expectsOutputToContain('One or more projects do not belong to that company.')->assertFailed();

        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_no_project_and_no_company_wide_flag_fails_before_anything_is_written(): void
    {
        $this->artisan('svc:portal:project-access', [
            'company' => $this->company->public_id, 'email' => $this->client->email,
        ])->expectsOutputToContain('Give at least one --project, or --company-wide to remove the restriction.')->assertFailed();

        $this->assertSame(0, AccessAuditEvent::query()->count());
    }
}
