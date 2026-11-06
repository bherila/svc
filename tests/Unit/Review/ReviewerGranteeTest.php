<?php

namespace Tests\Unit\Review;

use App\Support\Review\GranteeKind;
use App\Support\Review\ReviewerGrantee;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReviewerGranteeTest extends TestCase
{
    public function test_a_contact_is_an_address_trimmed_and_lower_cased(): void
    {
        $grantee = ReviewerGrantee::contact("  Ap@Example.test \n");

        $this->assertSame(GranteeKind::Contact, $grantee->kind);
        $this->assertSame('ap@example.test', $grantee->email);
        $this->assertNull($grantee->workspaceId);
    }

    public function test_a_contact_needs_an_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewerGrantee::contact("   \t");
    }

    public function test_a_workspace_is_its_id_and_the_smallest_real_id_is_one(): void
    {
        $grantee = ReviewerGrantee::workspace(1);

        $this->assertSame(GranteeKind::Workspace, $grantee->kind);
        $this->assertSame(1, $grantee->workspaceId);
        $this->assertNull($grantee->email);
    }

    public function test_workspace_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewerGrantee::workspace(0);
    }

    public function test_stored_columns_rebuild_exactly_one_kind(): void
    {
        $this->assertSame(GranteeKind::Contact, ReviewerGrantee::from('a@example.test', null)->kind);
        $this->assertSame(GranteeKind::Workspace, ReviewerGrantee::from(null, 7)->kind);
    }

    public function test_both_or_neither_is_refused(): void
    {
        foreach ([['a@example.test', 7], [null, null]] as [$email, $workspace]) {
            try {
                ReviewerGrantee::from($email, $workspace);
                $this->fail('A grant that names both or neither must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_key_is_the_same_for_the_same_grantee_however_it_was_typed(): void
    {
        $this->assertSame(ReviewerGrantee::contact('Ap@Example.test')->key(), ReviewerGrantee::contact(' ap@example.test ')->key());
        $this->assertNotSame(ReviewerGrantee::contact('a@example.test')->key(), ReviewerGrantee::contact('b@example.test')->key());
        $this->assertSame('workspace:12', ReviewerGrantee::workspace(12)->key());
        $this->assertNotSame(ReviewerGrantee::workspace(12)->key(), ReviewerGrantee::workspace(13)->key());
    }

    public function test_a_key_always_fits_a_unique_index(): void
    {
        $this->assertLessThanOrEqual(64, strlen(ReviewerGrantee::contact(str_repeat('x', 240).'@example.test')->key()));
        $this->assertSame('contact:'.sha1('a@example.test'), ReviewerGrantee::contact('a@example.test')->key());
    }
}
