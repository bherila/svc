<?php

namespace App\Support\Packages;

/** What the lapse command did with one open enrollment (ACC-6, EDGE-1). */
enum LapseOutcome: string
{
    /** Ended: status `lapsed`, any unpaid sign-up invoice voided, the agreement left a draft. */
    case Lapsed = 'lapsed';

    /** Its sign-up invoice turned out paid, so it moved on instead of lapsing. */
    case Continued = 'continued';

    /** Money is received or still in flight on its sign-up invoice, so it is not ended; a later run decides. */
    case Held = 'held';

    /** Not yet at its lapse date, or no longer open. */
    case NotDue = 'not_due';
}
