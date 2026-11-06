import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientHome from '@/pages/clients/home';
import type { ClientHomeProps } from '@/pages/clients/home';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    clientOverview,
    LONG_CLIENT,
    LONG_TITLE,
} from '@/test/revenue-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

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
    router: { get: vi.fn(), visit: vi.fn(), post: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({ workspaceNavigation: workspaceNavigation() }),
    }),
}));

vi.mock('recharts', () => {
    const Passthrough = ({ children }: { children?: ReactNode }) => (
        <div>{children}</div>
    );

    return {
        Bar: () => null,
        BarChart: Passthrough,
        CartesianGrid: () => null,
        ReferenceLine: () => null,
        ResponsiveContainer: Passthrough,
        Tooltip: () => null,
        XAxis: () => null,
        YAxis: () => null,
    };
});

function props(overrides: Partial<ClientHomeProps> = {}): ClientHomeProps {
    return {
        company: { id: 'company-1', name: LONG_CLIENT },
        latest_invoice: {
            id: 'invoice-1',
            invoice_number: 'INV-1',
            status: 'issued',
            currency: 'USD',
            issue_date: '2026-10-01',
            due_date: null,
            total_amount: 100,
            paid_amount: 0,
            balance_amount: 100,
            href: '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
        },
        recent_time: [],
        open_tasks: [],
        engagement: {
            agreement_title: 'Engagement',
            agreement_status: 'active',
            agreement_cadence: 'monthly',
            agreement_href: '/a',
            proposal_title: null,
            proposal_status: null,
            proposal_href: null,
        },
        links: {
            invoices: '/workspaces/workspace-1/clients/company-1/invoices',
            time: '/workspaces/workspace-1/clients/company-1/time',
            tasks: '/workspaces/workspace-1/clients/company-1/tasks',
        },
        settings_href: null,
        ...overrides,
    };
}

const PERIOD = {
    agreement_id: 'agreement-1',
    title: LONG_TITLE,
    period_start: '2026-11-01',
    period_end: '2026-11-30',
    minutes_used: 540,
    minutes_included: 600,
    over_retainer: false,
    ends_within_60_days: true,
    ends_on: '2026-12-15',
    ledger_status: 'ok' as const,
    href: '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
};

function section(name: string): HTMLElement {
    return screen.getByRole('heading', { name }).closest('section')!;
}

