import type {
    InvoiceDocumentData,
    InvoiceReceiptEntry,
} from '@/components/billing/invoice-document';

/**
 * A synthetic invoice document as the server builds it, with every optional
 * part present: hours statement, still-deferred list and Appendix A. Pass
 * `hostile` to make every free-text value an unbreakable run, which is what
 * the layout checks need.
 */
export function documentFixture(
    overrides: Partial<InvoiceDocumentData> = {},
    hostile = false,
): InvoiceDocumentData {
    const long = (word: string) => (hostile ? word.repeat(30) : word);

    return {
        mode: 'invoice',
        heading: 'Invoice',
        stamp: null,
        audience: 'client',
        banner: '',
        issuer_name: long('Synthetic workspace'),
        invoice_number: long('INV-SYN-1041'),
        status: 'issued',
        currency: 'USD',
        bill_to: long('Synthetic client'),
        issue_date: '2026-08-15',
        due_date: '2026-09-14',
        service_period_start: '2026-08-01',
        service_period_end: '2026-08-31',
        reissue_notice: null,
        void_notice: null,
        sections: [
            {
                key: 'time',
                title: 'Time',
                subtotal: 187_500,
                tax: 5_000,
                lines: [
                    {
                        id: 'line-1',
                        type: 'retainer',
                        type_label: 'Retainer',
                        description: long('Retainer for September 2026'),
                        quantity: '1.0000',
                        hours: null,
                        line_date: '2026-09-01',
                        unit_amount: 150_000,
                        tax_amount: 0,
                        total_amount: 150_000,
                    },
                    {
                        id: 'line-2',
                        type: 'additional_hours',
                        type_label: 'Additional hours',
                        description: long('Additional hours for August'),
                        quantity: '2.5000',
                        hours: 2.5,
                        line_date: null,
                        unit_amount: 15_000,
                        tax_amount: 5_000,
                        total_amount: 37_500,
                    },
                ],
            },
            {
                key: 'expenses',
                title: 'Expenses',
                subtotal: 41_200,
                tax: 0,
                lines: [
                    {
                        id: 'line-3',
                        type: 'expense',
                        type_label: 'Expense',
                        description: long('Staging database'),
                        quantity: '1.0000',
                        hours: null,
                        line_date: null,
                        unit_amount: 41_200,
                        tax_amount: 0,
                        total_amount: 41_200,
                    },
                ],
            },
        ],
        totals: {
            subtotal: 228_700,
            tax: 5_000,
            total: 233_700,
            paid: 50_000,
            balance: 183_700,
        },
        statement: {
            intro: 'For the work this invoice reconciles and the retainer period that follows it. All figures are hours.',
            sections: [
                {
                    title: 'Opening pool: August 2026',
                    rows: [
                        {
                            label: long('Retainer hours for August 2026'),
                            hours: '10.00',
                            kind: 'row',
                        },
                        {
                            label: 'Applied to the August 2026 pool',
                            hours: '12.50',
                            kind: 'detail',
                        },
                        {
                            label: 'Net hours available at the start of August 2026',
                            hours: '12.50',
                            kind: 'total',
                        },
                        {
                            label: 'Unused hours that expired at the start of August 2026',
                            hours: '2.00',
                            kind: 'note',
                        },
                    ],
                },
            ],
        },
        deferred_work: {
            entry_count: 2,
            total_hours: 3.5,
            oldest_worked_on: '2026-06-01',
            entries: [
                {
                    worked_on: '2026-06-01',
                    hours: 1.5,
                    description: long('Reviewed the roadmap'),
                },
                { worked_on: '2026-06-15', hours: 2, description: null },
            ],
        },
        appendix: {
            generic_label_used: true,
            generic_label: 'Professional services',
            blocks: [
                {
                    line_id: 'line-2',
                    description: long('Additional hours for August'),
                    minutes: 150,
                    entries: [
                        {
                            worked_on: '2026-08-03',
                            project: long('Synthetic project'),
                            description: long('Reconciled the purchase order'),
                            minutes: 90,
                        },
                        {
                            worked_on: '2026-08-04',
                            project: null,
                            description: 'Professional services',
                            minutes: 60,
                        },
                    ],
                },
            ],
        },
        receipts: null,
        pay_url: null,
        ...overrides,
    };
}

/**
 * Appendix B as the server builds it: one expense with an image and a PDF the
 * viewer may open, one whose file it may not (a null address), and one with no
 * receipt. Pass `hostile` for unbroken names.
 */
export function receiptsFixture(
    hostile = false,
): NonNullable<InvoiceDocumentData['receipts']> {
    const long = (word: string) => (hostile ? word.repeat(30) : word);
    const entries: InvoiceReceiptEntry[] = [
        {
            ref: 'B1',
            line_id: 'line-3',
            spent_on: '2026-08-12',
            description: long('Staging database'),
            amount: 41_200,
            files: [
                {
                    id: 'receipt-1',
                    filename: long('staging-database-august.png'),
                    media_type: 'image/png',
                    bytes: 52_000,
                    href: '/workspaces/ws/attachments/receipt-1',
                    note: null,
                },
                {
                    id: 'receipt-2',
                    filename: long('staging-database-august.pdf'),
                    media_type: 'application/pdf',
                    bytes: 90_000,
                    href: '/workspaces/ws/attachments/receipt-2',
                    note: 'Not drawn in the PDF; open the file from the invoice online.',
                },
            ],
        },
        {
            ref: 'B2',
            line_id: 'line-3',
            spent_on: '2026-08-20',
            description: long('Lab-results API usage'),
            amount: 9_900,
            files: [
                {
                    id: 'receipt-3',
                    filename: long('api-usage.jpg'),
                    media_type: 'image/jpeg',
                    bytes: 30_000,
                    href: null,
                    note: null,
                },
            ],
        },
        {
            ref: 'B3',
            line_id: 'line-3',
            spent_on: '2026-08-25',
            description: 'Parking',
            amount: 1_500,
            files: [],
        },
    ];

    return {
        intro: 'The expenses billed on this invoice, with the receipt filed for each. An expense with no receipt on file is listed without one.',
        receipt_count: 3,
        entries,
    };
}
