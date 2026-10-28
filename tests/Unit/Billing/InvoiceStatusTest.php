<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoiceStatus;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceStatusTest extends TestCase
{
    public function test_disputed_is_charged_and_immutable_but_collection_is_paused(): void
    {
        $this->assertSame(['draft', 'issued', 'partially_paid', 'paid', 'disputed', 'void'], InvoiceStatus::all());
        $this->assertSame(['issued', 'partially_paid', 'paid', 'disputed', 'void'], InvoiceStatus::settled());
        $this->assertSame(['issued', 'partially_paid', 'paid', 'disputed'], InvoiceStatus::charged());
        $this->assertSame(['draft', 'issued', 'partially_paid', 'paid', 'disputed'], InvoiceStatus::live());
        $this->assertSame(['issued', 'partially_paid'], InvoiceStatus::collectible());
        $this->assertSame(['issued', 'partially_paid', 'disputed'], InvoiceStatus::recordable());
        $this->assertTrue(InvoiceStatus::Disputed->isSettled());
        $this->assertTrue(InvoiceStatus::Disputed->hasCharged());
    }

    /** @return iterable<string, array{InvoiceStatus, int, bool, InvoiceStatus}> */
    public static function paymentStates(): iterable
    {
        foreach ([InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid, InvoiceStatus::Disputed] as $prior) {
            foreach ([0, 500, 1000, 1500] as $paid) {
                yield $prior->value.' disputed '.$paid => [$prior, $paid, true, InvoiceStatus::Disputed];
            }
            yield $prior->value.' none' => [$prior, 0, false, InvoiceStatus::Issued];
            yield $prior->value.' partial' => [$prior, 500, false, InvoiceStatus::PartiallyPaid];
            yield $prior->value.' whole' => [$prior, 1000, false, InvoiceStatus::Paid];
            yield $prior->value.' overpaid' => [$prior, 1500, false, InvoiceStatus::Paid];
        }
        yield 'void with dispute' => [InvoiceStatus::Void, 0, true, InvoiceStatus::Void];
        yield 'void without dispute' => [InvoiceStatus::Void, 1000, false, InvoiceStatus::Void];
    }

    #[DataProvider('paymentStates')]
    public function test_payment_facts_control_status_without_reopening_a_void(InvoiceStatus $prior, int $paid, bool $hasDispute, InvoiceStatus $expected): void
    {
        $this->assertSame($expected, $prior->withPayments($paid, 1000, $hasDispute));
    }

    public function test_a_draft_cannot_be_promoted_by_payment_arithmetic(): void
    {
        $this->expectException(DomainException::class);
        InvoiceStatus::Draft->withPayments(1000, 1000, true);
    }
}
