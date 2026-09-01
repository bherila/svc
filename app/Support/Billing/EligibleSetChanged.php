<?php

namespace App\Support\Billing;

use RuntimeException;

/**
 * The set an operator approved is not the set about to be written.
 *
 * A confirmation prompt names a count, and the transaction that counted it has
 * already closed by the time anyone answers. So the repair re-counts under its
 * own lock and refuses if the number moved - an undated paid invoice becoming
 * partially paid while the prompt is open is enough, and writing the extra row
 * would mean acting on approval nobody gave for it.
 *
 * Carries both numbers rather than a message alone, so a caller can say what
 * changed instead of only that something did.
 */
final class EligibleSetChanged extends RuntimeException
{
    public function __construct(public readonly int $approved, public readonly int $found)
    {
        parent::__construct(
            "The repair was approved for {$approved} invoice(s) but {$found} are now eligible; "
            .'nothing was written. Re-run to see the current set.',
        );
    }
}
