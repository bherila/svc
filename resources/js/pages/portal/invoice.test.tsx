import { render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import PortalInvoice from './invoice';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

function props(): ComponentProps<typeof PortalInvoice> {
    return {
        company: { id: 'company-fixture', name: 'Synthetic'.repeat(30) },
        home_href: '/portal/company-fixture',
        invoice: {
            id: 'invoice-fixture',
            invoice_number: 'SYNTHETIC'.repeat(30),
            status: 'issued',
            currency: 'USD',
            issue_date: null,
            due_date: null,
            service_period_start: null,
            service_period_end: null,
            subtotal_amount: 12_345,
            tax_amount: 0,
            total_amount: 12_345,
            paid_amount: 0,
            balance_amount: 12_345,
            pdf_url: '/invoices/invoice-fixture/pdf',
            deferred_work: null,
        },
        lines: [],
        line_detail: {},
    };
}

describe('a linked portal invoice', () => {
    it('renders the finished Square URL and contains oversized identity strings', () => {
        const url = 'https://squareupsandbox.com/pay-invoice/inv_fixture_1';
        const { container } = render(
            <PortalInvoice {...props()} square_page_url={url} />,
        );

        expect(
            screen.getByRole('link', { name: 'View or pay in Square' }),
        ).toHaveAttribute('href', url);
        expect(
            screen.getByRole('link', { name: 'Download PDF' }),
        ).toBeVisible();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('offers no Square link when the server supplies no destination', () => {
        render(<PortalInvoice {...props()} square_page_url={null} />);
        expect(
            screen.queryByRole('link', { name: 'View or pay in Square' }),
        ).toBeNull();
    });

    it('shows the remaining deferred entries when the server supplies a snapshot', () => {
        render(
            <PortalInvoice
                {...props()}
                invoice={{
                    ...props().invoice,
                    deferred_work: {
                        entry_count: 2,
                        total_hours: 3,
                        oldest_worked_on: '2026-04-01',
                        entries: [
                            {
                                worked_on: '2026-04-01',
                                hours: 1,
                                description: null,
                            },
                            {
                                worked_on: '2026-04-02',
                                hours: 2,
                                description: 'Client-safe work',
                            },
                        ],
                    },
                }}
            />,
        );

        expect(screen.getByText('Still deferred')).toBeVisible();
        expect(screen.getByText(/2 entries/)).toBeVisible();
        expect(screen.getByText('Client-safe work')).toBeVisible();
        expect(screen.getByText('—')).toBeVisible();
    });
});
