<?php

namespace App\Services\Finance;

use App\Models\Workspace;
use App\Services\Billing\StripeDisputeRecovery;
use App\Services\Billing\StripeGateway;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Billing\StripeProcessorMovementFacts;
use App\Support\Finance\ProcessorReadBudget;
use DomainException;
use SensitiveParameter;
use Stripe\Exception\ExceptionInterface;

/**
 * Actual balance entries for one recorded payment, independent of payout dates.
 * Reads and validates the complete bounded group before recording any entry.
 * Original payment amounts, allocations and dispute policy are not changed here.
 */
final class StripeProcessorMovementSync
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly StripeDisputeRecovery $disputes,
        private readonly ProcessorMovementRecorder $recorder,
    ) {}

    /** @return array{created: int, unchanged: int, enriched: int, conflict: int, deferred: int} */
    public function sync(#[SensitiveParameter] Workspace $workspace, int $paymentId, bool $apply = false, ?ProcessorReadBudget $budget = null, bool $recoverDisputes = false): array
    {
        $budget ??= new ProcessorReadBudget;
        $counts = ['created' => 0, 'unchanged' => 0, 'enriched' => 0, 'conflict' => 0, 'deferred' => 0];
        try {
            // This also establishes the owned payment's identity before HTTP.
            // Reuse its complete dispute read rather than requesting it twice.
            $snapshot = $this->disputes->read($workspace, $paymentId, $budget);
            $client = $this->gateway->client();
            $budget->consume();
            $account = $client->accounts->retrieve()->toArray();
            $namespaceId = $account['id'] ?? null;
            $mode = $this->gateway->mode();
            if (! is_string($namespaceId) || preg_match('/^acct_[A-Za-z0-9_]{1,200}$/D', $namespaceId) !== 1
                || ! in_array($mode, ['test', 'live'], true)) {
                throw self::incomplete();
            }
            $accountKey = $mode.':'.$namespaceId;
            $filter = str_starts_with($snapshot->providerIdentifier, 'pi_') ? 'payment_intent' : 'charge';
            $refunds = $this->refunds([$filter => $snapshot->providerIdentifier], $budget);
            $facts = [];
            foreach ($refunds as $refund) {
                foreach ([false, true] as $failure) {
                    $pointer = $refund[$failure ? 'failure_balance_transaction' : 'balance_transaction'] ?? null;
                    $entry = $this->entry($pointer, $budget);
                    $fact = StripeProcessorMovementFacts::refund($refund, $entry, $failure);
                    if ($fact !== null) {
                        $facts[] = $fact;
                    } elseif (! $failure || in_array($refund['status'] ?? null, ['failed', 'canceled'], true)) {
                        $counts['deferred']++;
                    }
                }
            }
            foreach ($snapshot->disputes as $dispute) {
                $entries = $dispute['balance_transactions'] ?? null;
                if (! is_array($entries) || ! array_is_list($entries)) {
                    throw self::incomplete();
                }
                if ($entries === []) {
                    StripeProcessorMovementFacts::dispute($dispute);
                    if (! in_array($dispute['status'] ?? null, ['prevented', 'warning_closed'], true)) {
                        $counts['deferred']++;
                    }
                }
                foreach ($entries as $entry) {
                    $fact = StripeProcessorMovementFacts::dispute($dispute, $this->entry($entry, $budget));
                    if ($fact === null) {
                        throw self::incomplete();
                    }
                    $facts[] = $fact;
                }
            }
            $seen = [];
            foreach ($facts as $fact) {
                if (isset($seen[$fact->identifier]) || $fact->currency !== $snapshot->currency
                    || ! in_array($snapshot->providerIdentifier, $fact->paymentIdentifiers, true)) {
                    throw self::incomplete();
                }
                $seen[$fact->identifier] = true;
            }
        } catch (ExceptionInterface|DomainException|StripeEventMustBeRetried) {
            // Neither a provider response nor an exception carrying it is logged.
            throw self::incomplete();
        }
        if ($recoverDisputes) {
            $this->disputes->apply($workspace, $snapshot, $apply);
        }
        // Each immutable entry is independently idempotent. If persistence is
        // interrupted, rerunning the payment safely resumes the remaining rows.
        foreach ($facts as $fact) {
            $outcome = $this->recorder->record($workspace, $paymentId, $fact, $accountKey, $apply);
            $counts[$outcome->value]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $filter
     * @return list<array<string, mixed>>
     */
    private function refunds(#[SensitiveParameter] array $filter, ProcessorReadBudget $budget): array
    {
        $result = [];
        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 100; $page++) {
            $input = [...$filter, 'limit' => 100, 'expand' => ['data.balance_transaction', 'data.failure_balance_transaction']];
            if ($cursor !== null) {
                $input['starting_after'] = $cursor;
            }
            $budget->consume();
            $response = $this->gateway->client()->refunds->all($input)->toArray();
            $rows = $response['data'] ?? null;
            $more = $response['has_more'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! is_bool($more)
                || ($more && $rows === [])) {
                throw self::incomplete();
            }
            foreach ($rows as $row) {
                $id = is_array($row) ? ($row['id'] ?? null) : null;
                if (! is_string($id) || preg_match('/^[\x21-\x7E]{1,255}$/D', $id) !== 1 || isset($seen[$id])) {
                    throw self::incomplete();
                }
                $seen[$id] = true;
                $result[] = $row;
                $cursor = $id;
            }
            if (! $more) {
                return $result;
            }
        }
        throw self::incomplete();
    }

    /** @return array<string, mixed>|null */
    private function entry(#[SensitiveParameter] mixed $pointer, ProcessorReadBudget $budget): ?array
    {
        if ($pointer === null) {
            return null;
        }
        if (is_array($pointer)) {
            return $pointer;
        }
        if (! is_string($pointer) || preg_match('/^[\x21-\x7E]{1,255}$/D', $pointer) !== 1) {
            throw self::incomplete();
        }

        $budget->consume();

        return $this->gateway->client()->balanceTransactions->retrieve($pointer)->toArray();
    }

    private static function incomplete(): StripeEventMustBeRetried
    {
        return new StripeEventMustBeRetried('Stripe processor movements could not be read completely; retry the sync.');
    }
}
