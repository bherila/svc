<?php

namespace Tests\Unit\Review;

use App\Support\Review\ReviewerEligibility;
use App\Support\Review\ReviewerGrantee;
use App\Support\Review\ReviewerGrantState;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ReviewerEligibilityTest extends TestCase
{
    /** @param list<int> $managed */
    private function reaches(ReviewerGrantee $grantee, string $email = 'ben@example.test', array $managed = [], bool $staff = false, bool $portal = false, ReviewerGrantState $state = ReviewerGrantState::Active): bool
    {
        return ReviewerEligibility::reaches($state, $grantee, $email, $managed, $staff, $portal);
    }

    public function test_a_contact_grant_reaches_the_person_at_that_address(): void
    {
        $grantee = ReviewerGrantee::contact('Ben@Example.test');

        $this->assertTrue($this->reaches($grantee));
        $this->assertTrue($this->reaches($grantee, ' BEN@example.test '));
        $this->assertFalse($this->reaches($grantee, 'ben2@example.test'));
        $this->assertFalse($this->reaches($grantee, ''));
    }

    public function test_a_contact_grant_does_not_depend_on_workspaces(): void
    {
        $this->assertTrue($this->reaches(ReviewerGrantee::contact('ben@example.test'), managed: [1, 2]));
    }

    public function test_a_workspace_grant_reaches_only_owners_and_admins_of_that_workspace(): void
    {
        $grantee = ReviewerGrantee::workspace(5);

        $this->assertTrue($this->reaches($grantee, managed: [5]));
        $this->assertTrue($this->reaches($grantee, managed: [3, 5, 9]));
        $this->assertFalse($this->reaches($grantee, managed: []));
        $this->assertFalse($this->reaches($grantee, managed: [4, 6]));
    }

    public function test_a_workspace_grant_ignores_the_address(): void
    {
        $this->assertTrue($this->reaches(ReviewerGrantee::workspace(5), email: '', managed: [5]));
    }

    public function test_a_revoked_grant_reaches_nobody(): void
    {
        $this->assertFalse($this->reaches(ReviewerGrantee::contact('ben@example.test'), state: ReviewerGrantState::Revoked));
        $this->assertFalse($this->reaches(ReviewerGrantee::workspace(5), managed: [5], state: ReviewerGrantState::Revoked));
    }

    public function test_the_granting_workspaces_own_staff_are_never_reached(): void
    {
        $this->assertFalse($this->reaches(ReviewerGrantee::contact('ben@example.test'), staff: true));
        $this->assertFalse($this->reaches(ReviewerGrantee::workspace(5), managed: [5], staff: true));
    }

    public function test_someone_with_portal_access_to_the_client_is_decided_by_that_alone(): void
    {
        $this->assertFalse($this->reaches(ReviewerGrantee::contact('ben@example.test'), portal: true));
        $this->assertFalse($this->reaches(ReviewerGrantee::workspace(5), managed: [5], portal: true));
    }

    public function test_the_state_follows_the_revocation_stamp(): void
    {
        $this->assertSame(ReviewerGrantState::Active, ReviewerGrantState::of(null));
        $this->assertSame(ReviewerGrantState::Revoked, ReviewerGrantState::of(CarbonImmutable::parse('2026-11-06')));
        $this->assertTrue(ReviewerGrantState::Active->reaches());
        $this->assertFalse(ReviewerGrantState::Revoked->reaches());
    }
}
