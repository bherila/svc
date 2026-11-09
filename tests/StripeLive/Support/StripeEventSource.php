<?php

namespace Tests\StripeLive\Support;

/** Where a scenario's events are read from: Stripe's Events API in the live suite, a script in the unit tests. */
interface StripeEventSource
{
    /**
     * @param  int  $since  unix time the run started
     * @param  list<string>  $types
     * @return list<RecordedEvent> newest first, as the Events API lists them
     */
    public function list(int $since, array $types): array;
}
