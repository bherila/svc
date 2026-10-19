<?php

namespace App\Console\Commands\Finance;

use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\StripeGateway;
use App\Services\Billing\StripeSettlementSync;
use App\Services\Square\SquareClient;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareRequestFailed;
use App\Services\Square\SquareSettlementSync;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Finance\PayoutSyncResult;
use App\Support\Square\SquarePaymentFacts;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fill processor settlement facts for payments and payouts recorded before
 * svc stored them (S7): the payouts themselves, and on each processor payment
 * its charge time, fee, payout and arrival date.
 *
 * Reads Stripe and Square only - their read APIs, never a write - and writes
 * through {@see StripeSettlementSync} / {@see SquareSettlementSync}, the same
 * code the webhooks use. It does nothing until told: without `--apply` it
 * reports what it would change, through the same comparison, and writes
 * nothing.
 *
 * ## Idempotent and resumable
 *
 * Every write is keyed by the processor's own identifiers and only writes what
 * differs, so a second run over the same history changes nothing. A run that
 * stops - a processor outage, a rate limit - reports what it did and fails;
 * running it again picks up where it stopped: payouts already stored are
 * unchanged, and the charge pass only revisits payments still missing a charge
 * time or fee. `--since` narrows the payout pass to payouts created on or
 * after a day; Square's own default without it is the last year.
 *
 * Counts only, so the output is safe to paste into an issue.
 */
final class BackfillPayoutsCommand extends Command
{
    protected $signature = 'svc:finance:backfill-payouts
        {--provider=all : stripe, square or all}
        {--since= : Only payouts created on or after this day, YYYY-MM-DD}
        {--workspace= : Write to this workspace only (public id or slug); omit for every workspace}
        {--apply : Write; without this the command only reports what it would change}
        {--format=text : Output text or json}';

    protected $description = 'Backfill processor payouts and each payment\'s charge time, fee and payout from Stripe and Square';

    /** Payment statuses whose money the processor took, so it has a charge to read. */
    private const array CHARGED = [InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Refunded, InvoicePaymentStatus::Disputed];

    /** @var array<string, array<string, int>> provider => counts */
    private array $report = [];

    /** @var array<string, string> provider => why it was skipped or stopped */
    private array $notes = [];

    private bool $failed = false;

