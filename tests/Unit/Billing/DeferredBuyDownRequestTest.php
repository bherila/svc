<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\DeferredBuyDownRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(DeferredBuyDownRequest::class)]
final class DeferredBuyDownRequestTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'missing target' => [[]];
        yield 'both targets' => [['hours' => 1, 'amount' => 100]];
        foreach ([null, 0, -1, 0.001, 0.00000001, 1000001, 'unknown'] as $hours) {
            yield 'hours '.json_encode($hours) => [['hours' => $hours]];
        }
        foreach ([null, 0, -1, 1.5, 2147483648, 'unknown'] as $amount) {
            yield 'amount '.json_encode($amount) => [['amount' => $amount]];
        }
        yield 'round up must be boolean' => [['hours' => 1, 'round_up' => 'yes']];
        yield 'negative discount' => [['hours' => 1, 'discount_amount' => -1, 'discount_reason' => 'Synthetic reason']];
        yield 'fractional discount' => [['hours' => 1, 'discount_amount' => 1.5, 'discount_reason' => 'Synthetic reason']];
        yield 'oversized discount' => [['hours' => 1, 'discount_amount' => 2147483648, 'discount_reason' => 'Synthetic reason']];
        yield 'missing reason' => [['hours' => 1, 'discount_amount' => 1]];
        yield 'blank reason' => [['hours' => 1, 'discount_amount' => 1, 'discount_reason' => '  ']];
        yield 'oversized reason' => [['hours' => 1, 'discount_amount' => 1, 'discount_reason' => str_repeat('synthetic', 126)]];
    }

    #[DataProvider('invalid')]
    public function test_invalid_inputs_are_refused_without_loading_a_database(array $input): void
    {
        $this->expectException(ValidationException::class);
        DeferredBuyDownRequest::from($input);
    }

    public function test_whole_minute_float_noise_is_accepted_but_real_fractions_have_a_field_error(): void
    {
        foreach ([(60 + 0.000003) / 60, (60 - 0.000003) / 60] as $hours) {
            $this->assertSame(60, DeferredBuyDownRequest::from(['hours' => $hours])->minutes);
        }
        foreach ([['hours' => 0.001], ['hours' => 1, 'discount_amount' => 1]] as $input) {
            try {
                DeferredBuyDownRequest::from($input);
                $this->fail('Invalid selection was accepted.');
            } catch (ValidationException $refused) {
                $this->assertArrayHasKey(isset($input['discount_amount']) ? 'discount_reason' : 'hours', $refused->errors());
            }
        }
        $request = DeferredBuyDownRequest::from(['hours' => 1, 'discount_reason' => null]);
        $this->assertNull($request->discountReason);
        $this->assertFalse($request->roundUp);
        $this->assertSame(0, $request->discount);
    }

    public function test_browser_and_console_scalars_normalize_to_the_same_immutable_terms(): void
    {
        $hours = DeferredBuyDownRequest::from(['hours' => '1.5', 'round_up' => '1', 'discount_amount' => '100', 'discount_reason' => '  Synthetic reason  ']);
        $this->assertSame(90, $hours->minutes);
        $this->assertNull($hours->amount);
        $this->assertTrue($hours->roundUp);
        $this->assertSame(100, $hours->discount);
        $this->assertSame('Synthetic reason', $hours->discountReason);
        $amount = DeferredBuyDownRequest::from(['amount' => '12345']);
        $this->assertSame(12345, $amount->amount);
        $this->assertNull($amount->minutes);
        $this->assertFalse($amount->roundUp);
        $this->assertSame(0, $amount->discount);
        $this->assertNull($amount->discountReason);
        $this->assertSame(1, DeferredBuyDownRequest::from(['hours' => 1 / 60])->minutes);
    }
}
