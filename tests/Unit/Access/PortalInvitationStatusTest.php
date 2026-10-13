<?php

namespace Tests\Unit\Access;

use App\Support\Access\PortalInvitationNotApplied;
use App\Support\Access\PortalInvitationStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PortalInvitationStatusTest extends TestCase
{
    public function test_a_pending_invitation_reads_as_expired_from_its_expiry_on(): void
    {
        $expiry = new DateTimeImmutable('2026-10-19T12:00:00Z');

        $this->assertSame(PortalInvitationStatus::Pending, PortalInvitationStatus::of('pending', $expiry, new DateTimeImmutable('2026-10-19T11:59:59Z')));
        $this->assertSame(PortalInvitationStatus::Expired, PortalInvitationStatus::of('pending', $expiry, $expiry));
        $this->assertSame(PortalInvitationStatus::Pending, PortalInvitationStatus::of('pending', null, $expiry));
        foreach (['accepted', 'not_applied', 'revoked', 'expired'] as $stored) {
            $this->assertSame($stored, PortalInvitationStatus::of($stored, $expiry, new DateTimeImmutable('2027-01-01T00:00:00Z'))->value);
        }
    }

    public function test_only_pending_and_expired_invitations_are_open(): void
    {
        $open = array_values(array_filter(PortalInvitationStatus::cases(), static fn (PortalInvitationStatus $status): bool => $status->isOpen()));

        $this->assertSame([PortalInvitationStatus::Pending, PortalInvitationStatus::Expired], $open);
    }

    public function test_every_not_applied_reason_explains_itself_and_says_what_to_do(): void
    {
        $this->assertStringStartsWith('The person who sent this invitation can no longer manage access', PortalInvitationNotApplied::InviterLostAuthority->message());
        $this->assertStringStartsWith('None of the invited projects belong to this client any more', PortalInvitationNotApplied::ProjectsMoved->message());
        foreach (PortalInvitationNotApplied::cases() as $reason) {
            $this->assertStringContainsString('Add them again', $reason->message());
        }
    }
}
