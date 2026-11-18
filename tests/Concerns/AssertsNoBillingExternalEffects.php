<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/** Rollback protects SQL only; the billing ratchet also depends on no external effects. */
trait AssertsNoBillingExternalEffects
{
    private function recordBillingExternalEffects(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Queue::fake();
    }

    private function assertNoBillingExternalEffects(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
        Queue::assertNothingPushed();
    }
}
