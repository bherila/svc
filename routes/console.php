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
