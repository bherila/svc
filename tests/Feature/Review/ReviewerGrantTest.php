<?php

namespace Tests\Feature\Review;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompanyMembership;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SharedProjects\SharedProjectParticipations;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReviewerWorld;
use Tests\TestCase;

/**
 * Giving and taking back reviewer access: who may, who may be named, what each
 * refusal says, what is audited, and that no grant leaks across providers.
 */
final class ReviewerGrantTest extends TestCase
{
    use BuildsReviewerWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
        $this->buildReviewerWorld();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    private function give(User $actor, Workspace $workspace, array $data): TestResponse
    {
        return $this->actingAs($actor)->postJson("/workspaces/{$workspace->public_id}/reviewers", $data);
    }

    private function share(Workspace $host, User $hostOwner, Workspace $participant, User $participantOwner): void
    {
        $service = app(SharedProjectParticipations::class);
        $invitation = $service->invite($hostOwner, $host, $this->herilaProject->public_id, $participantOwner->email);
        $service->accept($participantOwner, $invitation->public_id, $participant);
    }

    // --- the page ------------------------------------------------------

    public function test_owners_and_admins_open_the_page_and_it_lists_only_their_own_workspace(): void
    {
        $this->giveGrant($this->herila, $this->herilaOwner, $this->herilaVet, 'one@vet.example.test');
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet, 'two@vet.example.test');
        $admin = $this->staff($this->herila, 'admin');

