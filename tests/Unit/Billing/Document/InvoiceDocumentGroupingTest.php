<?php

namespace Tests\Unit\Billing\Document;

use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\DocumentSection;
use App\Support\Billing\Document\InvoiceDocumentFormat;
use App\Support\Billing\Document\InvoiceDocumentGrouper;
use App\Support\Billing\Document\InvoiceDocumentLine;
use App\Support\Billing\Document\InvoiceDocumentSectionBlock;
use App\Support\Billing\Document\StoredValueLabel;
use App\Support\Billing\InvoiceLineType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentSection::class)]
#[CoversClass(InvoiceDocumentGrouper::class)]
#[CoversClass(InvoiceDocumentSectionBlock::class)]
#[CoversClass(InvoiceDocumentLine::class)]
#[CoversClass(InvoiceDocumentFormat::class)]
#[CoversClass(StoredValueLabel::class)]
#[CoversClass(DocumentMode::class)]
final class InvoiceDocumentGroupingTest extends TestCase
{
    /** @return iterable<string, array{InvoiceLineType, DocumentSection}> */
    public static function mapping(): iterable
    {
        yield 'retainer' => [InvoiceLineType::Retainer, DocumentSection::Time];
        yield 'prior month retainer' => [InvoiceLineType::PriorMonthRetainer, DocumentSection::Time];
        yield 'prior month billable' => [InvoiceLineType::PriorMonthBillable, DocumentSection::Time];
        yield 'additional hours' => [InvoiceLineType::AdditionalHours, DocumentSection::Time];
        yield 'subcontractor' => [InvoiceLineType::Subcontractor, DocumentSection::Time];
        yield 'recurring item' => [InvoiceLineType::RecurringItem, DocumentSection::RecurringServices];
        yield 'expense' => [InvoiceLineType::Expense, DocumentSection::Expenses];
        yield 'carried deferred applied' => [InvoiceLineType::CarriedDeferredApplied, DocumentSection::DeferredWork];
        yield 'carried deferred billed' => [InvoiceLineType::CarriedDeferredBilled, DocumentSection::DeferredWork];
        yield 'deferred buydown' => [InvoiceLineType::DeferredBuyDown, DocumentSection::DeferredWork];
        yield 'credit' => [InvoiceLineType::Credit, DocumentSection::Adjustments];
        yield 'adjustment' => [InvoiceLineType::Adjustment, DocumentSection::Adjustments];
        yield 'reconciliation' => [InvoiceLineType::Reconciliation, DocumentSection::Adjustments];
        yield 'milestone' => [InvoiceLineType::Milestone, DocumentSection::Adjustments];
    }

    #[DataProvider('mapping')]
    public function test_every_line_type_prints_in_its_section(InvoiceLineType $type, DocumentSection $section): void
    {
        $this->assertSame($section, DocumentSection::forType($type));
        $this->assertSame($section, DocumentSection::forStored($type->value));
    }

    public function test_the_mapping_names_every_line_type(): void
    {
        $named = array_map(static fn (array $row): InvoiceLineType => $row[0], iterator_to_array(self::mapping(), false));

        $this->assertEqualsCanonicalizing(InvoiceLineType::cases(), $named);
    }

    public function test_a_stored_type_nobody_knows_still_prints_under_adjustments(): void
    {
        $this->assertSame(DocumentSection::Adjustments, DocumentSection::forStored('retired_type'));
    }

    public function test_sections_have_titles_in_print_order(): void
    {
        $this->assertSame(
            ['Time', 'Recurring services', 'Expenses', 'Deferred work', 'Adjustments and credits'],
            array_map(static fn (DocumentSection $section): string => $section->title(), DocumentSection::cases()),
        );
    }

    public function test_grouping_follows_section_order_leaves_out_empty_sections_and_keeps_line_order(): void
    {
        $lines = [
            $this->line('c1', 'credit', total: -500),
            $this->line('e1', 'expense', total: 4120),
            $this->line('t1', 'additional_hours', total: 3750, tax: 500),
            $this->line('x1', 'retired_type', total: 100),
            $this->line('t2', 'retainer', total: 150000),
        ];

        $blocks = InvoiceDocumentGrouper::group($lines);

        $this->assertSame(
            [['time', ['t1', 't2']], ['expenses', ['e1']], ['adjustments', ['c1', 'x1']]],
            array_map(static fn (InvoiceDocumentSectionBlock $block): array => [
                $block->section->value,
                array_map(static fn (InvoiceDocumentLine $line): string => $line->id, $block->lines),
            ], $blocks),
        );
        $this->assertSame(153750, $blocks[0]->subtotal());
        $this->assertSame(500, $blocks[0]->tax());
        $this->assertSame(-400, $blocks[2]->subtotal());
        $this->assertSame([], InvoiceDocumentGrouper::group([]));
    }

    public function test_a_block_serialises_with_its_title_and_figures(): void
    {
        $block = new InvoiceDocumentSectionBlock(DocumentSection::Expenses, [$this->line('e1', 'expense', total: 4120, tax: 20)]);

        $array = $block->toArray();

        $this->assertSame('expenses', $array['key']);
        $this->assertSame('Expenses', $array['title']);
        $this->assertSame(4120, $array['subtotal']);
        $this->assertSame(20, $array['tax']);
        $this->assertSame('Expense', $array['lines'][0]['type_label']);
        $this->assertSame(
            ['id', 'type', 'type_label', 'description', 'quantity', 'hours', 'line_date', 'unit_amount', 'tax_amount', 'total_amount'],
            array_keys($array['lines'][0]),
        );
    }

    public function test_figures_print_as_the_reader_expects(): void
    {
        $this->assertSame('1,500.00', InvoiceDocumentFormat::money(150000));
        $this->assertSame('-1.50', InvoiceDocumentFormat::money(-150));
        $this->assertSame('0.00', InvoiceDocumentFormat::money(0));
        $this->assertSame('1.50', InvoiceDocumentFormat::minutesAsHours(90));
        $this->assertSame('0.97', InvoiceDocumentFormat::minutesAsHours(58));
        $this->assertSame('1 entry', InvoiceDocumentFormat::entries(1));
        $this->assertSame('0 entries', InvoiceDocumentFormat::entries(0));
        $this->assertSame('2 entries', InvoiceDocumentFormat::entries(2));
    }

    public function test_stored_values_print_as_sentences(): void
    {
        $this->assertSame('Partially paid', StoredValueLabel::of('partially_paid'));
        $this->assertSame('Prior month retainer', StoredValueLabel::of('prior-month_retainer'));
        $this->assertSame('—', StoredValueLabel::of(''));
        $this->assertSame('—', StoredValueLabel::of('__'));
    }

    public function test_a_proforma_is_stamped_and_an_invoice_is_not(): void
    {
        $this->assertNull(DocumentMode::Invoice->stamp());
        $this->assertSame('PROFORMA · NOT AN INVOICE', DocumentMode::Proforma->stamp());
        $this->assertSame('Invoice', DocumentMode::Invoice->heading());
        $this->assertSame('Proforma invoice', DocumentMode::Proforma->heading());
    }

    private function line(string $id, string $type, int $total, int $tax = 0): InvoiceDocumentLine
    {
        return new InvoiceDocumentLine($id, $type, 'Synthetic '.$id, '1.0000', null, null, $total, $tax, $total);
    }
}
