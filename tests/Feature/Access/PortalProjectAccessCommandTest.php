<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentPortalContactReadService;
use App\Services\Authorization\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * The portal scope command is a system-actor caller of the same operation the
 * settings page, REST and MCP use (#385): the same fields - the contact's
 * current version and explicit confirmation - and the same validation.
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
        $this->client = User::factory()->create(['email' => 'portal-command@client.example.test']);
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id,
            'user_id' => $this->client->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);
    }

    public function test_narrowing_reports_the_projects_granted_and_is_audited_as_the_system(): void
    {
        $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change([
            // The address is matched as sign-in matches it, ignoring case.
            'email' => 'PORTAL-COMMAND@client.example.test',
            '--project' => [$this->first->public_id, $this->second->public_id],
        ])))->expectsOutputToContain('That user now sees 2 project(s) and nothing else.')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $event = AccessAuditEvent::query()->sole();
        $this->assertSame(['portal.scope_changed', 'system', null], [$event->action, $event->actor_type, $event->actor_user_id]);
    }

    public function test_show_reports_the_scope_projects_and_the_version_a_change_needs(): void
    {
        $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change(['--project' => [$this->second->public_id, $this->first->public_id]])))->assertSuccessful();

        $this->artisan('svc:portal:project-access', ['company' => $this->company->public_id, 'email' => $this->client->email, '--show' => true])
            ->expectsOutputToContain('projects')
            ->expectsOutputToContain($this->version())
            ->expectsOutputToContain('First')
            ->expectsOutputToContain('Second')
            ->assertSuccessful();
        $this->assertSame(1, AccessAuditEvent::query()->count());
    }

    public function test_restoring_company_wide_access_reports_it(): void
    {
        $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change(['--project' => [$this->first->public_id]])))->assertSuccessful();

        $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change(['--company-wide' => true])))
            ->expectsOutputToContain('That user sees every client-visible project again.')->assertSuccessful();

        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
    }

    public function test_a_project_outside_the_company_fails_with_the_api_message_and_changes_nothing(): void
    {
        $sibling = $this->project($this->company($this->company->workspace, 'sibling-client'), 'Sibling');

        $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change(['--project' => [$this->first->public_id, $sibling->public_id]])))
            ->expectsOutputToContain('One or more projects do not belong to that client.')->assertFailed();

        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_it_refuses_what_the_api_refuses_before_anything_is_written(): void
    {
        $cases = [
            'no projects' => [[], 'Give at least one --project, or --company-wide to remove the restriction.'],
            'no version' => [['--expected-version' => null, '--project' => [$this->first->public_id]], '--expected-version'],
            'stale version' => [['--expected-version' => str_repeat('0', 64), '--project' => [$this->first->public_id]], 'Portal access changed. Read it again before confirming.'],
            'unconfirmed' => [['--confirm' => false, '--project' => [$this->first->public_id]], '--confirm'],
            'repeated project' => [['--project' => [$this->first->public_id, $this->first->public_id]], '--project'],
            'not a project id' => [['--project' => ['not-a-project-id']], '--project'],
            'company-wide with projects' => [['--company-wide' => true, '--project' => [$this->first->public_id]], 'Access to the whole client is not limited to projects.'],
        ];
        foreach ($cases as $case => [$options, $message]) {
            $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change($options)))->expectsOutputToContain($message)->assertFailed();
            $this->assertSame(0, AccessAuditEvent::query()->count(), $case);
        }
        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
    }

    public function test_someone_without_portal_access_to_the_company_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->artisan('svc:portal:project-access', ['--apply' => true] + ($this->change(['email' => $stranger->email, '--company-wide' => true])))
            ->expectsOutputToContain('That user is not a portal member of that company.')->assertFailed();
    }

    public function test_default_preview_checks_the_operation_without_changing_scope_or_writing_audit(): void
    {
        $this->artisan('svc:portal:project-access', $this->change(['--project' => [$this->first->public_id], '--format' => 'json']))
            ->expectsOutputToContain('"dry_run":true')->assertSuccessful();
        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertDatabaseCount('access_audit_events', 0);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function change(array $options): array
    {
        return array_filter([
            'company' => $this->company->public_id,
            'email' => $this->client->email,
            '--expected-version' => $this->version(),
            '--confirm' => true,
            ...$options,
        ], static fn (mixed $value): bool => $value !== null && $value !== false);
    }

    private function version(): string
    {
        return (string) app(AgentPortalContactReadService::class)->contact($this->company->workspace, $this->company, $this->client->public_id)['version'];
    }
}
