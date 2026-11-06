<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\InvoiceFacts;
use App\Support\Accounting\InvoiceLineEntries;
use App\Support\Accounting\LineFacts;
use PHPUnit\Framework\TestCase;

/**
 * The invoice-line feed's entries from facts alone: an issued line, the
 * signed change of a correction, the negation of a voided line, and a line
 * rolled back to how it was issued. Synthetic ids and amounts throughout.
 */
final class InvoiceLineEntriesTest extends TestCase
{
    private function invoice(?string $issueDate = '2030-02-01'): InvoiceFacts
    {
        return new InvoiceFacts('inv-1', 'SYN-1', $issueDate, '2030-01-01', '2030-01-31', 'USD', 'client-1', 'America/New_York');
    }

    /** An operator-authored taxable line as it stands now: 3 x 50.00 plus 12.00 tax. */
    private function line(string $quantity = '3.0000', int $tax = 1200, int $total = 16200, string $description = 'Hosting', string $type = 'adjustment', ?string $project = 'project-1'): LineFacts
    {
        return new LineFacts('line-1', $type, $description, $quantity, 5000, $tax, $total, $project, 41);
    }

    /** @return array<string, array<string, mixed>> */
    private function state(string $description, string $quantity, int $unit, int $tax): array
    {
        return ['description' => $description, 'quantity' => $quantity, 'unit_amount' => $unit, 'tax_amount' => $tax];
    }

    public function test_an_issued_line_is_one_entry_with_tax_broken_out(): void
    {
        $position = new FeedPosition('2030-02-01 15:00:00.000000', 1, 41);

        $entry = InvoiceLineEntries::issued($this->invoice(), $this->line(), $position);

        $this->assertSame($position, $entry->position);
        $this->assertSame([
            'id' => 'line-1',
            'entry_type' => 'invoice_line',
            'entry_type_label' => 'Invoice line',
            'recorded_at' => '2030-02-01T15:00:00+00:00',
            'effective_on' => '2030-02-01',
            'reverses_entry_id' => null,
            'correction_id' => null,
            'invoice_id' => 'inv-1',
            'invoice_number' => 'SYN-1',
            'currency' => 'USD',
            'client_id' => 'client-1',
            'project_id' => 'project-1',
            'line_id' => 'line-1',
            'line_type' => 'adjustment',
            'line_type_label' => 'Adjustment',
            'description' => 'Hosting',
            'net_amount' => 15000,
            'tax_amount' => 1200,
            'total_amount' => 16200,
            'tax_rate' => '0.0800',
            'taxable' => true,
            'service_period_start' => '2030-01-01',
            'service_period_end' => '2030-01-31',
        ], $entry->payload);
    }

    public function test_an_untaxed_line_with_no_project_and_a_multi_word_type(): void
    {
        $line = new LineFacts('line-2', 'prior_month_retainer', 'Retainer', '1.0000', 100000, 0, 100000, null);

        $payload = InvoiceLineEntries::issued($this->invoice(), $line, new FeedPosition('2030-02-01 15:00:00.000000', 1, 2))->payload;

        $this->assertSame([100000, 0, 100000, null, false, null], [$payload['net_amount'], $payload['tax_amount'], $payload['total_amount'], $payload['tax_rate'], $payload['taxable'], $payload['project_id']]);
        $this->assertSame(['prior_month_retainer', 'Prior month retainer'], [$payload['line_type'], $payload['line_type_label']]);
    }

    public function test_a_credit_is_a_negative_line_with_no_rate(): void
    {
        $credit = new LineFacts('line-3', 'credit', 'Credit', '1.0000', -2000, 0, -2000, null);

        $payload = InvoiceLineEntries::issued($this->invoice(), $credit, new FeedPosition('2030-02-01 15:00:00.000000', 1, 3))->payload;

        $this->assertSame([-2000, 0, -2000, null, false], [$payload['net_amount'], $payload['tax_amount'], $payload['total_amount'], $payload['tax_rate'], $payload['taxable']]);
    }

