<?php

namespace App\Support\Access;

use App\Models\User;
use InvalidArgumentException;

/**
 * Who is making an access change, and how to correlate it afterwards.
 *
 * The actor is a person or the system. The system actor is an operator tool -
 * a console command, a migration - that is trusted by construction and has no
 * staff membership to re-check; it is still bound by the rules every actor
 * is, such as never touching workspace ownership.
 *
 * The two ids are deliberately different things and are never merged:
 *
 * - `operationId` names the logical mutation. A caller that retries the same
 *   change - after a timeout, say - sends the same operation id again, so the
 *   audit trail can tell one intended change from two.
 * - `requestId` names the one request or invocation that carried it, and is
 *   different on every retry.
 *
 * Both end up on every audit row the change writes, and neither may carry
 * anything but an opaque identifier.
 */
final readonly class AccessMutationContext
{
    private function __construct(
        public ?User $actor,
        public ?string $operationId,
        public ?string $requestId,
    ) {
        if ($operationId !== null && preg_match('/^[A-Za-z0-9._:-]{32,64}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('An operation id must be 32 to 64 letters, digits, dots, colons, underscores or hyphens.');
        }

        if ($requestId !== null && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $requestId) !== 1) {
            throw new InvalidArgumentException('A request id must be 8 to 128 letters, digits, underscores or hyphens.');
        }
    }

    public static function user(User $actor, ?string $operationId = null, ?string $requestId = null): self
    {
        return new self($actor, $operationId, $requestId);
    }

    public static function system(?string $operationId = null, ?string $requestId = null): self
    {
        return new self(null, $operationId, $requestId);
    }

    public function isSystem(): bool
    {
        return $this->actor === null;
    }

    /** The acting person's id, or null for the system. */
    public function actorId(): ?int
    {
        return $this->actor?->id;
    }
}
