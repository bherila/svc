<?php

namespace App\Services\Credentials;

use App\Models\AgentPrincipal;
use App\Models\User;

/**
 * Which of one person's agent (Passport) credentials a revocation ends.
 *
 * Always one owner: no selection reaches another person's credentials, and
 * the owner is fixed before anything is read, so isolation does not depend on
 * the caller filtering correctly.
 */
final readonly class AgentCredentialSelection
{
    private function __construct(
        public int $ownerId,
        public string $ownerPublicId,
        public AgentCredentialScope $scope,
        public ?string $tokenId = null,
        public ?AgentTokenKind $kind = null,
        public ?string $label = null,
        public ?string $clientId = null,
        public ?int $atMost = null,
    ) {}

    /** One unrevoked access token of this owner, by id, and its refresh tokens. */
    public static function token(User|AgentPrincipal $owner, string $tokenId): self
    {
        return new self((int) $owner->getKey(), (string) $owner->public_id, AgentCredentialScope::Token, tokenId: $tokenId);
    }

    /**
     * The owner's live tokens one kind issued under this exact label. Unless
     * `$all`, more than one is refused rather than guessed between.
     */
    public static function named(User|AgentPrincipal $owner, AgentTokenKind $kind, string $label, bool $all): self
    {
        return new self((int) $owner->getKey(), (string) $owner->public_id, AgentCredentialScope::Name, kind: $kind, label: $label, atMost: $all ? null : 1);
    }

    /**
     * Every agent credential the owner holds - each access token, each refresh
     * token (including one whose access token was purged) and each pending
     * authorization code - or only those issued to one OAuth client.
     */
    public static function connections(User|AgentPrincipal $owner, ?string $clientId = null): self
    {
        return new self((int) $owner->getKey(), (string) $owner->public_id, AgentCredentialScope::Connections, clientId: $clientId);
    }

    /** The stored token name a {@see named()} selection matches exactly. */
    public function storedName(): ?string
    {
        return $this->kind === null || $this->label === null ? null : $this->kind->namePrefix().$this->label;
    }
}
