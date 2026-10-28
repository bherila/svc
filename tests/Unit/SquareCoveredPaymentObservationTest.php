<?php

namespace Tests\Unit;

use App\Services\Square\SquareCoveredPaymentObservation;
use App\Support\Square\SquareDisputeFacts;
use App\Support\Square\SquarePaymentFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SquareCoveredPaymentObservationTest extends TestCase
{
    /** @return array<string,mixed> */
    private function payment(): array
    {
        return ['id' => 'synthetic_payment', 'status' => 'COMPLETED', 'order_id' => 'synthetic_order',
            'location_id' => 'synthetic_location', 'amount_money' => ['amount' => 1000, 'currency' => 'USD'],
            'refunded_money' => ['amount' => 0, 'currency' => 'USD'], 'source_type' => 'CARD',
            'card_details' => ['card_payment_timeline' => ['captured_at' => '2030-02-01T12:00:00Z']]];
    }

    public static function changedFacts(): array
    {
        return [['id'], ['order'], ['location'], ['currency'], ['amount'], ['refund'], ['captured'], ['coverage'], ['dispute'], ['timezone']];
    }

    #[DataProvider('changedFacts')]
    public function test_a_changed_allocation_or_provider_fact_invalidates_the_review(string $change): void
    {
        $original = $this->payment();
        $before = SquarePaymentFacts::from($original, $original['id']);
        $changed = $original;
        $coverage = 'synthetic_coverage';
        $disputes = [];
        if ($change === 'id') {
            $changed['id'] = 'synthetic_other_payment';
        }
        if ($change === 'order') {
            $changed['order_id'] = 'synthetic_other_order';
        }
        if ($change === 'location') {
            $changed['location_id'] = 'synthetic_other_location';
        }
        if ($change === 'amount') {
            $changed['amount_money']['amount'] = 999;
        }
        if ($change === 'refund') {
            $changed['refunded_money']['amount'] = 1;
        }
        if ($change === 'currency') {
            $changed['amount_money']['currency'] = 'EUR';
            $changed['refunded_money']['currency'] = 'EUR';
        }
        if ($change === 'captured') {
            $changed['card_details']['card_payment_timeline']['captured_at'] = '2030-02-02T12:00:00Z';
        }
        if ($change === 'coverage') {
            $coverage = 'synthetic_other_coverage';
        }
        if ($change === 'dispute') {
            $disputes = ['synthetic_payment' => [$this->dispute('WON')]];
        }
        $after = SquarePaymentFacts::from($changed, $changed['id']);
        $old = new SquareCoveredPaymentObservation('synthetic_coverage', [$before], []);
        $new = new SquareCoveredPaymentObservation($coverage, [$after], $disputes, $change === 'timezone' ? 'America/Los_Angeles' : 'UTC');
        $this->assertNotSame($old->version($before), $new->version($after));
        $this->assertSame($before, $old->payment('synthetic_payment'));
        $this->assertNull($old->providerRefusal($before));
    }

    public function test_a_missing_payment_is_a_conflict_rather_than_another_payment_being_selected(): void
    {
        $payment = SquarePaymentFacts::from($this->payment(), 'synthetic_payment');
        $observation = new SquareCoveredPaymentObservation('synthetic_coverage', [$payment], []);
        $this->expectException(ConflictHttpException::class);
        $observation->payment('synthetic_missing_payment');
    }

    public function test_refunds_and_unresolved_disputes_are_refused_but_a_won_dispute_is_not(): void
    {
        $payment = SquarePaymentFacts::from($this->payment(), 'synthetic_payment');
        foreach (['LOST', 'EVIDENCE_REQUIRED'] as $state) {
            $observed = new SquareCoveredPaymentObservation('synthetic_coverage', [$payment], ['synthetic_payment' => [$this->dispute($state)]]);
            $this->assertNotNull($observed->providerRefusal($payment));
        }
        $won = new SquareCoveredPaymentObservation('synthetic_coverage', [$payment], ['synthetic_payment' => [$this->dispute('WON')]]);
        $this->assertNull($won->providerRefusal($payment));
        $refunded = SquarePaymentFacts::from([...$this->payment(), 'refunded_money' => ['amount' => 1, 'currency' => 'USD']], 'synthetic_payment');
        $this->assertNotNull($won->providerRefusal($refunded));
        $mismatch = new SquareCoveredPaymentObservation('synthetic_coverage', [$payment], ['synthetic_payment' => [$this->dispute('WON', 'EUR')]]);
        $this->assertNotNull($mismatch->providerRefusal($payment));
        $over = new SquareCoveredPaymentObservation('synthetic_coverage', [$payment], ['synthetic_payment' => [$this->dispute('WON', 'USD', 1001)]]);
        $this->assertNotNull($over->providerRefusal($payment));
    }

    private function dispute(string $state, string $currency = 'USD', int $amount = 1000): SquareDisputeFacts
    {
        return SquareDisputeFacts::from(['id' => 'synthetic_dispute', 'state' => $state, 'disputed_payment' => ['payment_id' => 'synthetic_payment'],
            'amount_money' => ['amount' => $amount, 'currency' => $currency], 'updated_at' => '2030-02-02T00:00:00Z'], 'synthetic_dispute');
    }
}
