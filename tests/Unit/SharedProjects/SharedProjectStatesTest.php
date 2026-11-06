<?php

namespace Tests\Unit\SharedProjects;

use App\Support\Access\StaffRole;
use App\Support\SharedProjects\AssigneeEligibility;
use App\Support\SharedProjects\InvitationState;
use App\Support\SharedProjects\InviteeAddress;
use App\Support\SharedProjects\ParticipationState;
use App\Support\SharedProjects\SharedTaskScope;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SharedProjectStatesTest extends TestCase
{
    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'UTC');
    }

    public function test_an_invitation_is_pending_until_the_instant_it_expires(): void
    {
        $now = $this->at('2026-11-10 12:00:00');

        $this->assertSame(InvitationState::Pending, InvitationState::of(null, null, null, $now->addSecond(), $now));
        $this->assertSame(InvitationState::Expired, InvitationState::of(null, null, null, $now, $now), 'It lapses at the expiry instant, not after it.');
        $this->assertSame(InvitationState::Expired, InvitationState::of(null, null, null, $now->subSecond(), $now));
    }

    public function test_acceptance_outranks_everything_and_revocation_outranks_the_rest(): void
    {
        $now = $this->at('2026-11-10 12:00:00');
        $later = $now->addDay();
        $earlier = $now->subDay();

        $this->assertSame(InvitationState::Accepted, InvitationState::of($earlier, $earlier, $earlier, $earlier, $now));
        $this->assertSame(InvitationState::Revoked, InvitationState::of(null, $earlier, $earlier, $earlier, $now));
        $this->assertSame(InvitationState::Declined, InvitationState::of(null, $earlier, null, $earlier, $now));
        $this->assertSame(InvitationState::Declined, InvitationState::of(null, $earlier, null, $later, $now));
        $this->assertSame(InvitationState::Revoked, InvitationState::of(null, null, $earlier, $later, $now));
    }

    public function test_only_a_pending_invitation_is_open(): void
    {
        foreach (InvitationState::cases() as $state) {
            $this->assertSame($state === InvitationState::Pending, $state->isOpen(), $state->value);
        }
    }

    public function test_a_participation_gives_access_only_while_neither_revoked_nor_left(): void
    {
        $at = $this->at('2026-11-10 12:00:00');

        $this->assertSame(ParticipationState::Active, ParticipationState::of(null, null));
        $this->assertSame(ParticipationState::Revoked, ParticipationState::of($at, null));
        $this->assertSame(ParticipationState::Left, ParticipationState::of(null, $at));
        $this->assertSame(ParticipationState::Revoked, ParticipationState::of($at, $at), 'The host ending it outranks the participant leaving.');
        $this->assertTrue(ParticipationState::Active->grantsAccess());
        $this->assertFalse(ParticipationState::Revoked->grantsAccess());
        $this->assertFalse(ParticipationState::Left->grantsAccess());
    }

    public function test_an_address_is_compared_trimmed_and_case_insensitively(): void
    {
        $this->assertSame('ana@example.test', InviteeAddress::normalize("  Ana@Example.test \n"));
        $this->assertTrue(InviteeAddress::matches('Ana@Example.test', ' ana@EXAMPLE.test '));
        $this->assertFalse(InviteeAddress::matches('ana@example.test', 'bob@example.test'));
        $this->assertFalse(InviteeAddress::matches('ana@example.test', 'ana@other.example.test'));
        $this->assertFalse(InviteeAddress::matches('ana@example.test', null));
        $this->assertFalse(InviteeAddress::matches('ana@example.test', '   '));
        $this->assertFalse(InviteeAddress::matches('', ''));
        $this->assertFalse(InviteeAddress::matches('   ', '   '), 'A blank address matches nothing, not even another blank.');
        $this->assertSame('äna@example.test', InviteeAddress::normalize('ÄNA@Example.test'));
        $this->assertTrue(InviteeAddress::matches('ÄNA@example.test', 'äna@EXAMPLE.test'));
    }

    public function test_a_participant_workspace_offers_its_managers_and_the_members_it_put_on_the_share(): void
    {
        $this->assertTrue(AssigneeEligibility::eligible(StaffRole::Owner, false));
        $this->assertTrue(AssigneeEligibility::eligible(StaffRole::Admin, false));
        $this->assertTrue(AssigneeEligibility::eligible(StaffRole::Member, true));
        $this->assertFalse(AssigneeEligibility::eligible(StaffRole::Member, false));
        $this->assertFalse(AssigneeEligibility::eligible(null, true));
    }

    public function test_a_participant_is_never_selected_any_money_or_billing_column_of_a_task(): void
    {
        foreach (['milestone_price_amount', 'client_invoice_line_id', 'workspace_id', 'parent_task_id', 'client_task_list_id', 'organization_position', 'lock_version'] as $column) {
            $this->assertNotContains($column, SharedTaskScope::COLUMNS, $column);
        }
        foreach (['public_id', 'title', 'status', 'due_on'] as $column) {
            $this->assertContains($column, SharedTaskScope::COLUMNS, $column);
        }
    }
}
