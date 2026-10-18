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
    public int $ownerId;

    public string $ownerPublicId;

    private function __construct(
        User|AgentPrincipal $owner,
        public AgentCredentialScope $scope,
        public ?string $tokenId = null,
        public ?string $label = null,
        /** The exact stored token name a {@see named()} selection matches. */
        public ?string $storedName = null,
        public ?string $clientId = null,
        public ?int $atMost = null,
    ) {
        /**
         * @infection-ignore-all Both casts are equivalent mutants: this file is not strict, so the
         * typed properties coerce the key and public id identically without them, and PHPStan
         * requires them because getKey() and the attribute are untyped.
         */
        [$this->ownerId, $this->ownerPublicId] = [(int) $owner->getKey(), (string) $owner->public_id];
    }

    /** One unrevoked access token of this owner, by id, and its refresh tokens. */
    public static function token(User|AgentPrincipal $owner, string $tokenId): self
    {
        return new self($owner, AgentCredentialScope::Token, tokenId: $tokenId);
    }

    /**
     * The owner's live tokens one kind issued under this exact label. Unless
     * `$all`, more than one is refused rather than guessed between.
     */
    public static function named(User|AgentPrincipal $owner, AgentTokenKind $kind, string $label, bool $all): self
    {
        return new self($owner, AgentCredentialScope::Name, label: $label, storedName: $kind->namePrefix().$label, atMost: $all ? null : 1);
    }

    /**
     * Every agent credential the owner holds - each access token, each refresh
     * token (including one whose access token was purged) and each pending
     * authorization code - or only those issued to one OAuth client.
     */
    public static function connections(User|AgentPrincipal $owner, ?string $clientId = null): self
    {
        return new self($owner, AgentCredentialScope::Connections, clientId: $clientId);
    }
}
