<?php

declare(strict_types=1);

namespace App\Support\Approval;

/**
 * A decision to set a portal contact's approval grant, as opposed to leaving
 * it alone: `null` here is a decision (back to the default), where an absent
 * change is not.
 */
final readonly class PortalApprovalChange
{
    public function __construct(public ?bool $grant) {}
}
