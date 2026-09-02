import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientShow from '@/pages/clients/show';
import { sharedPageProps } from '@/test/shared-page-props';
import type {
    ClientShowProps,
    CompanyAgreement,
    CompanyInvoice,
} from '@/types/clients';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn() },
    // The page renders inside the client chrome now, which reads the shared
    // switcher payload. Supplied here so these stay tests of the Overview
    // content rather than of the layout - `client-context-layout.test.tsx`
    // owns the switcher and the tabs.
    usePage: () => ({
        props: sharedPageProps({
            clientContext: {
                workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
                companies: [{ id: 'company-1', name: 'Synthetic Client' }],
                current_company_id: 'company-1',
                can_manage: false,
            },
        }),
    }),
}));

function agreement(
    overrides: Partial<CompanyAgreement> = {},
): CompanyAgreement {
    return {
        id: 'agreement-1',
        title: 'Synthetic Retainer',
        status: 'active',
        currency: 'USD',
        billing_cadence: 'quarterly',
        is_recurring: true,
        starts_on: '2026-01-01',
        ends_on: '2026-12-31',
        signed_at: null,
        retainer_minutes_per_period: 1800,
        retainer_amount_per_period: 1500000,
        hourly_rate_amount: 20000,
        rollover_months: 3,
        project: null,
        ...overrides,
    };
}

function invoice(overrides: Partial<CompanyInvoice> = {}): CompanyInvoice {
    return {
        id: 'invoice-1',
        invoice_number: 'SYN-001',
        status: 'issued',
        currency: 'USD',
        issue_date: '2026-08-01',
        due_date: '2026-08-31',
        total_amount: 10000,
        paid_amount: 0,
        balance_amount: 10000,
        ...overrides,
    };
}

function props(overrides: Partial<ClientShowProps> = {}): ClientShowProps {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        company: {
            id: 'company-1',
            name: 'Synthetic Client',
            billing_email: 'billing@synthetic.test',
            is_active: true,
        },
        projects: [
            {
                id: 'project-1',
                name: 'Synthetic Project',
                status: 'active',
                is_visible_to_client: true,
            },
        ],
        agreements: [agreement()],
        invoice_limit: 20,
        invoices: [invoice()],
        ...overrides,
    };
}

describe('client detail', () => {
    it('heads the screen with the company', () => {
        render(<ClientShow {...props()} />);

        expect(
            screen.getByRole('heading', { name: 'Synthetic Client' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Billing: billing@synthetic.test'),
        ).toBeInTheDocument();
    });

    it('reads a recurring agreement’s cadence, term and retainer terms', () => {
        render(<ClientShow {...props()} />);

        expect(screen.getByText('Quarterly')).toBeInTheDocument();
        expect(screen.getByText('2026-01-01 → 2026-12-31')).toBeInTheDocument();
        expect(
            screen.getByText('30:00 per period · $15,000.00'),
        ).toBeInTheDocument();
        expect(screen.getByText('$200.00')).toBeInTheDocument();
    });

    it('never presents a one-time agreement as a repeating retainer', () => {
        render(
            <ClientShow
                {...props({
                    agreements: [
                        agreement({
                            billing_cadence: 'one_time',
                            is_recurring: false,
                            retainer_minutes_per_period: null,
                            retainer_amount_per_period: null,
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getByText(/not recurring/)).toBeInTheDocument();
        expect(screen.getByText('None — hourly only')).toBeInTheDocument();
        expect(screen.queryByText(/per period/)).not.toBeInTheDocument();
    });

    it('names the project an agreement is scoped to, and nothing when it is not', () => {
        const { unmount } = render(
            <ClientShow
                {...props({
                    agreements: [agreement({ project: 'Synthetic Project' })],
                })}
            />,
        );

        expect(screen.getByText('Synthetic Project only')).toBeInTheDocument();
        unmount();

        render(<ClientShow {...props()} />);

        expect(screen.queryByText(/ only$/)).not.toBeInTheDocument();
    });

    it('lists invoices with their money in the row’s own currency', () => {
        render(
            <ClientShow
                {...props({
                    invoices: [
                        invoice({
                            currency: 'EUR',
                            total_amount: 25000,
                            balance_amount: 5000,
                        }),
                    ],
                })}
            />,
        );

        const row = screen.getByText('SYN-001').closest('tr');
        expect(row).not.toBeNull();
        expect(
            within(row as HTMLElement).getByText('€250.00'),
        ).toBeInTheDocument();
        expect(
            within(row as HTMLElement).getByText('€50.00'),
        ).toBeInTheDocument();
    });

    it('says the invoice list is bounded only when it is actually full', () => {
        const full = Array.from({ length: 3 }, (_, index) =>
            invoice({
                id: `invoice-${index}`,
                invoice_number: `SYN-00${index}`,
            }),
        );

        const { unmount } = render(
            <ClientShow {...props({ invoice_limit: 3, invoices: full })} />,
        );

        expect(
            screen.getByText(/Showing the 3 most recent invoices/),
        ).toBeInTheDocument();
        unmount();

        render(
            <ClientShow {...props({ invoice_limit: 20, invoices: full })} />,
        );

        expect(
            screen.queryByText(/most recent invoices/),
        ).not.toBeInTheDocument();
    });

    it('renders empty sections without empty tables', () => {
        render(
            <ClientShow
                {...props({ projects: [], agreements: [], invoices: [] })}
            />,
        );

        expect(
            screen.getByText('No projects for this client.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('No agreements for this client.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('No invoices for this client.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
});
