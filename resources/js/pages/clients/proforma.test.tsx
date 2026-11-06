import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { documentFixture } from '@/test/invoice-document-fixtures';
import ClientProforma from './proforma';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const company = { id: 'company-fixture', name: 'Synthetic client' };

describe('the proforma invoice', () => {
    it('draws the next invoice through the document, stamped as not an invoice and with no pay control', () => {
        render(
            <ClientProforma
                company={company}
                agreement_title="Synthetic retainer"
                period_start="2026-10-01"
                reason={null}
                document={documentFixture({
                    mode: 'proforma',
                    heading: 'Proforma invoice',
                    stamp: 'PROFORMA · NOT AN INVOICE',
                    invoice_number: null,
                    status: 'draft',
                })}
            />,
        );

        expect(
            screen.getAllByRole('heading', { name: 'Proforma invoice' }),
        ).toHaveLength(2);
        expect(screen.getByText('PROFORMA · NOT AN INVOICE')).toBeVisible();
        expect(
            screen.getByText(/Synthetic retainer · period starting/),
        ).toBeVisible();
        expect(screen.queryByRole('button', { name: /pay/i })).toBeNull();
        expect(screen.queryByRole('link', { name: /pay/i })).toBeNull();
    });

    it('says why when there is nothing to preview', () => {
        render(
            <ClientProforma
                company={company}
                agreement_title="Synthetic retainer"
                period_start={null}
                document={null}
                reason="The current period already has an invoice, so there is nothing left to preview."
            />,
        );

        expect(screen.getByText(/already has an invoice/)).toBeVisible();
        expect(screen.queryByText('PROFORMA · NOT AN INVOICE')).toBeNull();
    });

    it('contains oversized agreement titles and document text', () => {
        const { container } = render(
            <ClientProforma
                company={company}
                agreement_title={'Retainer'.repeat(40)}
                period_start="2026-10-01"
                reason={null}
                document={documentFixture(
                    { mode: 'proforma', stamp: 'PROFORMA · NOT AN INVOICE' },
                    true,
                )}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
