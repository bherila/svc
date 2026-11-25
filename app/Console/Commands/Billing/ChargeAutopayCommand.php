<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\MutationCommand;
use App\Models\InvoiceAutopayAttempt;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Support\Billing\Autopay\AutopayChargeOutcome;
use App\Support\Billing\Autopay\AutopayChargeResult;
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
            Log::warning(AutopayChargeService::isConsentHold($attempt)
                ? 'An autopay attempt is held for review until the client re-confirms consent.'
                : 'An autopay attempt was claimed with no payment intent and needs review.', ['attempt' => $attempt->public_id]);
        }
        // Invoices left for the next run because the identity provider could not say whether the consenting contact is still active.
        $consentUnchecked = count(array_filter($outcomes, static fn (AutopayChargeOutcome $outcome): bool => $outcome->result === AutopayChargeResult::ConsentCheckUnavailable));

        // Workspaces whose Stripe connection was refused: their invoices were not charged and keep their attempt.
        $connectionProblems = array_values(array_unique(array_map(
            static fn (AutopayChargeOutcome $outcome): string => $outcome->workspaceId,
            array_filter($outcomes, static fn (AutopayChargeOutcome $outcome): bool => $outcome->result === AutopayChargeResult::ConnectionNeedsAttention),
        )));

        if ($this->option('format') === 'json') {
            $this->json([
                'dry_run' => ! $apply,
                'executed' => $apply,
                'connection_needs_attention' => $connectionProblems,
                'consent_check_unavailable' => $consentUnchecked,
                'outcomes' => array_map(static fn (AutopayChargeOutcome $outcome): array => $outcome->toArray(), $outcomes),
                'needs_review' => array_map(static fn (InvoiceAutopayAttempt $attempt): array => [
                    'attempt_id' => $attempt->public_id,
                    'invoice_id' => $attempt->invoice?->public_id,
                    'claimed_at' => $attempt->claimed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'reason' => AutopayChargeService::isConsentHold($attempt) ? AutopayChargeService::CONSENT_HOLD_CODE : 'no_payment_intent',
                ], $review),
            ]);

            return self::SUCCESS;
        }

        $this->components->info(($apply ? 'Autopay run: ' : 'Autopay preview (re-run with --apply to charge): ').count($outcomes).' invoice(s).');
        foreach ($outcomes as $outcome) {
            $this->line(sprintf('  %s  %s  %s %s  %s', $outcome->invoiceNumber, $outcome->result->value, $outcome->currency, number_format(($outcome->amount ?? 0) / 100, 2), (string) $outcome->detail));
        }
        foreach ($connectionProblems as $workspaceId) {
            $this->components->warn('Stripe connection needs attention: workspace '.$workspaceId.' was refused by Stripe, so its due invoices were not charged and keep their attempt. Re-save the connection in the workspace payments settings; autopay charges on the first run after.');
        }
        if ($consentUnchecked > 0) {
            $this->components->warn('Consent check unavailable: '.$consentUnchecked.' invoice(s) were not charged because the identity provider could not confirm that the client contact who consented is still active. Nothing was sent and no attempt was spent; the next run asks again.');
        }
        foreach ($review as $attempt) {
            if (AutopayChargeService::isConsentHold($attempt)) {
                $this->components->warn('Held for consent: autopay attempt '.$attempt->public_id.' for invoice '.($attempt->invoice->invoice_number).' was not charged and nothing was sent to Stripe, because the client contact whose consent it relies on no longer has an active identity. Re-confirm consent with the client and record it as new consent (turn autopay off and on again, or change its limit); the next run then decides the invoice afresh. Or close it out as not charged (invoices.autopay.resolve) and collect by hand.');

                continue;
            }
            $this->components->warn('Needs review: autopay attempt '.$attempt->public_id.' for invoice '.($attempt->invoice->invoice_number).' was claimed at '.$attempt->claimed_at->utc()->format('Y-m-d H:i').' UTC and has no payment intent. Check Stripe, then record the outcome on the invoice (invoices.autopay.resolve) before charging by hand.');
        }

        return self::SUCCESS;
    }
}
