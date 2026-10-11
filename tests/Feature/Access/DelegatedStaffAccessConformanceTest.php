<?php

namespace Tests\Feature\Access;

use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\ProjectRole;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * The delegated staff access adapter against the package's normative semantics,
 * contract version 3, with removal effects.
 *
 * The manager owns two workspaces and nothing in a third. The target is a member
 * of the first (editable), an owner of the second (never editable) and a member
 * of the unseen one. The removable person is a member of the first, an admin of
 * the second and a member of the unseen one. A hidden person matching the same
 * searches belongs only to the unseen workspace. Every assertion reads the
 * tables directly.
 */
final class DelegatedStaffAccessConformanceTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use BuildsAccessFixtures;
    use RefreshDatabase;

    private const PROVIDER = 'example-provider';

    private Workspace $first;

    private Workspace $second;

    private Workspace $unseen;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bherila-auth.oauth_client.provider' => self::PROVIDER,
            'bherila-auth.delegated_access.application' => 'svc',
            'bherila-auth.delegated_access.issuer' => 'https://identity.example.test',
            'bherila-auth.delegated_access.oauth_provider' => self::PROVIDER,
        ]);

        $this->first = Workspace::query()->create(['name' => 'First Workspace', 'slug' => 'first']);
        $this->second = Workspace::query()->create(['name' => 'Second Workspace', 'slug' => 'second']);
        $this->unseen = Workspace::query()->create(['name' => 'Unseen Workspace', 'slug' => 'unseen']);

        $manager = $this->bound('manager-subject', 'Example Manager');
        $this->addStaff($this->first, $manager, 'owner');
        $this->addStaff($this->second, $manager, 'owner');

        $target = $this->bound('target-subject', 'Example Target');
        $this->addStaff($this->first, $target, 'member');
        $this->addStaff($this->second, $target, 'owner');
        $this->addStaff($this->unseen, $target, 'member');

        $removable = $this->bound('removable-subject', 'Example Removable');
        $this->addStaff($this->first, $removable, 'member');
        $this->addStaff($this->second, $removable, 'admin');
        $this->addStaff($this->unseen, $removable, 'member');

        // Matches the searches below, but only in a workspace the manager cannot see.
        $this->addStaff($this->unseen, $this->bound('hidden-subject', 'Example Hidden Person'), 'member');

        // Bound and staff, but manages nothing.
        $this->addStaff($this->first, $this->bound('stranger-subject', 'Example Stranger'), 'member');
    }

    public function test_the_adapter_follows_the_normative_update_semantics(): void
    {
        $this->assertDelegatedActorRefusedEverywhere('stranger-subject', 'target-subject', $this->first->public_id);
        $this->assertDelegatedProtectedMembershipsHold('manager-subject', 'target-subject');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('manager-subject', 'target-subject');
        $this->assertDelegatedStaleRevisionRefused('manager-subject', 'target-subject');
        $this->assertDelegatedUnadvertisedRoleRefused('manager-subject', 'target-subject');
        $this->assertDelegatedUpdateKeepsUnseenMemberships('manager-subject', 'target-subject');
    }

    public function test_a_search_stays_in_the_managers_scope(): void
    {
        $this->assertDelegatedSearchStaysInScope('manager-subject', 'subjects', 'Example', 'Hidden Person');
        $this->assertDelegatedSearchStaysInScope('manager-subject', 'workspaces', 'Workspace', 'Unseen');
    }

    public function test_a_removal_is_the_whole_managed_projection_or_nothing(): void
    {
        $this->assertDelegatedRemoveRefusedWithoutPartialChange('manager-subject', 'target-subject');
        $this->assertDelegatedRemoveStripsOnlyTheManagedProjection('manager-subject', 'removable-subject');
    }

    public function test_metadata_is_well_formed(): void
    {
        $this->assertDelegatedMetadataIsWellFormed('manager-subject', 'removable-subject');
    }

    public function test_writes_replay_from_their_receipts_through_the_endpoint(): void
    {
        $this->assertDelegatedReceiptsReplayThroughTheEndpoint('manager-subject', 'target-subject');
    }

    public function test_the_revision_covers_the_managers_reach(): void
    {
        $colleague = $this->bound('colleague-subject', 'Example Colleague');
        $this->addStaff($this->first, $colleague, 'member');
        $this->addStaff($this->unseen, $colleague, 'member');
        $manager = User::query()->where('oauth_subject', 'manager-subject')->sole();

        $this->assertDelegatedRevisionCoversTheActorsReach('manager-subject', 'colleague-subject',
            fn () => $this->addStaff($this->unseen, $manager, 'admin'));
    }

    public function test_the_disclosed_assignments_are_in_the_revision(): void
    {
        $person = $this->bound('effects-subject', 'Example Assigned');
        $this->addStaff($this->first, $person, 'member');
        $client = $this->company($this->first, 'first-client');
        $alpha = $this->project($client, 'Alpha');
        $beta = $this->project($client, 'Beta');
        $this->assign($person, $alpha, ProjectRole::Contributor);

        // Another project, the same role: the count shown stays 1.
        $swap = function () use ($person, $alpha, $beta): void {
            ClientProjectMembership::query()->where('user_id', $person->id)->where('client_project_id', $alpha->id)->delete();
            $this->assign($person, $beta, ProjectRole::Contributor);
        };

        $this->assertDelegatedRemovalEffectsAreInTheRevision('manager-subject', 'effects-subject', $swap, fn (): array => $this->assignments($person));
    }

    public function test_each_fresh_write_takes_exactly_what_it_disclosed(): void
    {
        $firstClient = $this->company($this->first, 'first-client');
        $alpha = $this->project($firstClient, 'Alpha');
        $beta = $this->project($firstClient, 'Beta');
        $secondClient = $this->company($this->second, 'second-client');
        $gamma = $this->project($secondClient, 'Gamma');
        $unseenClient = $this->company($this->unseen, 'unseen-client');
        $delta = $this->project($unseenClient, 'Delta');

        $removed = $this->bound('remove-subject', 'Example Removed');
        $this->addStaff($this->first, $removed, 'member');
        $this->addStaff($this->unseen, $removed, 'member');
        $this->assign($removed, $alpha, ProjectRole::Manager);
        $this->assign($removed, $beta, ProjectRole::Viewer);
        $this->assign($removed, $delta, ProjectRole::Contributor);

        $dropped = $this->bound('drop-subject', 'Example Dropped');
        $this->addStaff($this->first, $dropped, 'member');
        $this->addStaff($this->second, $dropped, 'member');
        $this->assign($dropped, $alpha, ProjectRole::Contributor);
        $this->assign($dropped, $gamma, ProjectRole::Viewer);

        $promoted = $this->bound('role-subject', 'Example Promoted');
        $this->addStaff($this->first, $promoted, 'member');
        $this->assign($promoted, $beta, ProjectRole::Contributor);
        $beforeRole = $this->assignments($promoted);

        $this->assertDelegatedWriteTakesExactlyWhatItDisclosed('manager-subject', 'remove-subject', 'remove',
            fn (): array => $this->assignments($removed), ['Delta' => 'contributor']);
        $this->assertDelegatedWriteTakesExactlyWhatItDisclosed('manager-subject', 'drop-subject', 'drop',
            fn (): array => $this->assignments($dropped), ['Gamma' => 'viewer']);
        $this->assertDelegatedWriteTakesExactlyWhatItDisclosed('manager-subject', 'role-subject', 'role',
            fn (): array => $this->assignments($promoted), $beforeRole);
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $user = User::query()->where('oauth_provider', self::PROVIDER)->where('oauth_subject', $subject)->value('id');
        $workspaces = [];
        foreach (WorkspaceMembership::query()->where('user_id', $user)->with('workspace')->orderBy('workspace_id')->get() as $membership) {
            $workspaces[$membership->workspace->public_id] = $membership->role;
        }

        // No application-wide administrator here.
        return ['application_admin' => false, 'workspaces' => $workspaces];
    }

    protected function delegatedAccessManager(): string
    {
        return 'manager-subject';
    }

    private function bound(string $subject, string $name): User
    {
        // Addresses that match none of the searches, so each search matches on the name alone.
        return User::factory()->create(['name' => $name, 'email' => $subject.'@directory.test', 'oauth_provider' => self::PROVIDER, 'oauth_subject' => $subject]);
    }

    /** @return array<string, string> project name => assignment role, every workspace included */
    private function assignments(User $user): array
    {
        $assignments = [];
        foreach (ClientProjectMembership::query()->where('user_id', $user->id)->get() as $assignment) {
            $assignments[(string) ClientProject::query()->whereKey($assignment->getAttribute('client_project_id'))->value('name')] = $assignment->role->value;
        }
        ksort($assignments);

        return $assignments;
    }
}
