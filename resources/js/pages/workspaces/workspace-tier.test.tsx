import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/workspaces/dashboard';
import type { QueueRow } from '@/pages/workspaces/dashboard';
import WorkspaceFinance from '@/pages/workspaces/finance';
import WorkspaceInvoices from '@/pages/workspaces/invoices';
import WorkspaceTime from '@/pages/workspaces/time';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { dashboardRevenue } from '@/test/revenue-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

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
                square_href: '/workspaces/workspace-1/square',
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

const WORKSPACE = { id: 'workspace-1', name: 'Synthetic Workspace' };

function row(overrides: Partial<QueueRow> = {}): QueueRow {
    return {
        id: 'row-1',
        kind: 'unapproved_time',
        title: 'Time to approve',
        client: 'Synthetic Client',
        href: '/workspaces/workspace-1/clients/company-1/time',
        action: 'Review',
        amount: null,
        currency: null,
        date: null,
        count: 3,
        minutes: 150,
        ...overrides,
    };
}

describe('dashboard', () => {
    it('says only that nothing needs you when the queue is empty', () => {
        render(
            <Dashboard workspace={WORKSPACE} audience="workspace" queue={[]} />,
        );

        expect(screen.getByText('Nothing needs you')).toBeInTheDocument();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('lists each thing as an icon, a title, one muted line and a trailing link', () => {
        render(
            <Dashboard
                workspace={WORKSPACE}
                audience="workspace"
                queue={[row()]}
            />,
        );

        const item = screen.getByRole('listitem');

        expect(within(item).getByText('Time to approve')).toBeInTheDocument();
        expect(
            within(item).getByText('Synthetic Client · 3 entries, 2.5 h'),
        ).toBeInTheDocument();
        expect(
            within(item).getByRole('link', { name: 'Review: Time to approve' }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/time',
        );
        expect(item.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
    });

    it('reads durations in the style the person chose', () => {
        style.value = 'clock';
        render(
            <Dashboard
                workspace={WORKSPACE}
                audience="workspace"
                queue={[row()]}
            />,
        );
        style.value = 'decimal';

        expect(
            screen.getByText('Synthetic Client · 3 entries, 2:30'),
        ).toBeInTheDocument();
    });

    it('draws the revenue overview for owners and admins only', () => {
        const { container, rerender } = render(
            <Dashboard
                workspace={WORKSPACE}
                audience="workspace"
                queue={[]}
                revenue={dashboardRevenue()}
            />,
        );

        expect(
            container.querySelector('[data-slot="revenue-section"]'),
        ).not.toBeNull();

        rerender(
            <Dashboard workspace={WORKSPACE} audience="member" queue={[]} />,
        );

        expect(
            container.querySelector('[data-slot="revenue-section"]'),
        ).toBeNull();
        expect(container.querySelector('[data-slot="stat-tile"]')).toBeNull();
        expect(screen.getByText('Your work')).toBeInTheDocument();
    });

    it('describes an overdue invoice with its amount and date', () => {
        render(
            <Dashboard
                workspace={WORKSPACE}
                audience="workspace"
                queue={[
                    row({
                        kind: 'overdue_invoice',
                        title: 'Invoice SYN-1',
                        amount: 12000,
                        currency: 'USD',
                        date: '2026-08-01',
                        count: null,
                        minutes: null,
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/\$120\.00 overdue since/)).toBeInTheDocument();
    });
});

describe('cross-client lists', () => {
    const LONG = 'Unbroken'.repeat(12);

    it('lists invoices with the server-sent link and no overflow risk', () => {
        const { container } = render(
            <WorkspaceInvoices
                invoices={[
                    {
                        id: 'invoice-1',
                        invoice_number: LONG,
                        client: LONG,
                        status: 'partially_paid',
                        issue_date: '2026-08-01',
                        due_date: '2026-09-01',
                        balance_amount: 5000,
                        currency: 'USD',
                        href: '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
                    },
                ]}
                next_href="/workspaces/workspace-1/all/invoices?page=2"
                previous_href={null}
            />,
        );

        expect(screen.getByRole('link', { name: LONG })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
        );
        expect(screen.getByText('Partially paid')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Next/ })).toBeInTheDocument();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('lists time to approve with a link to each client sheet', () => {
        render(
            <WorkspaceTime
                entries={[
                    {
                        id: 'entry-1',
                        client: 'Synthetic Client',
                        project: 'Synthetic Project',
                        worked_on: '2026-08-20',
                        minutes: 90,
                        description: 'Synthetic work',
                        href: '/workspaces/workspace-1/clients/company-1/time',
                    },
                ]}
                next_href={null}
                previous_href={null}
            />,
        );

        expect(screen.getByText('1.5 h')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Review' })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/time',
        );
    });

    it('lists balances waiting for a payment and links Square when connected', () => {
        render(
            <WorkspaceFinance
                open_invoices={[
                    {
                        id: 'invoice-1',
                        invoice_number: 'SYN-1',
                        client: 'Synthetic Client',
                        status: 'issued',
                        due_date: null,
                        balance_amount: 10000,
                        currency: 'USD',
                        href: '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
                    },
                ]}
                next_href={null}
                previous_href={null}
            />,
        );

        expect(screen.getByText('SYN-1')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open invoice' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open mirrored Square invoices' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/square');
    });
});