    public function test_the_effective_date_of_a_line_with_no_issue_date_is_the_day_it_was_recorded_in_the_workspace_calendar(): void
    {
        // 02:30 UTC on the 2nd is 21:30 on the 1st in New York.
        $payload = InvoiceLineEntries::issued($this->invoice(null), $this->line(), new FeedPosition('2030-02-02 02:30:00.000000', 1, 41))->payload;

        $this->assertSame('2030-02-01', $payload['effective_on']);
        $this->assertSame('2030-02-02T02:30:00+00:00', $payload['recorded_at']);
        $this->assertSame('2030-02-01', InvoiceLineEntries::recordedOn(new FeedPosition('2030-02-02 02:30:00.000000', 1, 1), 'America/New_York'));
        $this->assertSame('2030-02-02', InvoiceLineEntries::recordedOn(new FeedPosition('2030-02-02 02:30:00.000000', 1, 1), 'UTC'));
    }

    public function test_a_tax_rate_is_the_effective_share_of_the_net_to_four_places(): void
    {
        $rate = static fn (int $net, int $tax): ?string => (new LineFacts('l', 'adjustment', 'd', '1.0000', $net, $tax, $net + $tax, null))->taxRate();

        $this->assertSame('0.3333', $rate(3, 1));
        $this->assertSame('0.0825', $rate(100000, 8250));
        $this->assertSame('1.0000', $rate(500, 500));
        $this->assertNull($rate(1000, 0));
        // A line whose net is not positive has no rate to state.
        $this->assertNull((new LineFacts('l', 'adjustment', 'd', '1.0000', 0, 5, 5, null))->taxRate());
        $this->assertNull((new LineFacts('l', 'adjustment', 'd', '1.0000', -1000, 5, -995, null))->taxRate());
    }

    public function test_a_correction_is_the_signed_change_it_made_and_names_the_line_it_reverses(): void
    {
        // 2 x 50.00 + 8.00 became 3 x 50.00 + 12.00.
        $diff = ['line' => 'line-1', 'description_changed' => false, 'before' => $this->state('Hosting', '2.0000', 5000, 800), 'after' => $this->state('Hosting', '3.0000', 5000, 1200)];
        // 03:30 UTC on March 2 is 22:30 on March 1 in New York.
        $position = new FeedPosition('2030-03-02 03:30:00.000000', 2, 9, 0);

        $entry = InvoiceLineEntries::corrected($this->invoice(), $this->line(), $diff, 'corr-1', $position);

        $this->assertNotNull($entry);
        $this->assertSame($position, $entry->position);
        $this->assertSame([
            'id' => 'a2c9d033-7146-5b03-b106-12a4c2529bf6',
            'entry_type' => 'correction',
            'entry_type_label' => 'Correction',
            'recorded_at' => '2030-03-02T03:30:00+00:00',
            'effective_on' => '2030-03-01',
            'reverses_entry_id' => 'line-1',
            'correction_id' => 'corr-1',
            'invoice_id' => 'inv-1',
            'invoice_number' => 'SYN-1',
            'currency' => 'USD',
            'client_id' => 'client-1',
            'project_id' => 'project-1',
            'line_id' => 'line-1',
            'line_type' => 'adjustment',
            'line_type_label' => 'Adjustment',
            'description' => 'Hosting',
            'net_amount' => 5000,
            'tax_amount' => 400,
            'total_amount' => 5400,
            'tax_rate' => '0.0800',
            'taxable' => true,
            'service_period_start' => '2030-01-01',
            'service_period_end' => '2030-01-31',
        ], $entry->payload);
    }

    public function test_a_correction_that_lowers_a_line_is_negative_and_one_that_removes_its_tax_is_still_taxable(): void
    {
        $diff = ['before' => $this->state('Hosting', '3.0000', 5000, 1200), 'after' => $this->state('Hosting', '1.0000', 5000, 0)];
        $current = new LineFacts('line-1', 'adjustment', 'Hosting', '1.0000', 5000, 0, 5000, null, 41);

        $payload = InvoiceLineEntries::corrected($this->invoice(), $current, $diff, 'corr-2', new FeedPosition('2030-03-02 12:00:00.000000', 2, 10, 0))?->payload;

        $this->assertNotNull($payload);
        $this->assertSame([-10000, -1200, -11200, null, true], [$payload['net_amount'], $payload['tax_amount'], $payload['total_amount'], $payload['tax_rate'], $payload['taxable']]);
    }

