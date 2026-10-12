<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquarePresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A presented Square record never drops list items silently (#438): every
 * capped list sits beside a `<list>_truncated` flag that says when Square sent
 * more than was kept. The structural test finds every list in every shape, so
 * a new list without its flag fails here.
 */
final class SquarePresenterTruncationTest extends TestCase
{
    public function test_every_list_in_every_shape_has_a_truncation_flag(): void
    {
        $presenter = new SquarePresenter;
        $shapes = [
            'invoice' => $presenter->invoice(['payment_requests' => [['reminders' => [['uid' => 'rem-fixture']]]]]),
            'order' => $presenter->order(['line_items' => [['uid' => 'li-fixture']], 'tenders' => [['id' => 'tender-fixture']]]),
            'payment' => $presenter->payment(['processing_fee' => [['type' => 'INITIAL']], 'refund_ids' => ['refund-fixture']]),
            'customer' => $presenter->customer([]),
            'location' => $presenter->location([]),
        ];

        $lists = [];
        foreach ($shapes as $shape => $presented) {
            $this->collectLists($shape, $presented, $lists);
        }

        $this->assertSame([
            'invoice.payment_requests',
            'invoice.payment_requests.reminders',
            'order.line_items',
            'order.tenders',
            'payment.processing_fee',
            'payment.refund_ids',
        ], $lists);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function caps(): iterable
    {
        yield 'invoice payment requests' => ['invoice', 'payment_requests', 20];
        yield 'payment request reminders' => ['reminder', 'reminders', 10];
        yield 'order line items' => ['order', 'line_items', 100];
        yield 'order tenders' => ['order', 'tenders', 50];
        yield 'payment processing fees' => ['payment', 'processing_fee', 10];
        yield 'payment refund ids' => ['payment', 'refund_ids', 50];
    }

    #[DataProvider('caps')]
    public function test_a_list_at_its_cap_is_whole_and_one_more_is_flagged(string $shape, string $key, int $cap): void
    {
        foreach ([$cap - 1 => false, $cap => false, $cap + 1 => true, $cap + 25 => true] as $count => $truncated) {
            $presented = $this->present($shape, $key, $count);
            $this->assertCount(min($count, $cap), $presented[$key], "{$key} x{$count}");
            $this->assertSame($truncated, $presented[$key.'_truncated'], "{$key} x{$count}");
        }
    }

    /** Items that are not records are not counted as cut. */
    public function test_unusable_items_are_skipped_without_flagging(): void
    {
        $order = (new SquarePresenter)->order(['tenders' => ['not-a-tender', 7, ['id' => 'tender-fixture']]]);
        $this->assertCount(1, $order['tenders']);
        $this->assertFalse($order['tenders_truncated']);

        $payment = (new SquarePresenter)->payment(['refund_ids' => [['id' => 'nested'], 'refund-fixture']]);
        $this->assertSame(['refund-fixture'], $payment['refund_ids']);
        $this->assertFalse($payment['refund_ids_truncated']);
    }

    /** @return array<string, mixed> */
    private function present(string $shape, string $key, int $count): array
    {
        $presenter = new SquarePresenter;
        $items = $key === 'refund_ids'
            ? array_map(static fn (int $i): string => 'refund-fixture-'.$i, range(1, $count))
            : array_map(static fn (int $i): array => ['uid' => 'fixture-'.$i, 'id' => 'fixture-'.$i], range(1, $count));

        return match ($shape) {
            'invoice' => $presenter->invoice([$key => $items]),
            'reminder' => $presenter->invoice(['payment_requests' => [[$key => $items]]])['payment_requests'][0],
            'order' => $presenter->order([$key => $items]),
            'payment' => $presenter->payment([$key => $items]),
        };
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $lists
     */
    private function collectLists(string $path, array $value, array &$lists): void
    {
        foreach ($value as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (array_is_list($item)) {
                $this->assertArrayHasKey($key.'_truncated', $value, $path.'.'.$key);
                $this->assertIsBool($value[$key.'_truncated'], $path.'.'.$key);
                $lists[] = $path.'.'.$key;
                foreach ($item as $element) {
                    if (is_array($element)) {
                        $this->collectLists($path.'.'.$key, $element, $lists);
                    }
                }

                continue;
            }
            $this->collectLists($path.'.'.$key, $item, $lists);
        }
    }
}
