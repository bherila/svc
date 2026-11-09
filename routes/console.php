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
Schedule::command('svc:billing:dispatch-invoice-emails --apply --limit=50')->everyMinute()->withoutOverlapping();
// Payment reminder cadences (#438), off until enabled per client. Each step is
// claimed once per invoice, so an overlapping or repeated run sends nothing twice.
Schedule::command('svc:billing:send-payment-reminders --apply --limit=50')->everyFifteenMinutes()->withoutOverlapping(30);
// Autopay (#636): one attempt per invoice, claimed before Stripe is called, so an
// overlapping or repeated run charges nothing twice. Runs in the scheduler's own
// process; there is no queue worker to hand it to.
Schedule::command('svc:billing:charge-autopay --apply --limit=50')->everyFifteenMinutes()->withoutOverlapping(30);
// Cadence-period drafts for recurring agreements whose current period has
// started with no invoice, withheld until svc.billing.scheduled_cadence_drafts
// is on. Hourly, so the first of the month is reached in every workspace's own
// time zone; drafts only - issuing and sending stay an operator's.
Schedule::command('svc:billing:generate-cadence-drafts --apply --limit=50')->hourly()->when(static fn (): bool => (bool) config('svc.billing.scheduled_cadence_drafts'))->withoutOverlapping(30);
// Service package sign-ups left unaccepted or unpaid past their lapse date
// (ACC-6): each is lapsed under its own lock, its unpaid sign-up invoice voided
// and its draft agreement left a draft, so a repeated run changes nothing.
// Hourly, so a lapse date is reached in every workspace's own time zone; only
// while the feature is on (X-1). In process; no queue worker.
Schedule::command('svc:packages:lapse-enrollments --apply --page=200')->hourly()->when(static fn (): bool => (bool) config('svc.service_packages.enabled'))->withoutOverlapping(30);
// Is each workspace's Stripe webhook endpoint subscribed to every event SVC needs?
// One read of Stripe per connection, recorded for the Payments page; it changes
// nothing at Stripe (the fix is a button an owner or admin presses). No queue.
Schedule::command('svc:payments:check-stripe-webhooks --apply')->dailyAt('03:35')->withoutOverlapping(30);
// The read-only Square mirror (#438): no more often than every 15 minutes.
// The lock expires well before a day so a crashed run cannot stall the next.
Schedule::command('svc:square:sync --apply')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('svc:square:reconcile-payments --apply')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('svc:square:prune-webhook-receipts --apply')->daily()->withoutOverlapping(30);

// Non-invoice Square charges need not wait forever for a local payment (#495).
Schedule::command('svc:square:prune-pending-settlements --apply')->daily()->withoutOverlapping(30);

// Independent provider runs avoid starving either lane under the shared
// command budget. No queue worker is required; both run synchronously.
// Withheld until svc.processor_movements.scheduled_apply is turned on.
$processorMovementsScheduled = static fn (): bool => (bool) config('svc.processor_movements.scheduled_apply');
Schedule::command('svc:finance:sync-processor-movements --provider=stripe --apply')->dailyAt('02:10')->when($processorMovementsScheduled)->withoutOverlapping(30);
Schedule::command('svc:finance:sync-processor-movements --provider=square --apply')->dailyAt('02:25')->when($processorMovementsScheduled)->withoutOverlapping(30);
