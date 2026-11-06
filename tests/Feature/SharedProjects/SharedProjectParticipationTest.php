<?php

namespace Tests\Feature\SharedProjects;

use App\Models\AccessAuditEvent;
use App\Models\ClientProject;
use App\Models\ClientProjectParticipation;
use App\Models\ClientProjectParticipationInvitation;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Services\SharedProjects\SharedProjectAccess;
use App\Services\SharedProjects\SharedProjectParticipations;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsSharedProjects;
use Tests\TestCase;

/**
 * Invite, accept, decline, revoke, leave and link: the lifecycle of a
 * participation, and what each step does and does not give.
 */
final class SharedProjectParticipationTest extends TestCase
{
    use BuildsSharedProjects;
    use RefreshDatabase;

    private SharedProjectParticipations $participations;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        $this->participations = app(SharedProjectParticipations::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function standsAtShare(User $user, string $workspaceKey): bool
    {
        $w = $this->world;

        return app(SharedProjectAccess::class)->resolve($user, $w[$workspaceKey], $w['project']->public_id) !== null;
    }

    private function refused(int $status, callable $act): void
    {
        try {
            $act();
        } catch (HttpException $refused) {
            $this->assertSame($status, $refused->getStatusCode());

            return;
        }
        $this->fail("Expected a {$status} refusal.");
    }

    public function test_a_pending_invitation_grants_nothing_until_it_is_accepted(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');

        $invitation = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, strtoupper((string) $invitee->email));

        $this->assertSame((string) $invitee->email, $invitation->email, 'The address is stored lower-cased.');
        $this->assertFalse($this->standsAtShare($invitee, 'stranger'), 'A pending invitation is not access.');
        $this->assertSame(0, ClientProjectParticipation::query()->where('participant_workspace_id', $w['stranger']->id)->count());

        $this->participations->accept($invitee, $invitation->public_id, $w['stranger']);

        $this->assertTrue($this->standsAtShare($invitee, 'stranger'));
    }

    public function test_only_the_hosts_owners_and_admins_invite_and_only_to_its_own_projects(): void
    {
        $this->sharedWorld();
        $w = $this->world;

        // A project manager, a viewer, a plain member and a participant are all refused.
        foreach (['hostManager', 'hostViewer'] as $who) {
            $this->refused(403, fn () => $this->participations->invite($w[$who], $w['host'], $w['project']->public_id, 'someone@example.test'));
        }
        $this->refused(404, fn () => $this->participations->invite($w['hostPlain'], $w['host'], $w['project']->public_id, 'someone@example.test'));
        $this->refused(404, fn () => $this->participations->invite($w['aOwner'], $w['a'], $w['project']->public_id, 'someone@example.test'));
        // A project of another workspace is not found, even by its own host's owner standing in the wrong workspace.
        $this->refused(404, fn () => $this->participations->invite($w['hostOwner'], $w['host'], $w['aLocal']->public_id, 'someone@example.test'));
        $this->assertSame(0, ClientProjectParticipationInvitation::query()->where('email', 'someone@example.test')->count());
    }

