<?php

namespace Tests\StripeLive\Support;

/** What the safety gate decided about the configured Stripe key. */
enum StripeKeyVerdict
{
    /** No key is configured: the live suite skips, so forks and local runs pass. */
    case Absent;

    /** A test-mode secret or restricted key: the suite may run. */
    case TestMode;

    /** A key of any other shape (live, publishable, garbage): the suite refuses to run. */
    case Refused;
}
