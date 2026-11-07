import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    documentFixture,
    receiptsFixture,
} from '@/test/invoice-document-fixtures';
import { InvoiceReceiptsCard } from './invoice-rail';

describe('the receipts card', () => {
    it('lists each expense with a link to every receipt the server gave an address for', () => {
        render(
            <InvoiceReceiptsCard
                document={documentFixture({ receipts: receiptsFixture() })}
            />,
        );

        const card = screen.getByLabelText('Expense receipts');
        expect(within(card).getByText('Expenses · 3 receipts')).toBeVisible();
        // Two files on the first expense, so each gets its own reference.
        expect(
            within(card).getByRole('link', {
                name: 'Open receipt B1.1: staging-database-august.png',
            }),
        ).toHaveAttribute('href', '/workspaces/ws/attachments/receipt-1');
        expect(
            within(card).getByRole('link', {
                name: 'Open receipt B1.2: staging-database-august.pdf',
            }),
        ).toHaveAttribute('target', '_blank');
        // The server withheld the address for B2: named, not linked.
        expect(within(card).getByText('B2')).toBeVisible();
        expect(within(card).queryByRole('link', { name: /B2/ })).toBeNull();
        // And an expense without a receipt says so.
        expect(within(card).getByText('None')).toBeVisible();
        expect(within(card).getByText('Appendix B of the PDF')).toBeVisible();
    });

    it('says "1 receipt" in the singular', () => {
        const receipts = receiptsFixture();
        render(
            <InvoiceReceiptsCard
                document={documentFixture({
                    receipts: {
                        ...receipts,
                        receipt_count: 1,
                        entries: [receipts.entries[1]],
                    },
                })}
            />,
        );

        expect(screen.getByText('Expenses · 1 receipt')).toBeVisible();
    });

    it('draws nothing when the viewer may be told of no receipt', () => {
        const { container } = render(
            <InvoiceReceiptsCard document={documentFixture()} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('contains oversized descriptions beside the links', () => {
        const { container } = render(
            <InvoiceReceiptsCard
                document={documentFixture(
                    { receipts: receiptsFixture(true) },
                    true,
                )}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