    public function test_a_bad_address_is_refused_before_anything_is_written(): void
    {
        $this->sharedWorld();
        $w = $this->world;

        $this->expectException(ValidationException::class);
        try {
            $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, 'not-an-address');
        } finally {
            $this->assertSame(0, ClientProjectParticipationInvitation::query()->where('email', 'not-an-address')->count());
        }
    }

    public function test_a_newer_invitation_to_the_same_address_replaces_the_pending_one(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');

        $first = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $second = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);

        $this->assertNull($this->participations->openInvitationFor($invitee, $first->public_id), 'The old link stops working.');
        $this->assertNotNull($this->participations->openInvitationFor($invitee, $second->public_id));
    }

    public function test_an_invitation_is_visible_only_to_the_person_it_is_addressed_to_and_only_while_open(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');
        $invitation = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);

        $this->assertNotNull($this->participations->openInvitationFor($invitee, $invitation->public_id));
        $this->assertNull($this->participations->openInvitationFor($w['strangerOwner'], $invitation->public_id), 'Someone else holding the link is told nothing.');
        $this->assertNull($this->participations->openInvitationFor($w['hostOwner'], $invitation->public_id), 'Not even the inviter reads it as the invitee.');
        $this->assertNull($this->participations->openInvitationFor($invitee, '00000000-0000-4000-8000-000000000000'));
        $this->assertCount(1, $this->participations->openInvitationsFor($invitee));
        $this->assertCount(0, $this->participations->openInvitationsFor($w['strangerOwner']));

        // It lapses at the instant it expires.
        CarbonImmutable::setTestNow($invitation->expires_at);
        $this->assertNull($this->participations->openInvitationFor($invitee, $invitation->public_id));
        $this->assertCount(0, $this->participations->openInvitationsFor($invitee));
        $this->refused(404, fn () => $this->participations->accept($invitee, $invitation->public_id, $w['stranger']));
        $this->assertFalse($this->standsAtShare($invitee, 'stranger'));
    }

    public function test_the_address_must_match_and_the_accepting_workspace_must_be_one_the_person_administers(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');
        $invitation = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $member = $this->person($w['stranger'], 'member', 'Mo Member');
        $memberInvitation = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $member->email);

        // Another person cannot accept it, even into their own workspace.
        $this->refused(404, fn () => $this->participations->accept($w['strangerOwner'], $invitation->public_id, $w['stranger']));
        // A workspace the invitee does not belong to.
        $this->refused(404, fn () => $this->participations->accept($invitee, $invitation->public_id, $w['b']));
        // A plain member is not an owner or admin of the workspace.
        $this->refused(403, fn () => $this->participations->accept($member, $memberInvitation->public_id, $w['stranger']));
        // The host cannot join itself.
        $hostOwnerAlt = $this->person($w['host'], 'owner', 'Hollis Second');
        $self = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $hostOwnerAlt->email);
        $this->expectException(ValidationException::class);
        $this->participations->accept($hostOwnerAlt, $self->public_id, $w['host']);
    }

    public function test_a_workspace_that_already_takes_part_cannot_join_twice(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $again = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $w['aAdmin']->email);

        $this->expectException(ValidationException::class);
        $this->participations->accept($w['aAdmin'], $again->public_id, $w['a']);
    }

    public function test_an_invitation_is_single_use_and_declining_or_revoking_it_closes_it(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');

        $declined = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $this->participations->decline($invitee, $declined->public_id);
        $this->refused(404, fn () => $this->participations->accept($invitee, $declined->public_id, $w['stranger']));
        $this->assertFalse($this->standsAtShare($invitee, 'stranger'));

        $revoked = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $this->participations->revokeInvitation($w['hostOwner'], $w['host'], $w['project']->public_id, $revoked->public_id);
        $this->refused(404, fn () => $this->participations->accept($invitee, $revoked->public_id, $w['stranger']));
        $this->refused(404, fn () => $this->participations->revokeInvitation($w['hostOwner'], $w['host'], $w['project']->public_id, $revoked->public_id));

        $used = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $this->participations->accept($invitee, $used->public_id, $w['stranger']);
        $this->refused(404, fn () => $this->participations->accept($invitee, $used->public_id, $w['stranger']));
        $this->refused(404, fn () => $this->participations->decline($invitee, $used->public_id));
    }

    public function test_only_the_host_can_revoke_an_invitation_and_only_through_its_own_project(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');
        $invitation = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);

        $this->refused(403, fn () => $this->participations->revokeInvitation($w['hostManager'], $w['host'], $w['project']->public_id, $invitation->public_id));
        // Another workspace cannot reach the host's invitation, and the path's project must be the invitation's.
        $this->refused(404, fn () => $this->participations->revokeInvitation($w['aOwner'], $w['a'], $w['project']->public_id, $invitation->public_id));
        $this->refused(404, fn () => $this->participations->revokeInvitation($w['hostOwner'], $w['host'], $w['other']->public_id, $invitation->public_id));
        $this->assertNotNull($this->participations->openInvitationFor($invitee, $invitation->public_id));
    }

    public function test_the_host_revoking_a_participant_ends_its_access_links_and_assignments_at_once(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $assignment = $this->assignment($w['shared'], $w['aOwner'], $w['a']);
        $kept = $this->assignment($w['shared'], $w['bOwner'], $w['b']);
        $participation = ClientProjectParticipation::query()->where('participant_workspace_id', $w['a']->id)->firstOrFail();
        $this->assertTrue($this->standsAtShare($w['aOwner'], 'a'));

        $this->refused(403, fn () => $this->participations->revokeParticipant($w['hostManager'], $w['host'], $w['project']->public_id, $participation->public_id));
        $this->refused(404, fn () => $this->participations->revokeParticipant($w['hostOwner'], $w['host'], $w['other']->public_id, $participation->public_id));
        $this->refused(404, fn () => $this->participations->revokeParticipant($w['aOwner'], $w['a'], $w['project']->public_id, $participation->public_id));

        $revoked = $this->participations->revokeParticipant($w['hostOwner'], $w['host'], $w['project']->public_id, $participation->public_id);

        $this->assertNotNull($revoked->revoked_at);
        $this->assertNull($revoked->active_marker);
        $this->assertFalse($this->standsAtShare($w['aOwner'], 'a'));
        $this->assertTrue($this->standsAtShare($w['bOwner'], 'b'), 'Another participant is untouched.');
        $this->assertNull(ClientProject::query()->whereKey($w['aLocal']->id)->value('shared_participation_id'), 'The local project is unlinked.');
        $this->assertNotNull(ClientProject::query()->whereKey($w['bLocal']->id)->value('shared_participation_id'));
        $this->assertSame(1, ClientProject::query()->whereKey($w['aLocal']->id)->count(), 'Its own project, time and books stay with it.');
        $this->assertNull(ClientTaskAssignment::query()->whereKey($assignment->id)->first(), 'Its hands come off the host\'s tasks.');
        $this->assertNotNull(ClientTaskAssignment::query()->whereKey($kept->id)->first());
        $this->refused(404, fn () => $this->participations->revokeParticipant($w['hostOwner'], $w['host'], $w['project']->public_id, $participation->public_id));
    }

    public function test_leaving_ends_access_and_is_the_participants_owners_and_admins_to_do(): void
    {
        $this->sharedWorld();
        $w = $this->world;

        $this->refused(403, fn () => $this->participations->leave($w['aMember'], $w['a'], $w['project']->public_id));
        $this->refused(404, fn () => $this->participations->leave($w['strangerOwner'], $w['stranger'], $w['project']->public_id));
        $this->refused(404, fn () => $this->participations->leave($w['hostOwner'], $w['a'], $w['project']->public_id));
        $this->assertTrue($this->standsAtShare($w['aOwner'], 'a'));

        $left = $this->participations->leave($w['aAdmin'], $w['a'], $w['project']->public_id);

        $this->assertNotNull($left->left_at);
        $this->assertNull($left->active_marker);
        $this->assertFalse($this->standsAtShare($w['aOwner'], 'a'));
        $this->assertFalse($this->standsAtShare($w['aAdmin'], 'a'));
        $this->assertNull(ClientProject::query()->whereKey($w['aLocal']->id)->value('shared_participation_id'));
        $this->refused(404, fn () => $this->participations->leave($w['aOwner'], $w['a'], $w['project']->public_id));
    }

    public function test_a_workspace_can_be_invited_again_after_it_leaves_or_is_revoked(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $this->participations->leave($w['aOwner'], $w['a'], $w['project']->public_id);
        $this->assertFalse($this->standsAtShare($w['aOwner'], 'a'));

        $this->join($w['host'], $w['hostOwner'], $w['project'], $w['aOwner'], $w['a']);

        $this->assertTrue($this->standsAtShare($w['aOwner'], 'a'));
        $this->assertSame(2, ClientProjectParticipation::query()->where('participant_workspace_id', $w['a']->id)->count());
        $this->assertSame(1, ClientProjectParticipation::query()->where('participant_workspace_id', $w['a']->id)->whereNotNull('active_marker')->count());
    }

    public function test_the_database_allows_one_active_participation_per_project_and_workspace(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $active = ClientProjectParticipation::query()->where('participant_workspace_id', $w['a']->id)->firstOrFail();

        $this->expectException(QueryException::class);
        (new ClientProjectParticipation)->forceFill([
            'host_workspace_id' => $w['host']->id, 'client_project_id' => $w['project']->id, 'participant_workspace_id' => $w['a']->id,
            'granted_at' => $active->granted_at, 'accepted_at' => $active->accepted_at, 'active_marker' => 1,
        ])->save();
    }

    public function test_a_participant_links_one_of_its_own_projects_and_only_one_at_a_time(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $second = ClientProject::query()->create(['workspace_id' => $w['a']->id, 'client_company_id' => $w['aLocal']->client_company_id, 'name' => 'Second local project']);

        $this->refused(403, fn () => $this->participations->linkLocalProject($w['aMember'], $w['a'], $w['project']->public_id, $second->public_id));
        $this->refused(404, fn () => $this->participations->linkLocalProject($w['strangerOwner'], $w['stranger'], $w['project']->public_id, $second->public_id));
        // Not one of its own: the host's project, or another participant's.
        foreach ([$w['other'], $w['bLocal'], $w['project']] as $foreign) {
            try {
                $this->participations->linkLocalProject($w['aOwner'], $w['a'], $w['project']->public_id, $foreign->public_id);
                $this->fail('A project outside the participant\'s workspace was linked.');
            } catch (ValidationException) {
                $this->assertNotSame($w['host']->id, ClientProject::query()->whereKey($w['aLocal']->id)->value('workspace_id'));
            }
        }

        $this->participations->linkLocalProject($w['aOwner'], $w['a'], $w['project']->public_id, $second->public_id);

        $this->assertNull(ClientProject::query()->whereKey($w['aLocal']->id)->value('shared_participation_id'));
        $this->assertNotNull(ClientProject::query()->whereKey($second->id)->value('shared_participation_id'));

        $this->participations->linkLocalProject($w['aOwner'], $w['a'], $w['project']->public_id, null);
        $this->assertNull(ClientProject::query()->whereKey($second->id)->value('shared_participation_id'));
    }

    public function test_a_local_project_cannot_stand_for_two_shares(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $otherShared = ClientProject::query()->create(['workspace_id' => $w['host']->id, 'client_company_id' => $w['company']->id, 'name' => 'Second shared project']);
        $this->join($w['host'], $w['hostOwner'], $otherShared, $w['aOwner'], $w['a']);

        $this->expectException(ValidationException::class);
        $this->participations->linkLocalProject($w['aOwner'], $w['a'], $otherShared->public_id, $w['aLocal']->public_id);
    }

    public function test_linking_to_a_share_the_workspace_has_left_is_not_found(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $this->participations->leave($w['aOwner'], $w['a'], $w['project']->public_id);

        $this->refused(404, fn () => $this->participations->linkLocalProject($w['aOwner'], $w['a'], $w['project']->public_id, $w['aLocal']->public_id));
    }

    public function test_every_change_is_written_to_the_access_audit_in_the_workspaces_it_touches(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $invitee = $this->person($w['stranger'], 'owner', 'Ines Invitee');
        $invitation = $this->participations->invite($w['hostOwner'], $w['host'], $w['project']->public_id, (string) $invitee->email);
        $this->participations->decline($invitee, $invitation->public_id);
        $this->participations->leave($w['aOwner'], $w['a'], $w['project']->public_id);

        $actions = fn ($workspace) => AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'like', 'shared_project.%')->orderBy('id')->pluck('action')->all();

        $this->assertSame(['shared_project.invited', 'shared_project.joined', 'shared_project.invited', 'shared_project.joined', 'shared_project.invited', 'shared_project.invitation_declined', 'shared_project.left'], $actions($w['host']));
        $this->assertSame(['shared_project.joined', 'shared_project.linked', 'shared_project.left'], $actions($w['a']));
        $this->assertSame([], $actions($w['stranger']), 'A workspace that took no part writes nothing.');
    }

    public function test_the_audit_names_ids_and_never_content(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        $recorded = (string) json_encode(AccessAuditEvent::query()->where('action', 'like', 'shared_project.%')->get(['before_state', 'after_state'])->toArray());

        $this->assertStringContainsString($w['project']->public_id, $recorded);
        foreach (['SECRET-HOST-PROJECT-NOTE', 'Synthetic Shared Project', 'SECRET-A-LOCAL-PROJECT'] as $content) {
            $this->assertStringNotContainsString($content, $recorded);
        }
    }

    public function test_nothing_in_these_services_escapes_as_an_unhandled_http_exception_type(): void
    {
        $this->sharedWorld();
        $w = $this->world;
        try {
            $this->participations->leave($w['strangerOwner'], $w['stranger'], $w['project']->public_id);
        } catch (HttpResponseException $unexpected) {
            $this->fail('A refusal must be an HttpException, not a prepared response.');
        } catch (HttpException $refused) {
            $this->assertSame(404, $refused->getStatusCode());
        }
    }
}
