import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import PortalHome from '@/pages/portal/home';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { PortalHomeProps } from '@/types/portal';

const style = vi.hoisted(() => ({ value: 'decimal' as 'decimal' | 'clock' }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: vi.fn() },
    usePage: () => {
        const shared = sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        });

        return {
            props: {
                ...shared,
                auth: {
                    user: { ...shared.auth.user!, duration_style: style.value },
                },
            },
        };
    },
}));

const LONG = 'Unbroken'.repeat(12);

function props(overrides: Partial<PortalHomeProps> = {}): PortalHomeProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        balance: {
            amount_due: 920000,
            currency: 'USD',
            invoice_count: 1,
            overdue: false,
            overdue_amount: 0,
            first: {
                id: 'invoice-1',
                number: 'SYN-1041',
                balance: 920000,
                currency: 'USD',
                due_date: '2026-11-15',
                days_until_due: 9,
            },
        },
        last_payment: {
            amount: 880000,
            currency: 'USD',
            received_on: '2026-10-12',
        },
        pay: {
            href: 'https://pay.example.test/invoice-1',
            invoice_href: '/portal/company-1/invoices/invoice-1',
        },
        this_period: {
            agreement: {
                id: 'agreement-1',
                title: 'Synthetic retainer',
                href: '/portal/company-1/agreements/agreement-1',
            },
            period_start: '2026-11-01',
            period_end: '2026-11-30',
            use: {
                minutes_used: 1890,
                minutes_included: 2400,
                rollover_minutes: 360,
                overage_minutes: 0,
            },
            next_invoice: {
                period_start: '2026-12-01',
                period_end: '2026-12-31',
                currency: 'USD',
                total: 815000,
                parts: [
                    {
                        kind: 'retainer',
                        description: 'Retainer',
                        amount: 800000,
                        date: '2026-12-01',
                        hours: null,
                    },
                    {
                        kind: 'recurring_item',
                        description: 'Web hosting',
                        amount: 15000,
                        date: '2026-12-01',
                        hours: null,
                    },
                    {
                        kind: 'overage',
                        description: 'Hours past the retainer so far',
                        amount: null,
                        date: null,
                        hours: 1.5,
                    },
                ],
                excludes: 'tax and expenses',
            },
            still_deferred: null,
        },
        recent_invoices: [
            {
                id: 'invoice-1',
                invoice_number: 'SYN-1041',
                status: 'partially_paid',
                currency: 'USD',
                issue_date: '2026-10-01',
                due_date: '2026-11-15',
                total_amount: 920000,
                paid_amount: 0,
                balance_amount: 920000,
                href: '/portal/company-1/invoices/invoice-1',
            },
        ],
        recent_time: [
            {
                id: 'time-1',
                worked_on: '2026-11-02',
                project: 'Synthetic Project',
                description: 'Synthetic client summary',
                minutes: 150,
            },
        ],
        open_tasks: [
            {
                id: 'task-1',
                title: 'Confirm the synthetic list',
                project: 'Synthetic Project',
                status: 'in_progress',
            },
        ],
        engagement: null,
        links: {
            invoices: '/portal/company-1/invoices',
            time: '/portal/company-1/time',
            tasks: '/portal/company-1/tasks',
        },
        ...overrides,
    };
}

