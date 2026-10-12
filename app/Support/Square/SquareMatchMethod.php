<?php

namespace App\Support\Square;

/** How an operator arrived at a Square link. Nothing is ever linked automatically. */
enum SquareMatchMethod: string
{
    /** Found and confirmed by hand. */
    case Manual = 'manual';

    /** Offered by candidate ranking and confirmed by a person; score and reasons are what they saw. */
    case Suggested = 'suggested';
}
