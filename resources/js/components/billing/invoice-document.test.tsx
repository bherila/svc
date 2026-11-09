import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    documentFixture,
    receiptsFixture,
} from '@/test/invoice-document-fixtures';
import { InvoiceDocument } from './invoice-document';

describe('the invoice document', () => {
    it('prints each section with its lines and a subtotal where there are several', () => {
        render(<InvoiceDocument document={documentFixture()} />);

        expect(screen.getByRole('heading', { name: 'Time' })).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Expenses' })).toBeVisible();
        expect(screen.getByText('Time subtotal')).toBeVisible();
        // One line in a section needs no subtotal beneath it.
        expect(screen.queryByText('Expenses subtotal')).toBeNull();
        // On its line, and again as the appendix block of the work behind it.
        expect(screen.getAllByText('Additional hours for August')).toHaveLength(
            2,
        );
        expect(screen.getAllByText('Additional hours').length).toBeGreaterThan(
            0,
        );
        // Stored quantity "2.5000" reads as 2.5.
        expect(screen.getByText('2.5')).toBeVisible();
    });

    it('prints the totals, the hours statement, the deferred list and the appendix', () => {
        render(<InvoiceDocument document={documentFixture()} />);

        const totals = screen.getByText('Subtotal').closest('dl');
        expect(totals).not.toBeNull();
        expect(
            within(totals as HTMLElement).getByText('$2,337.00'),
        ).toBeVisible();
        expect(
            within(totals as HTMLElement).getByText('$1,837.00'),
        ).toBeVisible();

        expect(
            screen.getByText('Net hours available at the start of August 2026'),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Unused hours that expired at the start of August 2026',
            ),
        ).toBeVisible();
        expect(screen.getByText(/2 entries · 3.5000 hours/)).toBeVisible();
        expect(screen.getByText('2 entries')).toBeVisible();
        // 90 + 60 minutes, as the invoice always prints them: decimal hours.
        expect(screen.getByText('2.5 h')).toBeVisible();
        expect(screen.getByText('1.5 h')).toBeVisible();
    });

    it('tags work a client dispute holds out of billing in the deferred list', () => {
        render(<InvoiceDocument document={documentFixture()} />);

        const tags = screen.getAllByText('Disputed');
        expect(tags).toHaveLength(1);
        // On the row of the disputed entry, which has no client description.
        expect(within(tags[0].closest('tr')!).getByText('—')).toBeVisible();
    });

    it('leaves out the parts the invoice does not have', () => {
        render(
            <InvoiceDocument
                document={documentFixture({
                    statement: null,
                    deferred_work: null,
                    appendix: null,
                })}
            />,
        );

        expect(screen.queryByText('Hours statement')).toBeNull();
        expect(screen.queryByText('Still deferred')).toBeNull();
        expect(screen.queryByText(/Appendix A/)).toBeNull();
    });

    it('says what a void or reissued invoice is, and stamps a proforma', () => {
        const { rerender } = render(
            <InvoiceDocument
                document={documentFixture({
                    status: 'void',
                    void_notice:
                        'This invoice was voided on 2026-08-20. Nothing is owed on it.',
                    reissue_notice:
                        'Reissued 2026-08-18 under the same invoice number; this replaces the earlier version.',
                })}
            />,
        );
        expect(screen.getByText(/voided on 2026-08-20/)).toBeVisible();
        expect(screen.getByText(/Reissued 2026-08-18/)).toBeVisible();
        expect(screen.queryByText('PROFORMA · NOT AN INVOICE')).toBeNull();

        rerender(
            <InvoiceDocument
                document={documentFixture({
                    mode: 'proforma',
                    heading: 'Proforma invoice',
                    stamp: 'PROFORMA · NOT AN INVOICE',
                    invoice_number: null,
                    status: 'draft',
                })}
            />,
        );
        expect(screen.getByText('PROFORMA · NOT AN INVOICE')).toBeVisible();
        expect(
            screen.getByRole('heading', { name: 'Proforma invoice' }),
        ).toBeVisible();
    });

    it('shows as printed on request: the paper surface, and the sun and moon toggle', () => {
        const { container } = render(
            <InvoiceDocument document={documentFixture()} />,
        );
        const sheet = container.querySelector('article') as HTMLElement;
        const toggle = screen.getByRole('button', { name: 'Show as printed' });

        expect(sheet).toHaveAttribute('data-surface', 'screen');
        expect(sheet).not.toHaveClass('invoice-paper');
        expect(toggle).toHaveAttribute('aria-pressed', 'false');
        expect(toggle.querySelector('svg')).toHaveClass('lucide-sun');
        // Only drawn in dark mode, where the two surfaces differ.
        expect(toggle.parentElement).toHaveClass('hidden', 'dark:flex');

        fireEvent.click(toggle);

        expect(sheet).toHaveAttribute('data-surface', 'paper');
        expect(sheet).toHaveClass('invoice-paper');
        expect(toggle).toHaveAttribute('aria-pressed', 'true');
        expect(toggle.querySelector('svg')).toHaveClass('lucide-moon');
    });

    it('contains oversized identifiers, descriptions and project names', () => {
        const { container } = render(
            <InvoiceDocument document={documentFixture({}, true)} />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
    it('prints Appendix B with each expense, its receipts and a link where the server gave one', () => {
        render(
            <InvoiceDocument
                document={documentFixture({ receipts: receiptsFixture() })}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Appendix B: receipts' }),
        ).toBeVisible();
        expect(
            screen.getByText(/An expense with no receipt on file/),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'staging-database-august.png' }),
        ).toHaveAttribute('href', '/workspaces/ws/attachments/receipt-1');
        // A file the viewer may not open is named and not linked.
        expect(screen.getByText('api-usage.jpg')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: 'api-usage.jpg' }),
        ).toBeNull();
        expect(
            screen.getByText(
                'Not drawn in the PDF; open the file from the invoice online.',
            ),
        ).toBeVisible();
        expect(screen.getByText('No receipt on file')).toBeVisible();
        const appendix = screen
            .getByRole('heading', { name: 'Appendix B: receipts' })
            .closest('#appendix-b') as HTMLElement;
        expect(within(appendix).getByText('$412.00')).toBeVisible();
        expect(within(appendix).getByText('B3')).toBeVisible();
    });

    it('draws no Appendix B, and no empty heading, when there is none', () => {
        render(<InvoiceDocument document={documentFixture()} />);

        expect(screen.queryByText(/Appendix B/)).toBeNull();
    });

    it('contains oversized vendor names and filenames in Appendix B', () => {
        const { container } = render(
            <InvoiceDocument
                document={documentFixture(
                    { receipts: receiptsFixture(true) },
                    true,
                )}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
