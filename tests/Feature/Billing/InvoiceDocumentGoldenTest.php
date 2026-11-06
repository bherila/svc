<?php

namespace Tests\Feature\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Services\Billing\InvoiceDocumentService;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceLineType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Support\Billing\BladeInvoiceDocumentFacts;
use Tests\Support\Billing\InvoiceDocumentFacts;
use Tests\TestCase;

/**
 * Golden tests for the invoice document as it prints today: the masthead, the
 * lines, the totals, the reissue notice and Appendix A, for both audiences.
 *
 * These compare facts ({@see InvoiceDocumentFacts}), never markup, so a rebuilt
 * renderer is checked against the same figures by overriding
 * {@see self::documentFacts()}. The hours statement and "Still deferred" have
 * their own files: {@see InvoiceDocumentHoursStatementGoldenTest} and
 * {@see InvoiceDocumentDeferredWorkGoldenTest}.
 */
class InvoiceDocumentGoldenTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    private const INTERNAL_NOTE = 'Synthetic internal note: chased the finance team';

    /**
     * The label each stored line type prints. A new InvoiceLineType case has no
     * entry here, which fails {@see self::test_every_line_type_prints_its_label()}
     * until someone decides how it should read.
     *
     * @var array<string, string>
     */
    private const LINE_TYPE_LABELS = [
        'retainer' => 'Retainer',
        'prior_month_retainer' => 'Prior month retainer',
        'prior_month_billable' => 'Prior month billable',
        'additional_hours' => 'Additional hours',
        'credit' => 'Credit',
        'expense' => 'Expense',
        'milestone' => 'Milestone',
        'adjustment' => 'Adjustment',
        'recurring_item' => 'Recurring item',
        'reconciliation' => 'Reconciliation',
        'subcontractor' => 'Subcontractor',
        'carried_deferred_applied' => 'Carried deferred applied',
        'carried_deferred_billed' => 'Carried deferred billed',
        'deferred_buydown' => 'Deferred buydown',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
    }

    /** The one place a second renderer plugs in. */
    protected function documentFacts(): InvoiceDocumentFacts
    {
        return new BladeInvoiceDocumentFacts(app(InvoiceDocumentService::class));
    }

    /** @return iterable<string, array{string}> */
    public static function audiences(): iterable
    {
        yield 'operator' => [InvoiceLineDetail::OPERATOR];
        yield 'client' => [InvoiceLineDetail::CLIENT];
    }

    /** @return iterable<string, array{string, string}> */
    public static function lineTypes(): iterable
    {
        foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
            foreach (InvoiceLineType::cases() as $type) {
                yield $audience.' / '.$type->value => [$audience, $type->value];
            }
        }
    }

    #[DataProvider('audiences')]
    public function test_the_masthead_dates_and_header_facts(string $audience): void
    {
        $invoice = $this->goldenInvoice(['invoice_number' => 'INV-SYN-GOLDEN-0001']);

        $facts = $this->documentFacts()->extract($invoice, $audience);

        $this->assertSame('INV-SYN-GOLDEN-0001', $facts['invoice_number']);
        $this->assertSame('Issued', $facts['status']);
        $this->assertSame('USD', $facts['currency']);
        $this->assertSame('Synthetic Golden Client', $facts['bill_to']);
        $this->assertSame('2026-08-15', $facts['issue_date']);
        $this->assertSame('2026-09-14', $facts['due_date']);
        $this->assertSame('2026-08-01 – 2026-08-31', $facts['service_period']);
        $this->assertNull($facts['reissue_notice']);
        $this->assertNull($facts['hours_statement']);
        $this->assertNull($facts['still_deferred']);
        $this->assertNull($facts['appendix']);
    }

    public function test_only_the_operator_copy_carries_the_administrator_banner(): void
    {
        $invoice = $this->goldenInvoice();

        $this->assertSame(
            'Administrator copy: includes internal descriptions',
            $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR)['audience_banner'],
        );
        $this->assertSame('', $this->documentFacts()->extract($invoice, InvoiceLineDetail::CLIENT)['audience_banner']);
    }

    #[DataProvider('audiences')]
    public function test_lines_print_description_type_quantity_unit_tax_and_total(string $audience): void
    {
        $invoice = $this->goldenInvoice([
            'subtotal_amount' => 190000, 'tax_amount' => 5000, 'total_amount' => 195000,
            'paid_amount' => 25000, 'balance_amount' => 170000,
        ]);
        $this->goldenLine($invoice, ['type' => 'retainer', 'description' => 'Synthetic retainer for August 2026', 'quantity' => '1', 'unit_amount' => 150000, 'tax_amount' => 0, 'total_amount' => 150000, 'sort_order' => 1]);
        $this->goldenLine($invoice, ['type' => 'additional_hours', 'description' => 'Additional hours (2.50 hrs @ 150.00 USD/hr)', 'quantity' => '2.5', 'unit_amount' => 15000, 'tax_amount' => 5000, 'total_amount' => 37500, 'sort_order' => 2]);
        $this->goldenLine($invoice, ['type' => 'credit', 'description' => 'Synthetic credit applied', 'quantity' => '1', 'unit_amount' => -5000, 'tax_amount' => 0, 'total_amount' => -5000, 'sort_order' => 3]);

        $facts = $this->documentFacts()->extract($invoice, $audience);

        $this->assertSame([
            ['description' => 'Synthetic retainer for August 2026', 'type' => 'Retainer', 'quantity' => '1.0000', 'unit' => '1,500.00', 'tax' => '0.00', 'total' => '1,500.00'],
            ['description' => 'Additional hours (2.50 hrs @ 150.00 USD/hr)', 'type' => 'Additional hours', 'quantity' => '2.5000', 'unit' => '150.00', 'tax' => '50.00', 'total' => '375.00'],
            ['description' => 'Synthetic credit applied', 'type' => 'Credit', 'quantity' => '1.0000', 'unit' => '-50.00', 'tax' => '0.00', 'total' => '-50.00'],
        ], $facts['lines']);
        $this->assertSame([
            'Subtotal' => '1,900.00', 'Tax' => '50.00', 'Total' => '1,950.00', 'Paid' => '250.00', 'Balance' => '1,700.00',
        ], $facts['totals']);
    }

    public function test_lines_print_in_sort_order_then_id_and_only_this_invoices(): void
    {
        $invoice = $this->goldenInvoice();
        $this->goldenLine($invoice, ['description' => 'Third by sort order', 'sort_order' => 5]);
        $this->goldenLine($invoice, ['description' => 'First by sort order', 'sort_order' => 1]);
        $this->goldenLine($invoice, ['description' => 'Second, tied on sort order, later id', 'sort_order' => 1]);
        $other = $this->goldenInvoice();
        $this->goldenLine($other, ['description' => 'Another invoice line']);

        $descriptions = array_column($this->documentFacts()->extract($invoice, InvoiceLineDetail::CLIENT)['lines'], 'description');

        $this->assertSame(['First by sort order', 'Second, tied on sort order, later id', 'Third by sort order'], $descriptions);
    }

    #[DataProvider('audiences')]
    public function test_a_paid_invoice_prints_its_status_and_zero_balance(string $audience): void
    {
        $invoice = $this->goldenInvoice([
            'status' => 'paid', 'subtotal_amount' => 100000, 'total_amount' => 100000, 'paid_amount' => 100000, 'balance_amount' => 0,
        ]);

        $facts = $this->documentFacts()->extract($invoice, $audience);

        $this->assertSame('Paid', $facts['status']);
        $this->assertSame('1,000.00', $facts['totals']['Paid']);
        $this->assertSame('0.00', $facts['totals']['Balance']);
    }

    #[DataProvider('lineTypes')]
    public function test_every_line_type_prints_its_label(string $audience, string $type): void
    {
        $this->assertArrayHasKey(
            $type,
            self::LINE_TYPE_LABELS,
            "InvoiceLineType::{$type} has no golden fixture: add its printed label to LINE_TYPE_LABELS.",
        );
        $invoice = $this->goldenInvoice();
        $this->goldenLine($invoice, [
            'type' => $type, 'description' => 'Synthetic '.$type.' line', 'quantity' => '2', 'unit_amount' => 10000,
            'tax_amount' => 500, 'total_amount' => 20500,
        ]);

        $lines = $this->documentFacts()->extract($invoice, $audience)['lines'];

        $this->assertSame([[
            'description' => 'Synthetic '.$type.' line', 'type' => self::LINE_TYPE_LABELS[$type], 'quantity' => '2.0000',
            'unit' => '100.00', 'tax' => '5.00', 'total' => '205.00',
        ]], $lines);
    }

    public function test_the_line_type_fixture_map_names_no_retired_case(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(static fn (InvoiceLineType $type): string => $type->value, InvoiceLineType::cases()),
            array_keys(self::LINE_TYPE_LABELS),
        );
    }

    #[DataProvider('audiences')]
    public function test_a_reissued_invoice_prints_the_reissue_notice(string $audience): void
    {
        $invoice = $this->goldenInvoice(['reissued_at' => '2026-08-20 09:30:00']);

        $facts = $this->documentFacts()->extract($invoice, $audience);

        $this->assertSame('Reissued 2026-08-20 under the same invoice number; this replaces the earlier version.', $facts['reissue_notice']);
    }

    /** @return array{0: ClientInvoice, 1: ClientInvoiceLine, 2: ClientInvoiceLine} */
    private function invoiceWithAppendix(): array
    {
        $second = $this->goldenProject('Synthetic Second Project');
        $invoice = $this->goldenInvoice();
        $retainer = $this->goldenLine($invoice, ['description' => 'Synthetic retainer sold for September', 'sort_order' => 1]);
        $hours = $this->goldenLine($invoice, ['type' => 'additional_hours', 'description' => 'Additional hours (3.75 hrs)', 'quantity' => '3.75', 'sort_order' => 2]);
        $prior = $this->goldenLine($invoice, ['type' => 'prior_month_billable', 'description' => 'Prior month billable', 'sort_order' => 3]);

        // Internal wording only, so a client reads the neutral label.
        $this->goldenAttach($hours, $this->goldenEntry('2026-08-03', 90, ['description' => self::INTERNAL_NOTE]));
        // Written for the client and visible to them.
        $this->goldenAttach($hours, $this->goldenEntry('2026-08-04', 60, [
            'client_project_id' => $second->id, 'is_visible_to_client' => true, 'client_visible_description' => 'Reconciled the purchase order',
        ]));
        // Visible but blank wording: the neutral label.
        $this->goldenAttach($hours, $this->goldenEntry('2026-08-05', 30, ['is_visible_to_client' => true, 'client_visible_description' => '   ']));
        // Wording written, but the entry is not visible to the client: the neutral label.
        $this->goldenAttach($hours, $this->goldenEntry('2026-08-06', 45, ['client_visible_description' => 'Synthetic hidden wording']));
        $this->goldenAttach($prior, $this->goldenEntry('2026-07-30', 120, [
            'is_visible_to_client' => true, 'client_visible_description' => 'Quarterly planning workshop',
        ]));

        return [$invoice, $retainer, $hours];
    }

    public function test_appendix_a_for_the_operator_lists_every_entry_with_its_internal_note(): void
    {
        [$invoice] = $this->invoiceWithAppendix();

        $appendix = $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR)['appendix'];

        $this->assertSame('Grouped by the invoice line each entry was billed on.', $appendix['notice'] ?? null);
        $this->assertSame([
            [
                'description' => 'Additional hours (3.75 hrs)',
                'entries' => [
                    ['date' => '2026-08-03', 'project' => 'Synthetic Golden Project', 'work' => self::INTERNAL_NOTE, 'hours' => '1.50'],
                    ['date' => '2026-08-04', 'project' => 'Synthetic Second Project', 'work' => 'Synthetic internal note', 'hours' => '1.00'],
                    ['date' => '2026-08-05', 'project' => 'Synthetic Golden Project', 'work' => 'Synthetic internal note', 'hours' => '0.50'],
                    ['date' => '2026-08-06', 'project' => 'Synthetic Golden Project', 'work' => 'Synthetic internal note', 'hours' => '0.75'],
                ],
                'subtotal' => ['label' => '4 entries', 'hours' => '3.75'],
            ],
            [
                'description' => 'Prior month billable',
                'entries' => [
                    ['date' => '2026-07-30', 'project' => 'Synthetic Golden Project', 'work' => 'Synthetic internal note', 'hours' => '2.00'],
                ],
                'subtotal' => ['label' => '1 entry', 'hours' => '2.00'],
            ],
        ], $appendix['lines'] ?? null);
    }

    public function test_appendix_a_for_the_client_never_prints_an_internal_note(): void
    {
        [$invoice] = $this->invoiceWithAppendix();

        $appendix = $this->documentFacts()->extract($invoice, InvoiceLineDetail::CLIENT)['appendix'];

        $this->assertSame([
            [
                'description' => 'Additional hours (3.75 hrs)',
                'entries' => [
                    ['date' => '2026-08-03', 'project' => 'Synthetic Golden Project', 'work' => 'Professional services', 'hours' => '1.50'],
                    ['date' => '2026-08-04', 'project' => 'Synthetic Second Project', 'work' => 'Reconciled the purchase order', 'hours' => '1.00'],
                    ['date' => '2026-08-05', 'project' => 'Synthetic Golden Project', 'work' => 'Professional services', 'hours' => '0.50'],
                    ['date' => '2026-08-06', 'project' => 'Synthetic Golden Project', 'work' => 'Professional services', 'hours' => '0.75'],
                ],
                'subtotal' => ['label' => '4 entries', 'hours' => '3.75'],
            ],
            [
                'description' => 'Prior month billable',
                'entries' => [
                    ['date' => '2026-07-30', 'project' => 'Synthetic Golden Project', 'work' => 'Quarterly planning workshop', 'hours' => '2.00'],
                ],
                'subtotal' => ['label' => '1 entry', 'hours' => '2.00'],
            ],
        ], $appendix['lines'] ?? null);

        $html = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT)->render();
        foreach ([self::INTERNAL_NOTE, 'Synthetic internal note', 'Synthetic hidden wording'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_the_generic_label_notice_appears_for_the_client_only_when_it_applies(): void
    {
        [$invoice] = $this->invoiceWithAppendix();
        $notice = 'Grouped by the invoice line each entry was billed on. Entries shown as “Professional services” are billed work without a client-facing description.';

        $this->assertSame($notice, $this->documentFacts()->extract($invoice, InvoiceLineDetail::CLIENT)['appendix']['notice'] ?? null);
        // The operator reads the internal notes, so the notice has nothing to explain.
        $this->assertStringNotContainsString(
            'Professional services',
            $this->documentFacts()->extract($invoice, InvoiceLineDetail::OPERATOR)['appendix']['notice'] ?? '',
        );

        $written = $this->goldenInvoice();
        $line = $this->goldenLine($written, ['type' => 'additional_hours', 'description' => 'Additional hours']);
        $this->goldenAttach($line, $this->goldenEntry('2026-08-03', 60, ['is_visible_to_client' => true, 'client_visible_description' => 'Reviewed the contract']));

        $this->assertSame(
            'Grouped by the invoice line each entry was billed on.',
            $this->documentFacts()->extract($written, InvoiceLineDetail::CLIENT)['appendix']['notice'] ?? null,
        );
    }

    #[DataProvider('audiences')]
    public function test_a_line_with_no_work_behind_it_has_no_appendix_block(string $audience): void
    {
        [$invoice] = $this->invoiceWithAppendix();

        $descriptions = array_column($this->documentFacts()->extract($invoice, $audience)['appendix']['lines'] ?? [], 'description');

        $this->assertNotContains('Synthetic retainer sold for September', $descriptions);
    }
}
