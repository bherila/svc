<?php

namespace App\Exceptions;

use BWH\Auth\OAuth\Invitations\ApplicationInvitationActorNotEligible;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationConflict;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationException;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationInvalid;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationMisconfigured;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationNotFound;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationRateLimited;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationUnavailable;
use RuntimeException;

/**
 * The identity provider would not issue, resend or withdraw an invitation, and
 * nothing was recorded here.
 *
 * Carries the status a caller should answer with and a fixed message for the
 * person asking. Never the address, the link or the provider's own wording:
 * the package's exceptions already leave those out, and this keeps it so.
 */
final class PortalInvitationFailed extends RuntimeException
{
    /** @param array<string, list<string>> $errors field messages, for an address the provider refused */
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly array $errors = [],
        public readonly string $reason = '',
    ) {
        parent::__construct($message);
    }

    public static function from(ApplicationInvitationException $failure): self
    {
        $reason = $failure->error->value;

        return match (true) {
            $failure instanceof ApplicationInvitationMisconfigured => new self('Invitations are unavailable right now. An operator needs to check the identity provider settings.', 503, null, [], $reason),
            $failure instanceof ApplicationInvitationActorNotEligible => new self('Your account cannot send invitations at the identity provider.', 403, null, [], $reason),
            $failure instanceof ApplicationInvitationInvalid => new self('The identity provider refused that address.', 422, null, $failure->errors === [] ? ['email' => ['The identity provider refused that address.']] : $failure->errors, $reason),
            $failure instanceof ApplicationInvitationRateLimited => new self('Too many invitations have been sent. Try again later.', 429, $failure->retryAfter, [], $reason),
            $failure instanceof ApplicationInvitationConflict => new self('Another invitation to this address is being sent. Try again in a moment.', 409, $failure->retryAfter, [], $reason),
            $failure instanceof ApplicationInvitationNotFound => new self('The identity provider no longer has this invitation.', 409, null, [], $reason),
            $failure instanceof ApplicationInvitationUnavailable => new self('The identity provider is unavailable. Try again later.', 503, $failure->retryAfter ?? 30, [], $reason),
            default => new self('The identity provider is unavailable. Try again later.', 503, 30, [], $reason),
        };
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return array_filter(['Retry-After' => $this->retryAfter === null ? null : (string) $this->retryAfter, 'Cache-Control' => 'private, no-store']);
    }
}