    public function handle(StripeGateway $gateway, StripeSettlementSync $stripe, SquareSettlementSync $square, SquareClient $squareClient, WorkspaceClock $clock): int
    {
        $format = (string) $this->option('format');
        $provider = (string) $this->option('provider');
        if (! in_array($format, ['text', 'json'], true) || ! in_array($provider, ['all', 'stripe', 'square'], true)) {
            $this->error('--format must be text or json, and --provider stripe, square or all.');

            return self::INVALID;
        }
        $since = null;
        $sinceOption = $this->option('since');
        if ($sinceOption !== null) {
            $since = CarbonImmutable::createFromFormat('!Y-m-d', (string) $sinceOption, 'UTC');
            if (! $since instanceof CarbonImmutable || $since->toDateString() !== $sinceOption) {
                $this->error('--since must be a day, YYYY-MM-DD.');

                return self::INVALID;
            }
        }
        $only = null;
        if ($this->option('workspace') !== null) {
            $named = (string) $this->option('workspace');
            $only = Workspace::query()->where('public_id', $named)->orWhere('slug', $named)->first();
            if ($only === null) {
                $this->error('No workspace matches that public id or slug.');

                return self::INVALID;
            }
        }
        $apply = (bool) $this->option('apply');
        // The console kernel can reuse this instance for another call.
        $this->report = [];
        $this->notes = [];
        $this->failed = false;

        if ($provider !== 'square') {
            $this->stripe($gateway, $stripe, $since, $only, $apply, $clock);
        }
        if ($provider !== 'stripe') {
            $this->square($square, $squareClient, $since, $only, $apply);
        }
        $this->print($format, $apply);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function stripe(StripeGateway $gateway, StripeSettlementSync $sync, ?CarbonImmutable $since, ?Workspace $only, bool $apply, WorkspaceClock $clock): void
    {
        $counts = $this->counts();
        if ($gateway->mode() === null) {
            $this->report['stripe'] = $counts;
            $this->notes['stripe'] = 'skipped: not configured';

            return;
        }
        try {
            // What Stripe says now is at least as current as any event
            // already applied, so it is stamped with the time it was read.
            foreach ($sync->payouts($since, (int) $clock->now()->utc()->getTimestampMs()) as $payout) {
                $counts['payouts_read']++;
                $this->tally($counts, $sync->apply($payout, $sync->members($payout), $apply, $only?->id));
            }
            foreach ($this->unsettled('stripe', $only)->lazyById(100) as $payment) {
                $counts['charges_read']++;
                if ($sync->recordCharge($payment, $apply)) {
                    $counts['charges_changed']++;
                }
            }
        } catch (StripeEventMustBeRetried|DomainException $failure) {
            $this->notes['stripe'] = 'stopped: '.$failure->getMessage();
            $this->failed = true;
        }
        $this->report['stripe'] = $counts;
    }

    private function square(SquareSettlementSync $sync, SquareClient $client, ?CarbonImmutable $since, ?Workspace $only, bool $apply): void
    {
        $counts = $this->counts();
        $configuration = SquareConfiguration::fromConfig();
        $workspace = $configuration->isConfigured() ? Workspace::query()->where('public_id', $configuration->workspacePublicId)->first() : null;
        if ($workspace === null || ($only !== null && $only->id !== $workspace->id)) {
            $this->report['square'] = $counts;
            $this->notes['square'] = 'skipped: '.($workspace === null ? 'not configured' : 'connected to another workspace');

            return;
        }
        try {
            foreach ($sync->payouts($workspace, $since, $apply) as $result) {
                $counts['payouts_read']++;
                $this->tally($counts, $result);
            }
            foreach ($this->unsettled('square', $workspace)->lazyById(100) as $payment) {
                $counts['charges_read']++;
                $id = (string) $payment->provider_payment_identifier;
                try {
                    $read = $client->getPayment($id)['payment'] ?? null;
                    $facts = is_array($read) ? SquarePaymentFacts::from($read, $id) : throw SquareRequestFailed::unreadable();
                } catch (SquareRequestFailed $failure) {
                    if (! $failure->isRecordSpecific()) {
                        throw $failure;
                    }
                    $counts['charges_unreadable']++;

                    continue;
                }
                if ($facts !== null && $sync->recordCharge($workspace, $facts, $apply)) {
                    $counts['charges_changed']++;
                }
            }
        } catch (SquareRequestFailed $failure) {
            $this->notes['square'] = 'stopped: '.$failure->getMessage();
            $this->failed = true;
        }
        $this->report['square'] = $counts;
    }

    /**
     * The provider's charged payments still missing a charge time or a fee:
     * those the charge pass reads. One that has both is never read again.
     *
     * @return Builder<ClientInvoicePayment>
     */
    private function unsettled(string $provider, ?Workspace $workspace): Builder
    {
        return ClientInvoicePayment::query()
            ->where('provider', $provider)
            ->whereNotNull('provider_payment_identifier')
            ->whereIn('status', array_map(static fn (InvoicePaymentStatus $status): string => $status->value, self::CHARGED))
            ->where(static fn (Builder $missing) => $missing->whereNull('provider_charged_at')->orWhereNull('provider_fee_amount'))
            ->when($workspace !== null, static fn (Builder $payments) => $payments->where('workspace_id', $workspace?->id));
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return ['payouts_read' => 0, 'payout_rows_changed' => 0, 'payments_changed' => 0, 'charges_read' => 0, 'charges_changed' => 0, 'charges_unreadable' => 0];
    }

    /** @param array<string, int> $counts */
    private function tally(array &$counts, PayoutSyncResult $result): void
    {
        $counts['payout_rows_changed'] += $result->payoutRowsChanged;
        $counts['payments_changed'] += $result->paymentsChanged;
    }

    private function print(string $format, bool $apply): void
    {
        if ($format === 'json') {
            $providers = [];
            foreach ($this->report as $provider => $counts) {
                $providers[$provider] = [...$counts, 'note' => $this->notes[$provider] ?? null];
            }
            $this->line((string) json_encode(['applied' => $apply, 'completed' => ! $this->failed, 'providers' => $providers], JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($this->report as $provider => $counts) {
            $this->components->twoColumnDetail(ucfirst($provider), $this->notes[$provider] ?? '');
            foreach ($counts as $name => $value) {
                $this->components->twoColumnDetail('  '.str_replace('_', ' ', $name), (string) $value);
            }
        }
        if ($this->failed) {
            $this->components->warn('Stopped early; run the command again to resume. Nothing already recorded is written twice.');
        }
        $this->components->info($apply ? 'Recorded the changes above.' : 'Dry run: nothing was written. Re-run with --apply to write.');
    }
}
