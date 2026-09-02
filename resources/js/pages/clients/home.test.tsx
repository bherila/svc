import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientHome from '@/pages/clients/home';
import type { ClientHomeProps } from '@/pages/clients/home';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn(), post: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({ workspaceNavigation: workspaceNavigation() }),
    }),
}));

function props(overrides: Partial<ClientHomeProps> = {}): ClientHomeProps {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        latest_invoice: null,
        recent_time: [],
        open_tasks: [],
        engagement: null,
        links: {
            invoices: '/workspaces/workspace-1/clients/company-1/invoices',
            time: '/workspaces/workspace-1/clients/company-1/time',
            tasks: '/workspaces/workspace-1/clients/company-1/tasks',
        },
        settings_href: null,
        ...overrides,
    };
}

describe('client home', () => {
    /**
     * The screens this replaced drew one bordered card per record, so a client
     * with eleven invoices had eleven frames to scroll past. A frame is for
     * something worth stopping at; the whole page has at most one.
     */
    it('links every section to the module holding the rest', () => {
        render(<ClientHome {...props()} />);

        expect(
            screen.getByRole('link', { name: 'All invoices' }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/invoices',
        );
        expect(screen.getByRole('link', { name: 'All time' })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/time',
        );
        expect(screen.getByRole('link', { name: 'All tasks' })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/tasks',
        );
    });

    /**
     * A route family that does not serve a module gets no link rather than a
     * broken one - the same rule the tab strip follows.
     */
    it('omits the view-all link for a module this viewer has no screen for', () => {
        render(
            <ClientHome
                {...props({
                    links: { invoices: null, time: null, tasks: null },
                })}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'All invoices' }),
        ).not.toBeInTheDocument();
    });

    /**
     * A description clipped at one line is the half of the row worth reading
     * thrown away. It was clipped: `truncate` on the description cell hid
     * everything past the column, on the one screen whose job is to say what
     * happened recently.
     */
    it('lets a long description wrap rather than clipping it', () => {
        const description =
            'Planning and implementation of the quarterly reporting pipeline, including the reconciliation pass we discussed on the call last week.';

        render(
            <ClientHome
                {...props({
                    recent_time: [
                        {
                            id: 'entry-1',
                            worked_on: '2026-09-01',
                            project: 'Synthetic Project',
                            description,
                            minutes: 150,
                        },
                    ],
                })}
            />,
        );

        // The whole string is in the document, and nothing on its way up the
        // tree is truncating it.
        const cell = screen.getByText(description);

        expect(cell).toBeInTheDocument();
        expect(cell).not.toHaveClass('truncate');
        expect(cell.closest('.truncate')).toBeNull();
    });

    /**
     * The columns hold `partially_paid`; a client reading their own home screen
     * should not be shown the database beside a figure they owe.
     */
    it('reads a stored status as words', () => {
        render(
            <ClientHome
                {...props({
                    latest_invoice: {
                        id: 'invoice-1',
                        invoice_number: 'SYN-123',
                        status: 'partially_paid',
                        currency: 'USD',
                        issue_date: '2026-09-01',
                        due_date: null,
                        total_amount: 1000,
                        paid_amount: 500,
                        balance_amount: 500,
                        href: '/invoice',
                    },
                })}
            />,
        );

        expect(screen.getByText('Partially paid')).toBeInTheDocument();
        expect(screen.queryByText('partially_paid')).not.toBeInTheDocument();
    });

    it('shows the empty states rather than nothing at all', () => {
        render(<ClientHome {...props()} />);

        expect(screen.getByText('No invoices yet.')).toBeInTheDocument();
        expect(screen.getByText('No recent time.')).toBeInTheDocument();
        expect(screen.getByText('No open tasks.')).toBeInTheDocument();
    });

    it('reads the latest invoice as one line with its outstanding balance', () => {
        render(
            <ClientHome
                {...props({
                    latest_invoice: {
                        id: 'invoice-1',
                        invoice_number: 'SYN-123',
                        status: 'partially_paid',
                        currency: 'USD',
                        issue_date: '2026-09-01',
                        due_date: '2026-09-15',
                        total_amount: 425000,
                        paid_amount: 275000,
                        balance_amount: 150000,
                        href: '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
                    },
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'SYN-123' })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
        );
        expect(screen.getByText(/1,500\.00 outstanding/)).toBeInTheDocument();
    });

    /**
     * Signing your name is a decision, and a decision offered beside ten other
     * things is one taken without reading it. Home says something is waiting
     * and links to the page where it can be read.
     */
    it('announces a waiting proposal without offering the form', () => {
        render(
            <ClientHome
                {...props({
                    engagement: {
                        agreement_title: null,
                        agreement_status: null,
                        agreement_cadence: null,
                        agreement_href: null,
                        proposal_title: 'Synthetic Proposal',
                        proposal_status: 'sent',
                        proposal_href: '/portal/company-1/proposals/proposal-1',
                    },
                })}
            />,
        );

        expect(
            within(screen.getByRole('status')).getByRole('link', {
                name: 'Review proposal',
            }),
        ).toHaveAttribute('href', '/portal/company-1/proposals/proposal-1');
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Accept' }),
        ).not.toBeInTheDocument();
    });

    it('offers client settings only to a viewer the server said may edit it', () => {
        const { rerender } = render(<ClientHome {...props()} />);

        expect(
            screen.queryByRole('link', { name: 'Client settings' }),
        ).not.toBeInTheDocument();

        rerender(
            <ClientHome
                {...props({
                    settings_href:
                        '/workspaces/workspace-1/clients/company-1/settings',
                })}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Client settings' }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/settings',
        );
    });

    /**
     * The page renders the same for both audiences - the difference is which
     * queries filled it, which is decided on the server. Standing in for that
     * here: a portal payload renders with no operator affordance appearing.
     */
    it('renders a portal payload without any operator affordance', () => {
        render(
            <ClientHome
                {...props({
                    links: {
                        invoices: '/portal/company-1/invoices',
                        time: '/portal/company-1/time',
                        tasks: '/portal/company-1/tasks',
                    },
                    recent_time: [
                        {
                            id: 'entry-1',
                            worked_on: '2026-09-01',
                            project: 'Synthetic Project',
                            description: 'Client-facing summary',
                            minutes: 150,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'All time' })).toHaveAttribute(
            'href',
            '/portal/company-1/time',
        );
        expect(screen.getByText('2:30')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Client settings' }),
        ).not.toBeInTheDocument();
    });
});