    public function test_a_correction_that_changes_only_a_tax_keeps_the_rate_of_the_line_after_it(): void
    {
        $diff = ['before' => $this->state('Hosting', '3.0000', 5000, 0), 'after' => $this->state('Hosting', '3.0000', 5000, 1200)];

        $payload = InvoiceLineEntries::corrected($this->invoice(), $this->line(), $diff, 'corr-3', new FeedPosition('2030-03-02 12:00:00.000000', 2, 11, 0))?->payload;

        $this->assertNotNull($payload);
        $this->assertSame([0, 1200, 1200, '0.0800', true], [$payload['net_amount'], $payload['tax_amount'], $payload['total_amount'], $payload['tax_rate'], $payload['taxable']]);
    }

    public function test_a_correction_that_changes_no_money_is_no_entry(): void
    {
        $position = new FeedPosition('2030-03-02 12:00:00.000000', 2, 9, 0);
        $wording = ['before' => $this->state('Hosting', '3.0000', 5000, 1200), 'after' => $this->state('Web hosting', '3.0000', 5000, 1200)];

        $this->assertNull(InvoiceLineEntries::corrected($this->invoice(), $this->line(description: 'Web hosting'), $wording, 'corr-1', $position));
    }

    public function test_a_diff_that_cannot_be_read_is_no_entry(): void
    {
        $position = new FeedPosition('2030-03-02 12:00:00.000000', 2, 9, 0);
        $good = $this->state('Hosting', '2.0000', 5000, 800);
        $read = static fn (array $diff): bool => InvoiceLineEntries::corrected(
            new InvoiceFacts('inv-1', 'SYN-1', '2030-02-01', null, null, 'USD', 'client-1', 'UTC'),
            new LineFacts('line-1', 'adjustment', 'Hosting', '3.0000', 5000, 1200, 16200, null),
            $diff, 'corr-1', $position,
        ) !== null;

        $this->assertTrue($read(['before' => $good, 'after' => $this->state('Hosting', '3.0000', 5000, 1200)]));
        $this->assertFalse($read(['after' => $this->state('Hosting', '3.0000', 5000, 1200)]));
        $this->assertFalse($read(['before' => $good]));
        $this->assertFalse($read(['before' => 'x', 'after' => $good]));
        foreach ([
            ['description', 7], ['quantity', 2], ['quantity', 'abc'], ['unit_amount', '5000'], ['tax_amount', 8.0], ['quantity', '0.0000'], ['unit_amount', -1],
        ] as [$field, $value]) {
            $this->assertFalse($read(['before' => [...$good, $field => $value], 'after' => $this->state('Hosting', '3.0000', 5000, 1200)]), $field);
        }
    }

    public function test_a_voided_line_is_the_negation_of_what_it_stands_for(): void
    {
        $position = new FeedPosition('2030-04-02 03:30:00.000000', 3, 5, 41);

        $entry = InvoiceLineEntries::voided($this->invoice(), $this->line(), $position);

        $this->assertSame($position, $entry->position);
        $this->assertSame([
            'id' => 'acfda6be-da69-5ba4-a9d1-2d3be0a11ff7',
            'entry_type' => 'void',
            'entry_type_label' => 'Void',
            'recorded_at' => '2030-04-02T03:30:00+00:00',
            'effective_on' => '2030-04-01',
            'reverses_entry_id' => 'line-1',
            'correction_id' => null,
            'invoice_id' => 'inv-1',
            'invoice_number' => 'SYN-1',
            'currency' => 'USD',
            'client_id' => 'client-1',
            'project_id' => 'project-1',
            'line_id' => 'line-1',
            'line_type' => 'adjustment',
            'line_type_label' => 'Adjustment',
            'description' => 'Hosting',
            'net_amount' => -15000,
            'tax_amount' => -1200,
            'total_amount' => -16200,
            'tax_rate' => '0.0800',
            'taxable' => true,
            'service_period_start' => '2030-01-01',
            'service_period_end' => '2030-01-31',
        ], $entry->payload);
    }

