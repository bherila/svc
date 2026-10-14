<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('svc:oauth:prune-dynamic-clients')->daily()->withoutOverlapping();
// Expired delegated access assertion nonces and operation receipts past their 30 days.
Schedule::command('bherila-auth:prune-delegated-nonces')->daily()->withoutOverlapping();
Schedule::command('svc:billing:dispatch-invoice-emails --limit=50')->everyMinute()->withoutOverlapping();
// Payment reminder cadences (#438), off until enabled per client. Each step is
// claimed once per invoice, so an overlapping or repeated run sends nothing twice.
Schedule::command('svc:billing:send-payment-reminders --limit=50')->everyFifteenMinutes()->withoutOverlapping(30);
// The read-only Square mirror (#438): no more often than every 15 minutes.
// The lock expires well before a day so a crashed run cannot stall the next.
Schedule::command('svc:square:sync')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('svc:square:reconcile-payments')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('svc:square:prune-webhook-receipts')->daily()->withoutOverlapping(30);
