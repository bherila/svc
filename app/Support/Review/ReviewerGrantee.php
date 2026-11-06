<?php

declare(strict_types=1);

namespace App\Support\Review;

use App\Support\SharedProjects\InviteeAddress;
use InvalidArgumentException;

/**
 * The person or workspace a grant is written to.
 *
 * Exactly one of the two. A contact is an address, normalised the way every
 * address lookup here is, so a grant written to "Ap@Example.test " reaches the
 * person who signs in as "ap@example.test". A workspace is its id; the people
 * it reaches are decided live (see {@see ReviewerEligibility}).
 */
final readonly class ReviewerGrantee
{
    private function __construct(
        public GranteeKind $kind,
        public ?string $email,
        public ?int $workspaceId,
    ) {}

    public static function contact(string $email): self
    {
        $address = InviteeAddress::normalize($email);
        if ($address === '') {
            throw new InvalidArgumentException('A contact grantee needs an address.');
        }

        return new self(GranteeKind::Contact, $address, null);
    }

    public static function workspace(int $workspaceId): self
    {
        if ($workspaceId < 1) {
            throw new InvalidArgumentException('A workspace grantee needs a workspace.');
        }

        return new self(GranteeKind::Workspace, null, $workspaceId);
    }

    /** Rebuild a grantee from stored columns; one of the two, never both and never neither. */
    public static function from(?string $email, ?int $workspaceId): self
    {
        if ($email !== null && $workspaceId === null) {
            return self::contact($email);
        }
        if ($email === null && $workspaceId !== null) {
            return self::workspace($workspaceId);
        }

        throw new InvalidArgumentException('A grant names a contact or a workspace, and only one.');
    }

    /**
     * What makes two grants to the same grantee the same grant: at most 64
     * characters, so it can sit in a unique index.
     */
    public function key(): string
    {
        return match ($this->kind) {
            GranteeKind::Contact => 'contact:'.sha1((string) $this->email),
            GranteeKind::Workspace => 'workspace:'.$this->workspaceId,
        };
    }
}
