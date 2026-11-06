<?php

namespace Tests\Unit\Review;

use App\Support\Review\GrantRefusal;
use App\Support\Review\ReviewerGrantee;
use App\Support\Review\ReviewerGrantRules;
use App\Support\Review\ReviewerGrantScope;
use PHPUnit\Framework\TestCase;

final class ReviewerGrantRulesTest extends TestCase
{
    public function test_a_workspace_that_shares_a_project_may_be_granted(): void
    {
        $this->assertNull(ReviewerGrantRules::refusal(ReviewerGrantee::workspace(2), 1, false, true, false));
    }

    public function test_a_workspace_cannot_review_itself(): void
    {
        $this->assertSame(GrantRefusal::OwnWorkspace, ReviewerGrantRules::refusal(ReviewerGrantee::workspace(1), 1, false, true, false));
        $this->assertNull(ReviewerGrantRules::refusal(ReviewerGrantee::workspace(2), 1, false, true, false));
    }

    public function test_a_workspace_that_shares_nothing_is_refused(): void
    {
        $this->assertSame(GrantRefusal::NoSharedProject, ReviewerGrantRules::refusal(ReviewerGrantee::workspace(2), 1, false, false, false));
    }

    public function test_the_own_workspace_refusal_outranks_the_sharing_one(): void
    {
        $this->assertSame(GrantRefusal::OwnWorkspace, ReviewerGrantRules::refusal(ReviewerGrantee::workspace(1), 1, false, false, false));
    }

    public function test_workspace_grants_ignore_the_contact_facts(): void
    {
        $this->assertNull(ReviewerGrantRules::refusal(ReviewerGrantee::workspace(2), 1, true, true, true));
    }

    public function test_a_contact_outside_the_team_and_the_portal_may_be_granted(): void
    {
        $this->assertNull(ReviewerGrantRules::refusal(ReviewerGrantee::contact('ben@example.test'), 1, false, false, false));
    }

    public function test_a_contact_on_the_team_is_refused(): void
    {
        $this->assertSame(GrantRefusal::OwnStaff, ReviewerGrantRules::refusal(ReviewerGrantee::contact('ben@example.test'), 1, true, false, false));
        $this->assertSame(GrantRefusal::OwnStaff, ReviewerGrantRules::refusal(ReviewerGrantee::contact('ben@example.test'), 1, true, false, true));
    }

    public function test_a_contact_with_portal_access_is_refused(): void
    {
        $this->assertSame(GrantRefusal::HasPortalAccess, ReviewerGrantRules::refusal(ReviewerGrantee::contact('ben@example.test'), 1, false, false, true));
    }

    public function test_contact_grants_ignore_whether_a_project_is_shared(): void
    {
        $this->assertNull(ReviewerGrantRules::refusal(ReviewerGrantee::contact('ben@example.test'), 1, false, true, false));
    }

    public function test_every_refusal_has_a_distinct_sentence(): void
    {
        $messages = array_map(fn (GrantRefusal $refusal): string => $refusal->message(), GrantRefusal::cases());

        $this->assertCount(count(GrantRefusal::cases()), array_unique($messages));
        foreach ($messages as $message) {
            $this->assertStringEndsWith('.', $message);
        }
    }

    public function test_a_projectless_grant_reaches_the_whole_client_and_a_project_grant_only_that_project(): void
    {
        $this->assertNull(ReviewerGrantScope::projectIds(null));
        $this->assertSame([9], ReviewerGrantScope::projectIds(9));
        $this->assertSame(0, ReviewerGrantScope::projectKey(null));
        $this->assertSame(9, ReviewerGrantScope::projectKey(9));
    }
}
