<?php

namespace App\Listeners;

use App\Auth\ProviderEmailVerification;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Hands every HTTP client response to {@see ProviderEmailVerification}, which keeps only the
 * sign-in provider's identity response, and only while a sign-in callback is being handled.
 */
final class RecordProviderEmailVerification
{
    public function __construct(private readonly ProviderEmailVerification $verification) {}

    public function handle(ResponseReceived $event): void
    {
        $this->verification->observe($event->request, $event->response);
    }
}