        foreach ([$this->herilaOwner, $admin] as $manager) {
            $this->actingAs($manager)->get("/workspaces/{$this->herila->public_id}/reviewers")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('workspaces/reviewers')
                ->where('store_url', "/workspaces/{$this->herila->public_id}/reviewers")
                ->has('grants', 1)
                ->where('grants.0.detail', 'one@vet.example.test')
                ->has('clients', 1)
                ->where('clients.0.name', 'Synthetic Vet')
                ->where('clients.0.projects.0.name', 'Synthetic Herila Project'));
        }
    }

    public function test_a_plain_member_is_refused_and_a_stranger_gets_a_404(): void
    {
        $url = "/workspaces/{$this->herila->public_id}/reviewers";

        $this->actingAs($this->herilaWorker)->get($url)->assertForbidden();
        $this->actingAs($this->otherOwner)->get($url)->assertNotFound();
        $this->actingAs($this->ben)->get($url)->assertNotFound();
    }

    public function test_the_page_names_only_workspaces_that_share_a_project(): void
    {
        $this->actingAs($this->herilaOwner)->get("/workspaces/{$this->herila->public_id}/reviewers")
            ->assertInertia(fn (Assert $page) => $page->where('workspaces', []));

        $this->share($this->herila, $this->herilaOwner, $this->otherEng, $this->otherOwner);

        $this->actingAs($this->herilaOwner)->get("/workspaces/{$this->herila->public_id}/reviewers")
            ->assertInertia(fn (Assert $page) => $page->where('workspaces', [['id' => $this->otherEng->public_id, 'name' => 'Synthetic Other Eng WS']]));
        // Seen from the other side of the share too.
        $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/reviewers")
            ->assertInertia(fn (Assert $page) => $page->where('workspaces', [['id' => $this->herila->public_id, 'name' => 'Synthetic Herila WS']]));
    }

    // --- giving --------------------------------------------------------

    public function test_giving_a_contact_grant_writes_the_grant_and_the_audit_row(): void
    {
        $contact = $this->contactOf($this->herilaVet, '  Ben@Vet.example.test ');

        $response = $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'project_id' => null, 'kind' => 'contact', 'contact_id' => $contact->public_id])
            ->assertCreated();

        $grant = ReviewerGrant::query()->where('public_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame($this->herila->id, $grant->granter_workspace_id);
        $this->assertSame($this->herilaVet->id, $grant->client_company_id);
        $this->assertNull($grant->client_project_id);
        $this->assertSame('ben@vet.example.test', $grant->reviewer_email);
        $this->assertSame('Ben Synthetic', $grant->reviewer_label);
        $this->assertSame($this->herilaOwner->id, $grant->granted_by_user_id);
        $this->assertSame(1, $grant->active_marker);

        $event = AccessAuditEvent::query()->where('action', 'reviewer_grant.granted')->firstOrFail();
        $this->assertSame($this->herila->id, $event->workspace_id);
        $this->assertSame($this->herilaOwner->id, $event->actor_user_id);
        $this->assertSame($grant->public_id, $event->after_state['grant']);
        $this->assertSame($this->herilaVet->public_id, $event->client_company_public_id);
    }

    public function test_a_project_grant_names_one_project_of_that_client(): void
    {
        $contact = $this->contactOf($this->herilaVet, 'ben@vet.example.test');

        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'project_id' => $this->herilaProject->public_id, 'kind' => 'contact', 'contact_id' => $contact->public_id])->assertCreated();

        $this->assertSame($this->herilaProject->id, ReviewerGrant::query()->firstOrFail()->client_project_id);
    }

    public function test_a_workspace_grant_needs_a_shared_project_and_reaches_through_it(): void
    {
        $body = ['client_id' => $this->herilaVet->public_id, 'kind' => 'workspace', 'workspace_id' => $this->otherEng->public_id];

        $this->give($this->herilaOwner, $this->herila, $body)->assertUnprocessable()->assertJsonValidationErrors('workspace_id');
        $this->assertSame(0, ReviewerGrant::query()->count());

        $this->share($this->herila, $this->herilaOwner, $this->otherEng, $this->otherOwner);
        $this->give($this->herilaOwner, $this->herila, $body)->assertCreated();

        $grant = ReviewerGrant::query()->firstOrFail();
        $this->assertSame($this->otherEng->id, $grant->reviewer_workspace_id);
        $this->assertNull($grant->reviewer_email);
    }

    public function test_a_workspace_that_does_not_exist_is_refused_like_one_that_shares_nothing(): void
    {
        $missing = $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'workspace', 'workspace_id' => '00000000-0000-4000-8000-000000000000']);
        $shares = $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'workspace', 'workspace_id' => $this->otherEng->public_id]);

        $this->assertSame($shares->json('errors.workspace_id'), $missing->json('errors.workspace_id'));
    }

    public function test_a_workspace_cannot_be_granted_its_own_hours_to_review(): void
    {
        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'workspace', 'workspace_id' => $this->herila->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('workspace_id');
    }

    public function test_the_providers_own_staff_cannot_be_named(): void
    {
        $contact = $this->contactOf($this->herilaVet, strtoupper($this->herilaWorker->email));

        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact', 'contact_id' => $contact->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->assertSame(0, ReviewerGrant::query()->count());
    }

    public function test_someone_with_portal_access_to_the_client_cannot_be_named(): void
    {
        $contact = $this->contactOf($this->herilaVet, $this->ben->email);
        ClientCompanyMembership::query()->create(['client_company_id' => $this->herilaVet->id, 'user_id' => $this->ben->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact', 'contact_id' => $contact->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_id');
    }

    public function test_a_contact_with_no_address_or_who_is_archived_cannot_be_named(): void
    {
        $blank = $this->contactOf($this->herilaVet, '');
        $archived = $this->contactOf($this->herilaVet, 'old@vet.example.test');
        $archived->forceFill(['is_archived' => true])->save();

        foreach ([$blank, $archived] as $contact) {
            $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact', 'contact_id' => $contact->public_id])
                ->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        }
    }

    public function test_the_same_grant_cannot_be_given_twice_but_can_after_it_is_taken_back(): void
    {
        $contact = $this->contactOf($this->herilaVet, 'ben@vet.example.test');
        $body = ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact', 'contact_id' => $contact->public_id];

        $first = $this->give($this->herilaOwner, $this->herila, $body)->assertCreated();
        $this->give($this->herilaOwner, $this->herila, $body)->assertUnprocessable();

        $this->actingAs($this->herilaOwner)->deleteJson($first->json('data.revoke_url'))->assertNoContent();
        $this->give($this->herilaOwner, $this->herila, $body)->assertCreated();

        $this->assertSame(2, ReviewerGrant::query()->count());
        $this->assertSame(1, ReviewerGrant::query()->whereNotNull('active_marker')->count());
    }

    // --- tenant isolation of the writes ---------------------------------

    public function test_another_workspaces_client_project_or_contact_does_not_resolve(): void
    {
        $theirs = $this->contactOf($this->otherVet, 'ben@vet.example.test');
        $mine = $this->contactOf($this->herilaVet, 'ben@vet.example.test');

        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->otherVet->public_id, 'kind' => 'contact', 'contact_id' => $theirs->public_id])->assertNotFound();
        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact', 'contact_id' => $theirs->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'project_id' => $this->otherProject->public_id, 'kind' => 'contact', 'contact_id' => $mine->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertSame(0, ReviewerGrant::query()->count());
    }

    public function test_only_owners_and_admins_of_the_workspace_may_give(): void
    {
        $contact = $this->contactOf($this->herilaVet, 'ben@vet.example.test');
        $body = ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact', 'contact_id' => $contact->public_id];

        $this->give($this->herilaWorker, $this->herila, $body)->assertForbidden();
        $this->give($this->otherOwner, $this->herila, $body)->assertNotFound();
        $this->give($this->ben, $this->herila, $body)->assertNotFound();
        $this->assertSame(0, ReviewerGrant::query()->count());
    }

    public function test_the_request_is_validated(): void
    {
        $this->give($this->herilaOwner, $this->herila, [])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'kind']);
        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'contact'])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'workspace'])->assertUnprocessable()->assertJsonValidationErrors('workspace_id');
        $this->give($this->herilaOwner, $this->herila, ['client_id' => $this->herilaVet->public_id, 'kind' => 'robot'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    // --- taking back ---------------------------------------------------

    public function test_taking_back_revokes_audits_and_keeps_the_row(): void
    {
        $grant = $this->giveGrant($this->herila, $this->herilaOwner, $this->herilaVet);

        $this->actingAs($this->herilaOwner)->delete("/workspaces/{$this->herila->public_id}/reviewers/{$grant->public_id}")->assertNoContent();

        $grant = $grant->fresh();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($this->herilaOwner->id, $grant->revoked_by_user_id);
        $this->assertNull($grant->active_marker);
        $event = AccessAuditEvent::query()->where('action', 'reviewer_grant.revoked')->firstOrFail();
        $this->assertSame($this->herila->id, $event->workspace_id);
        $this->assertSame($grant->public_id, $event->before_state['grant']);

        $this->actingAs($this->herilaOwner)->get("/workspaces/{$this->herila->public_id}/reviewers")
            ->assertInertia(fn (Assert $page) => $page->where('grants.0.revoke_url', null)->where('grants.0.revoked_at', fn ($value) => is_string($value)));
        $this->actingAs($this->herilaOwner)->delete("/workspaces/{$this->herila->public_id}/reviewers/{$grant->public_id}")->assertNotFound();
    }

    public function test_another_workspaces_grant_cannot_be_taken_back_through_ones_own_url(): void
    {
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->actingAs($this->herilaOwner)->delete("/workspaces/{$this->herila->public_id}/reviewers/{$grant->public_id}")->assertNotFound();
        $this->actingAs($this->herilaOwner)->delete("/workspaces/{$this->otherEng->public_id}/reviewers/{$grant->public_id}")->assertNotFound();
        $this->actingAs($this->herilaWorker)->delete("/workspaces/{$this->herila->public_id}/reviewers/{$grant->public_id}")->assertForbidden();
        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_the_schema_refuses_a_grant_naming_another_tenants_client(): void
    {
        $this->expectException(QueryException::class);

        DB::statement('PRAGMA foreign_keys = ON');
        $grant = new ReviewerGrant;
        $grant->forceFill([
            'granter_workspace_id' => $this->herila->id, 'client_company_id' => $this->otherVet->id, 'project_key' => 0,
            'grantee_kind' => 'contact', 'grantee_key' => 'contact:x', 'reviewer_email' => 'x@vet.example.test',
            'granted_at' => '2026-11-06 12:00:00', 'active_marker' => 1,
        ])->save();
    }
}
