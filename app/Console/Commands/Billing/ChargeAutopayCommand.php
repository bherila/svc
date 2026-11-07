<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\MutationCommand;
use App\Models\InvoiceAutopayAttempt;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Support\Billing\Autopay\AutopayChargeOutcome;
use Illuminate\Support\Facades\Log;

/**
 * Autopay (#636): charge each due invoice of a client that turned it on, once.
 * Runs in process from the scheduler - the deployment runs `schedule:run` and
 * no queue worker - and previews by default: without --apply it lists what it
 * would charge and calls nothing.
 */
final class ChargeAutopayCommand extends MutationCommand
{
    protected $signature = 'svc:billing:charge-autopay {--limit=50 : Maximum invoices to charge in this run}';

    protected $description = 'Charge the default saved Stripe method for each invoice autopay is due to collect';

    public function handle(AutopayChargeService $autopay): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $apply = (bool) $this->applying();

        $outcomes = $autopay->run($limit, $apply);
        $review = $autopay->needingReview();
        foreach ($review as $attempt) {
            Log::warning('An autopay attempt was claimed with no payment intent and needs review.', ['attempt' => $attempt->public_id]);
        }

        if ($this->option('format') === 'json') {
            $this->json([
                'dry_run' => ! $apply,
                'executed' => $apply,
                'outcomes' => array_map(static fn (AutopayChargeOutcome $outcome): array => $outcome->toArray(), $outcomes),
                'needs_review' => array_map(static fn (InvoiceAutopayAttempt $attempt): array => [
                    'attempt_id' => $attempt->public_id,
                    'invoice_id' => $attempt->invoice?->public_id,
                    'claimed_at' => $attempt->claimed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                ], $review),
            ]);

            return self::SUCCESS;
        }

        $this->components->info(($apply ? 'Autopay run: ' : 'Autopay preview (re-run with --apply to charge): ').count($outcomes).' invoice(s).');
        foreach ($outcomes as $outcome) {
            $this->line(sprintf('  %s  %s  %s %s  %s', $outcome->invoiceNumber, $outcome->result->value, $outcome->currency, number_format(($outcome->amount ?? 0) / 100, 2), (string) $outcome->detail));
        }
        foreach ($review as $attempt) {
            $this->components->warn('Needs review: autopay attempt '.$attempt->public_id.' for invoice '.($attempt->invoice?->invoice_number ?? '?').' was claimed at '.$attempt->claimed_at->utc()->format('Y-m-d H:i').' UTC and has no payment intent. Check Stripe before charging by hand.');
        }

        return self::SUCCESS;
    }
}
