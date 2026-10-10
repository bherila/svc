<?php

namespace Tests\Unit\Access;

use App\Models\User;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\StaffAuthority;
use App\Support\Access\StaffRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The authority matrix, decided from roles alone.
 *
 * Every rule an access mutation applies is here, so the whole matrix is
 * checked without a database: actor role (or the system, or a stranger)
 * against every target role, for grant, change and remove.
 */
final class StaffAuthorityTest extends TestCase
{
    private const ACTOR = 10;

    private const SUBJECT = 20;

    /** @return iterable<string, array{?StaffRole, StaffRole, ?AccessRefusal}> */
    public static function grants(): iterable
    {
        yield 'owner grants member' => [StaffRole::Owner, StaffRole::Member, null];
        yield 'owner grants admin' => [StaffRole::Owner, StaffRole::Admin, null];
        yield 'admin grants member' => [StaffRole::Admin, StaffRole::Member, null];
        yield 'admin cannot grant admin' => [StaffRole::Admin, StaffRole::Admin, AccessRefusal::NotAuthorized];
        yield 'member cannot grant member' => [StaffRole::Member, StaffRole::Member, AccessRefusal::NotAuthorized];
        yield 'member cannot grant admin' => [StaffRole::Member, StaffRole::Admin, AccessRefusal::NotAuthorized];
        yield 'stranger cannot grant member' => [null, StaffRole::Member, AccessRefusal::NotAuthorized];
        yield 'owner cannot grant owner' => [StaffRole::Owner, StaffRole::Owner, AccessRefusal::OwnerProtected];
        yield 'admin cannot grant owner' => [StaffRole::Admin, StaffRole::Owner, AccessRefusal::OwnerProtected];
        yield 'member asking for owner is refused for authority first' => [StaffRole::Member, StaffRole::Owner, AccessRefusal::NotAuthorized];
    }

    #[DataProvider('grants')]
    public function test_grant(?StaffRole $actor, StaffRole $role, ?AccessRefusal $expected): void
    {
        $this->assertSame($expected, (new StaffAuthority)->grant($this->as(self::ACTOR), $actor, $role));
    }

    /** @return iterable<string, array{?StaffRole, StaffRole, StaffRole, ?AccessRefusal}> */
    public static function changes(): iterable
    {
        yield 'owner member to admin' => [StaffRole::Owner, StaffRole::Member, StaffRole::Admin, null];
        yield 'owner admin to member' => [StaffRole::Owner, StaffRole::Admin, StaffRole::Member, null];
        yield 'admin member to member' => [StaffRole::Admin, StaffRole::Member, StaffRole::Member, null];
        yield 'admin cannot promote to admin' => [StaffRole::Admin, StaffRole::Member, StaffRole::Admin, AccessRefusal::NotAuthorized];
        yield 'admin cannot demote an admin' => [StaffRole::Admin, StaffRole::Admin, StaffRole::Member, AccessRefusal::NotAuthorized];
        yield 'member cannot change anyone' => [StaffRole::Member, StaffRole::Member, StaffRole::Member, AccessRefusal::NotAuthorized];
        yield 'stranger cannot change anyone' => [null, StaffRole::Member, StaffRole::Admin, AccessRefusal::NotAuthorized];
        yield 'owner cannot demote an owner' => [StaffRole::Owner, StaffRole::Owner, StaffRole::Admin, AccessRefusal::OwnerProtected];
        yield 'owner cannot promote to owner' => [StaffRole::Owner, StaffRole::Admin, StaffRole::Owner, AccessRefusal::OwnerProtected];
    }

    #[DataProvider('changes')]
    public function test_change(?StaffRole $actor, StaffRole $from, StaffRole $to, ?AccessRefusal $expected): void
    {
        $this->assertSame($expected, (new StaffAuthority)->change($this->as(self::ACTOR), $actor, self::SUBJECT, $from, $to));
    }