describe('client overview', () => {
    it('shows the key figures and the chart, with the text alternative in the page', async () => {
        const { container } = render(
            <ClientHome
                {...props({
                    overview: clientOverview(),
                    this_period: [PERIOD],
                })}
            />,
        );

        expect(
            await screen.findByRole('table', { name: /Revenue by month/ }),
        ).toBeInTheDocument();
        expect(
            container.querySelectorAll('[data-slot="stat-tile"]'),
        ).toHaveLength(4);
        expect(screen.getByText('Open balance')).toBeInTheDocument();
        expect(screen.getByText('MRR')).toBeInTheDocument();
    });

    it('meters this periods retainer use, and says it is reading until the deferred answer arrives', () => {
        const { container, rerender } = render(
            <ClientHome
                {...props({
                    overview: clientOverview(),
                    this_period: [PERIOD],
                })}
            />,
        );
        const period = () =>
            container.querySelector<HTMLElement>('[data-slot="this-period"]')!;

        expect(within(period()).getByText('9 h / 10 h')).toBeInTheDocument();
        expect(
            within(period()).getByText('Ends within 60 days'),
        ).toBeInTheDocument();

        rerender(
            <ClientHome
                {...props({
                    overview: clientOverview(),
                    this_period: undefined,
                })}
            />,
        );

        expect(
            within(period()).getByText(/Reading this period/),
        ).toBeInTheDocument();

        rerender(
            <ClientHome
                {...props({ overview: clientOverview(), this_period: [] })}
            />,
        );

        expect(
            within(period()).getByText(
                /appears here once an active agreement includes hours/,
            ),
        ).toBeInTheDocument();
    });

    it('cards each active agreement under the anchor the Agreements tab points at', () => {
        const { container } = render(
            <ClientHome {...props({ overview: clientOverview() })} />,
        );
        const agreements = section('Agreements');

        expect(container.querySelector('#agreements')).toBe(
            within(agreements).getByRole('heading', { name: 'Agreements' }),
        );
        expect(
            within(agreements).getByRole('link', { name: LONG_TITLE }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
        );
        expect(
            within(agreements).getByText(/ends 15 Dec 2026|ends Dec 15, 2026/),
        ).toBeInTheDocument();
        expect(
            within(agreements).getByText(/next charged/),
        ).toBeInTheDocument();
    });

    it('teaches when there is no active agreement and keeps the anchor', () => {
        const { container } = render(
            <ClientHome
                {...props({ overview: clientOverview({ agreements: [] }) })}
            />,
        );

        expect(container.querySelector('#agreements')).not.toBeNull();
        expect(
            screen.getByText(/appear here once an agreement is activated/),
        ).toBeInTheDocument();
    });

    it('tables the recent invoices with a badge for the one status per row', () => {
        render(<ClientHome {...props({ overview: clientOverview() })} />);

        const invoices = section('Recent invoices');

        expect(
            within(invoices).getByRole('link', { name: 'All invoices' }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/invoices',
        );
        expect(
            within(invoices).getByRole('link', { name: /^INV-9+$/ }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
        );
        expect(
            within(invoices).getByText('Partially paid'),
        ).toBeInTheDocument();
        expect(within(invoices).getByText('$600.00')).toBeInTheDocument();
        // The single latest-invoice card is replaced, not repeated.
        expect(screen.queryByText('Latest invoice')).not.toBeInTheDocument();
    });

    it('keeps a long project name beside its Log time link rather than under it', () => {
        render(<ClientHome {...props({ overview: clientOverview() })} />);

        const log = screen.getByRole('link', { name: /^Log time:/ });
        const name = log.parentElement!.querySelector('p')!;

        expect(log).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/time?log_project=project-1',
        );
        expect(log).toHaveClass('shrink-0');
        expect(name).toHaveClass('min-w-0', 'flex-1', 'wrap-anywhere');
        expect(log.parentElement).toHaveClass('flex', 'flex-wrap');
    });

    it("shows the client's recorded activity, which the retired Operations screen held", () => {
        render(<ClientHome {...props({ overview: clientOverview() })} />);

        expect(screen.getByText('Activity')).toBeInTheDocument();
        expect(
            screen.getByText('The latest 100 recorded events for this client.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Invoice issued')).toBeInTheDocument();
    });

    it('lists proposals, and teaches when a client has none', () => {
        const { rerender } = render(
            <ClientHome {...props({ overview: clientOverview() })} />,
        );

        expect(
            screen.getByRole('link', { name: /^View proposal:/ }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/proposals/proposal-1',
        );

        rerender(
            <ClientHome
                {...props({
                    overview: clientOverview({ proposals: [], projects: [] }),
                })}
            />,
        );

        expect(screen.getByText('No proposals yet.')).toBeInTheDocument();
        expect(
            screen.getByText(/Add one in client settings/),
        ).toBeInTheDocument();
    });

    it('stays inside the window with names and amounts that do not fit', async () => {
        const { container } = render(
            <ClientHome
                {...props({
                    overview: clientOverview(),
                    this_period: [PERIOD],
                })}
            />,
        );

        await screen.findByRole('table', { name: /Revenue by month/ });

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('client home without an overview', () => {
    it('is the plain Client Home, with the agreements anchor on the engagement line', () => {
        const { container } = render(<ClientHome {...props()} />);

        expect(container.querySelector('[data-slot="stat-tile"]')).toBeNull();
        expect(screen.getByText('Latest invoice')).toBeInTheDocument();
        expect(container.querySelector('#agreements')).toBe(
            screen
                .getByRole('heading', { name: 'Engagement' })
                .closest('section'),
        );
        expect(screen.queryByText('Recent invoices')).not.toBeInTheDocument();
        expect(screen.queryByText('Log time')).not.toBeInTheDocument();
    });
});
