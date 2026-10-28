<?php

namespace App\Services\Square;

use App\Support\Square\SquareDisputeFacts;
use App\Support\Square\SquarePaymentFacts;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Provider facts and the exact local coverage the person reviewed. */
final readonly class SquareCoveredPaymentObservation
{
    /** @param list<SquarePaymentFacts> $payments
     * @param array<string,list<SquareDisputeFacts>> $disputes */
    public function __construct(public string $coverageId, public array $payments, public array $disputes, public string $timezone = 'UTC') {}

    public function version(SquarePaymentFacts $payment): string
    {
        return hash('sha256', json_encode([
            $this->coverageId, $this->timezone, $payment->id, $payment->orderId, $payment->locationId,
            $payment->currency, $payment->amount, $payment->refundedAmount, $payment->receivedAt->toISOString(),
            $this->disputes[$payment->id] ?? [],
        ], JSON_THROW_ON_ERROR));
    }

    public function payment(string $id): SquarePaymentFacts
    {
        foreach ($this->payments as $payment) {
            if ($payment->id === $id) {
                return $payment;
            }
        }
        throw new ConflictHttpException('This completed payment is no longer on the covered Square invoice. Review it again.');
    }

    public function providerRefusal(SquarePaymentFacts $payment): ?string
    {
        if ($payment->refundedAmount > 0) {
            return 'This payment includes a refund and requires manual review.';
        }
        foreach ($this->disputes[$payment->id] ?? [] as $dispute) {
            if ($dispute->currency !== $payment->currency || $dispute->amount > $payment->amount || ! $dispute->resolvedForMerchant) {
                return 'This payment has disputed money and requires manual review.';
            }
        }

        return null;
    }
}