    /** @return iterable<string, array{?StaffRole, StaffRole, ?AccessRefusal}> */
    public static function removals(): iterable
    {
        yield 'owner removes member' => [StaffRole::Owner, StaffRole::Member, null];
        yield 'owner removes admin' => [StaffRole::Owner, StaffRole::Admin, null];
        yield 'admin removes member' => [StaffRole::Admin, StaffRole::Member, null];
        yield 'admin cannot remove admin' => [StaffRole::Admin, StaffRole::Admin, AccessRefusal::NotAuthorized];
        yield 'member cannot remove member' => [StaffRole::Member, StaffRole::Member, AccessRefusal::NotAuthorized];
        yield 'stranger cannot remove member' => [null, StaffRole::Member, AccessRefusal::NotAuthorized];
        yield 'owner cannot remove owner' => [StaffRole::Owner, StaffRole::Owner, AccessRefusal::OwnerProtected];
    }

    #[DataProvider('removals')]
    public function test_remove(?StaffRole $actor, StaffRole $current, ?AccessRefusal $expected): void
    {
        $this->assertSame($expected, (new StaffAuthority)->remove($this->as(self::ACTOR), $actor, self::SUBJECT, $current));
    }

    public function test_an_actor_cannot_change_or_remove_themselves(): void
    {
        $authority = new StaffAuthority;
        $self = $this->as(self::SUBJECT);

        $this->assertSame(AccessRefusal::SelfChange, $authority->change($self, StaffRole::Owner, self::SUBJECT, StaffRole::Member, StaffRole::Admin));
        $this->assertSame(AccessRefusal::SelfChange, $authority->change($self, StaffRole::Admin, self::SUBJECT, StaffRole::Member, StaffRole::Member));
        $this->assertSame(AccessRefusal::SelfChange, $authority->remove($self, StaffRole::Owner, self::SUBJECT, StaffRole::Admin));
        $this->assertSame(AccessRefusal::SelfChange, $authority->remove($self, StaffRole::Admin, self::SUBJECT, StaffRole::Member));
        // Ownership protection is reported ahead of the self rule.
        $this->assertSame(AccessRefusal::OwnerProtected, $authority->remove($self, StaffRole::Owner, self::SUBJECT, StaffRole::Owner));
    }

    public function test_the_system_manages_every_role_but_owner(): void
    {
        $authority = new StaffAuthority;
        $system = AccessMutationContext::system();

        $this->assertNull($authority->grant($system, null, StaffRole::Admin));
        $this->assertNull($authority->grant($system, null, StaffRole::Member));
        $this->assertNull($authority->change($system, null, self::SUBJECT, StaffRole::Admin, StaffRole::Member));
        $this->assertNull($authority->change($system, null, self::SUBJECT, StaffRole::Member, StaffRole::Admin));
        $this->assertNull($authority->remove($system, null, self::SUBJECT, StaffRole::Admin));
        $this->assertSame(AccessRefusal::OwnerProtected, $authority->grant($system, null, StaffRole::Owner));
        $this->assertSame(AccessRefusal::OwnerProtected, $authority->change($system, null, self::SUBJECT, StaffRole::Owner, StaffRole::Admin));
        $this->assertSame(AccessRefusal::OwnerProtected, $authority->remove($system, null, self::SUBJECT, StaffRole::Owner));
    }

    public function test_portal_access_is_managed_by_the_system_owners_and_admins(): void
    {
        $authority = new StaffAuthority;
        $actor = $this->as(self::ACTOR);

        $this->assertNull($authority->manage(AccessMutationContext::system(), null));
        $this->assertNull($authority->manage($actor, StaffRole::Owner));
        $this->assertNull($authority->manage($actor, StaffRole::Admin));
        $this->assertSame(AccessRefusal::NotAuthorized, $authority->manage($actor, StaffRole::Member));
        $this->assertSame(AccessRefusal::NotAuthorized, $authority->manage($actor, null));
    }

    public function test_only_owners_and_admins_manage_the_workspace(): void
    {
        $this->assertTrue(StaffRole::Owner->managesWorkspace());
        $this->assertTrue(StaffRole::Admin->managesWorkspace());
        $this->assertFalse(StaffRole::Member->managesWorkspace());
    }

    private function as(int $userId): AccessMutationContext
    {
        $user = new User;
        $user->id = $userId;

        return AccessMutationContext::user($user);
    }
}
