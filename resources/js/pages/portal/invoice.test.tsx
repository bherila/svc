import { render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { documentFixture } from '@/test/invoice-document-fixtures';
import PortalInvoice from './invoice';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/billing/stripe-card-payment', () => ({
    StripeCardPayment: ({ intentHref }: { intentHref: string }) => (
        <button type="button" data-intent={intentHref}>
            Pay by card
        </button>
    ),
}));

function props(
    overrides: Partial<ComponentProps<typeof PortalInvoice>> = {},
    hostile = true,
): ComponentProps<typeof PortalInvoice> {
    return {
        company: { id: 'company-fixture', name: 'Synthetic'.repeat(30) },
        home_href: '/portal/company-fixture',
        document: documentFixture({}, hostile),
        payment: { stripe: null, square: null },
        pdf_url: '/invoices/invoice-fixture/pdf',
        ...overrides,
    };
}

describe('the portal invoice', () => {
    it('renders the document with its sections, hours statement, deferred list and appendix', () => {
        render(<PortalInvoice {...props({}, false)} />);

        for (const name of [
            'Time',
            'Expenses',
            'Hours statement',
            'Still deferred',
        ]) {
            expect(screen.getByRole('heading', { name })).toBeVisible();
        }

        expect(
            screen.getByRole('heading', { name: 'Appendix A: time entries' }),
        ).toBeVisible();
        expect(screen.getByText('Reviewed the roadmap')).toBeVisible();
        expect(screen.getByText(/2 entries · 3.5000 hours/)).toBeVisible();
        expect(
            screen.getByText(/Entries shown as “Professional services”/),
        ).toBeVisible();
    });

    it('offers Square when the server supplies its hosted page, and says Pay in Square', () => {
        const url = 'https://squareupsandbox.com/pay-invoice/inv_fixture_1';
        render(
            <PortalInvoice
                {...props({ payment: { stripe: null, square: { href: url } } })}
            />,
        );

        const rail = screen.getByRole('complementary', {
            name: 'Invoice actions',
        });

        expect(
            within(rail).getByRole('link', { name: 'Pay in Square' }),
        ).toHaveAttribute('href', url);
        expect(
            within(rail).queryByRole('button', { name: 'Pay by card' }),
        ).toBeNull();
        expect(
            within(rail).getByRole('link', { name: 'Download PDF' }),
        ).toHaveAttribute('href', '/invoices/invoice-fixture/pdf');
    });

    it('offers card payment through the server-supplied Stripe route', () => {
        render(
            <PortalInvoice
                {...props({
                    payment: {
                        stripe: {
                            intent_href:
                                '/workspaces/ws/invoices/inv/stripe-payment-intent',
                            publishable_key: 'pk_test_synthetic',
                        },
                        square: null,
                    },
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Pay by card' }),
        ).toHaveAttribute(
            'data-intent',
            '/workspaces/ws/invoices/inv/stripe-payment-intent',
        );
    });

    it('offers both when the workspace has both, and nothing when it has neither', () => {
        const { unmount } = render(
            <PortalInvoice
                {...props({
                    payment: {
                        stripe: {
                            intent_href: '/pay',
                            publishable_key: 'pk_test_synthetic',
                        },
                        square: { href: 'https://squareup.com/pay-invoice/x' },
                    },
                })}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Pay by card' }),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Pay in Square' }),
        ).toBeVisible();
        unmount();

        render(<PortalInvoice {...props()} />);
        expect(
            screen.queryByRole('button', { name: 'Pay by card' }),
        ).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Pay in Square' }),
        ).toBeNull();
    });

    it('shows the balance in the rail and contains oversized identity strings', () => {
        const { container } = render(<PortalInvoice {...props()} />);

        const rail = screen.getByRole('complementary', {
            name: 'Invoice actions',
        });

        expect(within(rail).getByText(/Balance due/)).toBeVisible();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('shows a paid invoice as paid, with nothing left to pay', () => {
        const document = documentFixture({
            status: 'paid',
            totals: {
                subtotal: 100,
                tax: 0,
                total: 100,
                paid: 100,
                balance: 0,
            },
        });
        render(<PortalInvoice {...props({ document })} />);

        const rail = screen.getByRole('complementary', {
            name: 'Invoice actions',
        });

        // The status and the figure's caption both say Paid.
        expect(within(rail).getAllByText('Paid')).toHaveLength(2);
        expect(within(rail).queryByText(/Balance due/)).toBeNull();
    });
});