    public function test_a_voided_credit_reverses_to_a_positive_amount(): void
    {
        $credit = new LineFacts('line-3', 'credit', 'Credit', '1.0000', -2000, 0, -2000, null);

        $payload = InvoiceLineEntries::voided($this->invoice(), $credit, new FeedPosition('2030-04-02 12:00:00.000000', 3, 5, 43))->payload;

        $this->assertSame([2000, 0, 2000, false], [$payload['net_amount'], $payload['tax_amount'], $payload['total_amount'], $payload['taxable']]);
    }

    public function test_an_uncorrected_line_is_as_issued_as_it_stands(): void
    {
        $this->assertEquals($this->line(), InvoiceLineEntries::asIssued($this->line(), []));
    }

    public function test_a_correction_is_rolled_back_to_the_line_as_it_was_issued(): void
    {
        $diffs = [['before' => $this->state('Hosting (draft wording)', '2.0000', 5000, 800), 'after' => $this->state('Hosting', '3.0000', 5000, 1200)]];

        $issued = InvoiceLineEntries::asIssued($this->line(), $diffs);

        $this->assertEquals(new LineFacts('line-1', 'adjustment', 'Hosting (draft wording)', '2.0000', 5000, 800, 10800, 'project-1', 41), $issued);
        $this->assertSame([10000, 800], [$issued->netAmount(), $issued->taxAmount]);
    }

    public function test_the_oldest_corrections_before_is_the_issue_however_many_follow(): void
    {
        // 1 -> 2 -> 3 units; the line now stands at 3.
        $diffs = [
            ['before' => $this->state('Hosting', '1.0000', 5000, 400), 'after' => $this->state('Hosting', '2.0000', 5000, 800)],
            ['before' => $this->state('Hosting', '2.0000', 5000, 800), 'after' => $this->state('Hosting', '3.0000', 5000, 1200)],
        ];

        $issued = InvoiceLineEntries::asIssued($this->line(), $diffs);

        $this->assertSame(['1.0000', 400, 5400], [$issued->quantity, $issued->taxAmount, $issued->totalAmount]);
    }

    public function test_a_wording_only_correction_keeps_the_money_and_a_credits_signed_total(): void
    {
        $credit = new LineFacts('line-3', 'credit', 'Credit for May', '1.0000', -2000, 0, -2000, null, 43);
        // The correction form restated the quantity as "1" and changed the wording.
        $diffs = [['before' => $this->state('Credit', '1', -2000, 0), 'after' => $this->state('Credit for May', '1', -2000, 0)]];

        $issued = InvoiceLineEntries::asIssued($credit, $diffs);

        $this->assertSame(['Credit', -2000, 0, -2000], [$issued->description, $issued->unitAmount, $issued->taxAmount, $issued->totalAmount]);
    }

    public function test_a_wording_correction_then_a_money_correction_roll_back_both(): void
    {
        $diffs = [
            ['before' => $this->state('Hosting', '2.0000', 5000, 800), 'after' => $this->state('Web hosting', '2.0000', 5000, 800)],
            ['before' => $this->state('Web hosting', '2.0000', 5000, 800), 'after' => $this->state('Web hosting', '3.0000', 5000, 1200)],
        ];

        $issued = InvoiceLineEntries::asIssued($this->line(description: 'Web hosting'), $diffs);

        $this->assertSame(['Hosting', '2.0000', 10800], [$issued->description, $issued->quantity, $issued->totalAmount]);
    }

    public function test_a_diff_that_cannot_be_read_does_not_disturb_the_roll_back(): void
    {
        $diffs = [
            ['before' => 'garbage', 'after' => 'garbage'],
            ['before' => $this->state('Hosting', '2.0000', 5000, 800), 'after' => $this->state('Hosting', '3.0000', 5000, 1200)],
        ];

        $this->assertSame(10800, InvoiceLineEntries::asIssued($this->line(), $diffs)->totalAmount);
    }
}