describe('portal home', () => {
    it('puts what is owed first, with the invoice, the pay link and the last payment', () => {
        render(<PortalHome {...props()} />);

        // The headline, then the invoice history row's total and balance.
        expect(screen.getAllByText('$9,200.00')).toHaveLength(3);
        expect(
            screen.getByText(/Invoice SYN-1041 · due .* \(in 9 days\)/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Pay $9,200.00, invoice SYN-1041',
            }),
        ).toHaveAttribute('href', 'https://pay.example.test/invoice-1');
        expect(
            screen.getByRole('link', { name: /View invoice/ }),
        ).toHaveAttribute('href', '/portal/company-1/invoices/invoice-1');
        expect(
            screen.getByText(/Last payment \$8,800\.00/),
        ).toBeInTheDocument();
    });

    it('flags an overdue balance in words, not colour alone', () => {
        render(
            <PortalHome
                {...props({
                    balance: {
                        ...props().balance,
                        overdue: true,
                        overdue_amount: 920000,
                        first: {
                            ...props().balance.first!,
                            days_until_due: -3,
                        },
                    },
                })}
            />,
        );

        expect(screen.getByText(/Overdue: \$9,200\.00/)).toBeInTheDocument();
        expect(screen.getByText(/\(3 days overdue\)/)).toBeInTheDocument();
    });

    it('offers no Pay button when the invoice has no payment page', () => {
        render(
            <PortalHome
                {...props({
                    pay: {
                        href: null,
                        invoice_href: '/portal/company-1/invoices/invoice-1',
                    },
                })}
            />,
        );

        expect(
            screen.queryByRole('link', { name: /^Pay / }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /View invoice/ }),
        ).toBeInTheDocument();
    });

    it('says nothing is due and offers nothing to pay', () => {
        render(
            <PortalHome
                {...props({
                    balance: {
                        amount_due: 0,
                        currency: null,
                        invoice_count: 0,
                        overdue: false,
                        overdue_amount: 0,
                        first: null,
                    },
                    pay: { href: null, invoice_href: null },
                    last_payment: null,
                })}
            />,
        );

        expect(screen.getByText('Nothing due')).toBeInTheDocument();
        expect(
            screen.getByText('No payments received yet.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Pay/ })).toBeNull();
    });

    it('withholds a sum across currencies', () => {
        render(
            <PortalHome
                {...props({
                    balance: {
                        ...props().balance,
                        amount_due: null,
                        currency: null,
                        invoice_count: 2,
                    },
                })}
            />,
        );

        expect(screen.getByText('Several currencies')).toBeInTheDocument();
    });

    it('shows hours used against hours available including rollover, and the estimate with its parts', () => {
        render(<PortalHome {...props()} />);

        // 31.5 h used of 2400 + 360 minutes = 46 h available.
        expect(screen.getByText('31.5 h / 46 h')).toBeInTheDocument();
        expect(
            screen.getByText('Includes 6 h rolled over.'),
        ).toBeInTheDocument();

        const estimate = screen.getByRole('region', {
            name: 'Next invoice (estimate)',
        });

        expect(within(estimate).getByText('$8,150.00')).toBeInTheDocument();
        expect(within(estimate).getByText('Web hosting')).toBeInTheDocument();
        expect(
            within(estimate).getByText(
                /Hours past the retainer so far \(1\.5 h\)/,
            ),
        ).toBeInTheDocument();
        expect(
            within(estimate).getByText('Priced when billed'),
        ).toBeInTheDocument();
        expect(
            within(estimate).getByText(
                /Before tax and expenses\. An estimate, not an invoice\./,
            ),
        ).toBeInTheDocument();
    });

    it('draws no forecast beyond the next invoice', () => {
        render(<PortalHome {...props()} />);

        expect(screen.queryByText(/12.month|forecast|projected/i)).toBeNull();
    });

    it('teaches what the empty This period card means', () => {
        render(<PortalHome {...props({ this_period: null })} />);

        expect(
            screen.getByText(/Once an agreement is in force/),
        ).toBeInTheDocument();
    });

    it('says when nothing is scheduled for the next invoice', () => {
        const base = props();
        render(
            <PortalHome
                {...props({
                    this_period: { ...base.this_period!, next_invoice: null },
                })}
            />,
        );

        expect(
            screen.getByText('No recurring charges are scheduled yet.'),
        ).toBeInTheDocument();
    });

    it('shows deferred work only when the server sent it', () => {
        const base = props();
        const { rerender } = render(<PortalHome {...base} />);

        expect(screen.queryByText(/still deferred/)).toBeNull();

        rerender(
            <PortalHome
                {...props({
                    this_period: {
                        ...base.this_period!,
                        still_deferred: {
                            entry_count: 2,
                            total_hours: 3,
                            oldest_worked_on: '2026-04-01',
                        },
                    },
                })}
            />,
        );

        expect(
            screen.getByText(/3 h of work is still deferred, the oldest from/),
        ).toBeInTheDocument();
    });

    it('lists tasks, approved time and invoice history with statuses as words', () => {
        render(<PortalHome {...props()} />);

        expect(screen.getByText('In progress')).toBeInTheDocument();
        expect(
            screen.getByText('Synthetic client summary'),
        ).toBeInTheDocument();
        expect(screen.getByText('Partially paid')).toBeInTheDocument();
        expect(screen.queryByText('partially_paid')).toBeNull();
        expect(screen.getByRole('link', { name: 'SYN-1041' })).toHaveAttribute(
            'href',
            '/portal/company-1/invoices/invoice-1',
        );
        expect(
            screen.getByRole('link', { name: 'All invoices' }),
        ).toBeInTheDocument();
    });

    it('does not draw an approval queue', () => {
        render(<PortalHome {...props()} />);

        expect(screen.queryByText(/to approve/i)).toBeNull();
    });

    it('holds with unbroken names and no overflow risk', () => {
        const hostile = props();
        hostile.company.name = LONG;
        hostile.balance.first!.number = LONG;
        hostile.this_period!.agreement.title = LONG;
        hostile.this_period!.next_invoice!.parts[1].description = LONG;
        hostile.recent_invoices[0].invoice_number = LONG;
        hostile.recent_time[0].description = LONG;
        hostile.recent_time[0].project = LONG;
        hostile.open_tasks[0].title = LONG;
        hostile.open_tasks[0].project = LONG;

        const { container } = render(<PortalHome {...hostile} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
