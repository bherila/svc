<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Stale, unused self-registered OAuth clients past
// bherila-auth.oauth_server.dynamic_clients.retention_days (#460). The vendor's
// prune; svc:oauth:prune-dynamic-clients is an alias of it.
Schedule::command('bherila-auth:prune-dynamic-clients')->daily()->withoutOverlapping();
// Expired Passport access tokens, refresh tokens and authorization codes (#458),
// a day past the 30-day refresh-token lifetime after their own expiry. Never
// Passport's default (revoked rows at any age, and expired rows after a week):
// - a revoked or expired access token can still be the only parent of a live
//   refresh token; deleting it leaves an orphan that revocation by person
//   reaches only through its recorded owner (none for a pre-backfill token,
//   see svc:auth:revoke-connections), and any such orphan defers every
//   dynamic-client prune above;
// - revoked rows are evidence: a revocation keeps them for the investigation.
// By the time an access token is a day past expiry plus the refresh lifetime,
// every refresh token issued with it has expired, so nothing the revocation
// fence, the owner backfill or the client prune reads is removed early. The
// fence itself lives in the cache, keyed by person and token id, not by row.
Schedule::command('passport:purge --expired --hours=744')->daily()->withoutOverlapping(30);
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
