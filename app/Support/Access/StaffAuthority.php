<?php

namespace App\Support\Access;

/**
 * Who may change whose staff and portal access - every rule in one place.
 *
 * Pure: it decides from roles already read, so the access boundary can ask it
 * twice, once as a fast refusal before any lock and again under the
 * workspace access lock against roles read there, and both answers come from
 * the same rules.
 *
 * The rules, in the order they are applied:
 *
 * 1. The actor must be the system or a workspace owner or admin.
 * 2. Nobody creates, changes or removes `owner` here, the system included.
 *    Ownership is set when a workspace is created.
 * 3. A person may not change or remove their own staff membership.
 * 4. Only an owner (or the system) may grant `admin`, change a role to or
 *    from `admin`, or remove an admin. Owners and admins may grant, change
 *    and remove `member`.
 *
 * Portal access to a client company is managed by the system and by workspace
 * owners and admins, the same people who may preview any client's portal.
 *
 * Each method answers null when the change is allowed, or the reason it is not.
 */
final class StaffAuthority
{
    public function grant(AccessMutationContext $context, ?StaffRole $actorRole, StaffRole $role): ?AccessRefusal
    {
        return $this->decide($context, $actorRole, null, [$role]);
    }

    public function change(AccessMutationContext $context, ?StaffRole $actorRole, int $subjectUserId, StaffRole $from, StaffRole $to): ?AccessRefusal
    {
        return $this->decide($context, $actorRole, $subjectUserId, [$from, $to]);
    }

    public function remove(AccessMutationContext $context, ?StaffRole $actorRole, int $subjectUserId, StaffRole $current): ?AccessRefusal
    {
        return $this->decide($context, $actorRole, $subjectUserId, [$current]);
    }

    /**
     * Whether the actor may manage access in this workspace at all: the system,
     * or an owner or admin. Portal access asks no more than this, and every
     * staff change asks it before looking at the person being changed.
     */
    public function manage(AccessMutationContext $context, ?StaffRole $actorRole): ?AccessRefusal
    {
        return $this->canManage($context, $actorRole) ? null : AccessRefusal::NotAuthorized;
    }

    /** @param list<StaffRole> $roles every role the change touches, before and after */
    private function decide(AccessMutationContext $context, ?StaffRole $actorRole, ?int $subjectUserId, array $roles): ?AccessRefusal
    {
        if (! $this->canManage($context, $actorRole)) {
            return AccessRefusal::NotAuthorized;
        }

        if (in_array(StaffRole::Owner, $roles, true)) {
            return AccessRefusal::OwnerProtected;
        }

        if ($subjectUserId !== null && $context->actorId() === $subjectUserId) {
            return AccessRefusal::SelfChange;
        }

        if (in_array(StaffRole::Admin, $roles, true) && ! $context->isSystem() && $actorRole !== StaffRole::Owner) {
            return AccessRefusal::NotAuthorized;
        }

        return null;
    }

    private function canManage(AccessMutationContext $context, ?StaffRole $actorRole): bool
    {
        return $context->isSystem() || $actorRole?->managesWorkspace() === true;
    }
}
