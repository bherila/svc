import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AtRiskList } from '@/components/revenue/at-risk-list';
import { KpiStrip } from '@/components/revenue/kpi-strip';
import RevenueChart from '@/components/revenue/revenue-chart';
import { RevenueToggles } from '@/components/revenue/revenue-toggles';
import { changeSentence, phoneWindow } from '@/lib/revenue';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    chartMonths,
    dashboardRevenue,
    LONG_CLIENT,
} from '@/test/revenue-fixtures';

const get = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
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
    router: { get, visit: vi.fn() },
}));

// jsdom lays nothing out, so Recharts has no size to draw into. The chart's
// text alternative - the part this suite can and must prove - is plain HTML.
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

beforeEach(() => get.mockClear());

describe('KpiStrip', () => {
    it('prints the servers figures with one line of context each', () => {
        const revenue = dashboardRevenue();

        render(
            <KpiStrip kpis={revenue.kpis} currency="USD" variant="workspace" />,
        );

        const strip = screen.getByRole('region', { name: 'Key figures' });

        expect(strip).toHaveAttribute('aria-live', 'polite');
        expect(within(strip).getByText('$1,234,567.89')).toBeInTheDocument();
        expect(
            within(strip).getByText('Up 23.5% on the same period last year'),
        ).toBeInTheDocument();
        expect(
            within(strip).getByText(
                'Nothing to compare with the prior 12 months',
            ),
        ).toBeInTheDocument();
        expect(
            within(strip).getByText('Contracted recurring revenue'),
        ).toBeInTheDocument();
        expect(
            within(strip).getByText('$30,000.00 overdue on 2 invoices'),
        ).toBeInTheDocument();
        expect(strip.querySelectorAll('[data-slot="stat-tile"]')).toHaveLength(
            4,
        );
    });

    it('adds the projected overage to the next-12-months line only when it is on', () => {
        const revenue = dashboardRevenue();

        render(
            <KpiStrip
                kpis={{
                    ...revenue.kpis,
                    next_12_months: {
                        ...revenue.kpis.next_12_months,
                        projected_overage: 4200000,
                    },
                }}
                currency="USD"
                variant="workspace"
            />,
        );

        expect(
            screen.getByText('+ $42,000.00 projected overage'),
        ).toBeInTheDocument();
    });

    it('swaps the next 12 months for MRR on a client, and names an empty overdue as nothing overdue', () => {
        const revenue = dashboardRevenue();

        render(
            <KpiStrip
                kpis={{
                    ...revenue.kpis,
                    mrr: 110000,
                    open_receivables: {
                        outstanding: 0,
                        invoice_count: 0,
                        overdue_amount: 0,
                        overdue_count: 0,
                    },
                }}
                currency="USD"
                variant="client"
            />,
        );

        expect(screen.getByText('MRR')).toBeInTheDocument();
        expect(screen.queryByText('Next 12 months')).not.toBeInTheDocument();
        expect(screen.getByText('Open balance')).toBeInTheDocument();
        expect(screen.getByText('Nothing overdue')).toBeInTheDocument();
        expect(screen.getByText('$1,100.00')).toBeInTheDocument();
    });

    it('says a change in words, never colour alone', () => {
        expect(changeSentence(0, 'last year')).toBe('Unchanged on last year');
        expect(changeSentence(-3, 'last year')).toBe('Down 3% on last year');
        expect(changeSentence(null, 'last year')).toBe(
            'Nothing to compare with last year',
        );
    });
});

describe('RevenueToggles', () => {
    it('asks the server for the same page with the toggle changed, reloading only the revenue', () => {
        render(
            <RevenueToggles
                filters={{ basis: 'invoiced', expenses: true, overage: false }}
                href="/workspaces/workspace-1/dashboard"
                only="revenue"
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Invoiced' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByRole('button', { name: 'Collected' }),
        ).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(screen.getByRole('button', { name: 'Collected' }));

        expect(get).toHaveBeenCalledWith(
            '/workspaces/workspace-1/dashboard',
            { basis: 'collected', expenses: 1, overage: 0 },
            expect.objectContaining({
                only: ['revenue'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );
    });

    it('does not ask again for the basis already shown', () => {
        render(
            <RevenueToggles
                filters={{ basis: 'invoiced', expenses: true, overage: false }}
                href="/x"
                only="overview"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Invoiced' }));

        expect(get).not.toHaveBeenCalled();
    });

    it('carries the other toggles along when one switch flips', () => {
        render(
            <RevenueToggles
                filters={{ basis: 'collected', expenses: true, overage: false }}
                href="/x"
                only="overview"
            />,
        );

        fireEvent.click(
            screen.getByRole('switch', { name: 'Include overage projection' }),
        );

        expect(get).toHaveBeenCalledWith(
            '/x',
            { basis: 'collected', expenses: 1, overage: 1 },
            expect.anything(),
        );

        fireEvent.click(
            screen.getByRole('switch', { name: 'Include expenses' }),
        );

        expect(get).toHaveBeenLastCalledWith(
            '/x',
            { basis: 'collected', expenses: 0, overage: 0 },
            expect.anything(),
        );
    });
});

describe('RevenueChart', () => {
    const revenue = dashboardRevenue();
    const caption = 'Revenue by month, a synthetic summary.';

    function chart(
        overrides: Partial<Parameters<typeof RevenueChart>[0]> = {},
    ) {
        return render(
            <RevenueChart
                months={revenue.months}
                filters={revenue.filters}
                assumptions={revenue.assumptions}
                caption={caption}
                {...overrides}
            />,
        );
    }

    it('gives the chart a text alternative: a summary and a table of every month', () => {
        const { container } = chart();

        expect(screen.getByRole('img', { name: caption })).toBeInTheDocument();

        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row');

        // A header and one row per month, all in the page whether or not it is shown.
        expect(rows).toHaveLength(26);
        expect(
            container.querySelector('[data-slot="revenue-chart-table"]'),
        ).toHaveClass('sr-only');
        expect(
            within(rows[1]).getByRole('link', { name: 'November 2025' }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/all/invoices?month=2025-11',
        );
        expect(within(rows[13]).getByText('(this month)')).toBeInTheDocument();
        expect(
            within(rows[1]).getByText(
                /Synthetic Second Client \$400\.00, 3 others \$15\.00/,
            ),
        ).toBeInTheDocument();
    });

    it('shows the table on request, and says so', () => {
        const { container } = chart();
        const toggle = screen.getByRole('button', { name: 'Show as table' });

        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(toggle);

        expect(
            screen.getByRole('button', { name: 'Hide table' }),
        ).toHaveAttribute('aria-expanded', 'true');
        expect(
            container.querySelector('[data-slot="revenue-chart-table"]'),
        ).not.toHaveClass('sr-only');
    });

    it('lists only the bands that are switched on', () => {
        chart({
            filters: { basis: 'invoiced', expenses: false, overage: false },
        });

        const headers = within(screen.getByRole('table'))
            .getAllByRole('columnheader')
            .map((header) => header.textContent);

        expect(headers).toEqual([
            'Month',
            'Recurring',
            'Overage and other',
            'Contracted',
            'Total',
            'Top clients',
        ]);
        expect(screen.queryByText('Expenses')).not.toBeInTheDocument();
        expect(screen.queryByText('Projected overage')).not.toBeInTheDocument();
    });

    it('adds expenses and projected overage when they are on', () => {
        chart({
            filters: { basis: 'invoiced', expenses: true, overage: true },
        });

        const headers = within(screen.getByRole('table'))
            .getAllByRole('columnheader')
            .map((header) => header.textContent);

        expect(headers).toContain('Expenses');
        expect(headers).toContain('Projected overage');
        expect(screen.getAllByText('Projected overage').length).toBeGreaterThan(
            0,
        );
    });

    it('prints a switched-off figure as a dash rather than a zero', () => {
        chart({
            filters: { basis: 'invoiced', expenses: true, overage: true },
        });

        const row = within(screen.getByRole('table')).getAllByRole('row')[1];

        // November 2025 is a past month: it has no projected overage.
        expect(within(row).getAllByText('—').length).toBeGreaterThan(0);
    });

    it('says the forecast stays on the invoiced basis when past months are collected', () => {
        chart({
            assumptions: { ...revenue.assumptions, basis: 'collected' },
        });

        expect(
            screen.getByText(/forecast stays on the invoiced basis/),
        ).toBeInTheDocument();
    });

    it('keeps hostile names inside the window', () => {
        const { container } = chart();

        expect(
            screen.getAllByText(new RegExp(LONG_CLIENT)).length,
        ).toBeGreaterThan(0);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('AtRiskList', () => {
    it('names each agreement with its end date and the monthly amount that drops off', () => {
        const { container } = render(
            <AtRiskList atRisk={dashboardRevenue().at_risk} currency="USD" />,
        );

        expect(
            screen.getByText(
                /\$500\.00 a month drops off\. The projection does not assume a renewal\./,
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('$500.00')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: LONG_CLIENT })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1',
        );
        expect(
            screen.getByRole('link', { name: /View agreement:/ }),
        ).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
        );
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('says plainly when nothing is at risk', () => {
        render(
            <AtRiskList
                atRisk={{ agreements: [], monthly_total: 0 }}
                currency="USD"
            />,
        );

        expect(
            screen.getByText('No agreement ends in the next 12 months.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });
});

describe('phone window', () => {
    it('keeps six months back, the current month and five ahead', () => {
        const months = phoneWindow(chartMonths());

        expect(months.map((month) => month.month)).toEqual([
            '2026-05',
            '2026-06',
            '2026-07',
            '2026-08',
            '2026-09',
            '2026-10',
            '2026-11',
            '2026-12',
            '2027-01',
            '2027-02',
            '2027-03',
            '2027-04',
        ]);
        expect(months[6].kind).toBe('current');
    });

    it('shows everything when no month is current', () => {
        const months = chartMonths().filter(
            (month) => month.kind === 'projected',
        );

        expect(phoneWindow(months)).toHaveLength(months.length);
    });

    it('clamps at the start of a short series', () => {
        const months = chartMonths().slice(10);

        expect(phoneWindow(months)).toHaveLength(2 + 1 + 5);
    });
});

describe('chart colours', () => {
    const css = readFileSync(
        join(__dirname, '..', '..', '..', 'css', 'app.css'),
        'utf8',
    );

    it('are theme tokens, defined for the light theme and again for dark', () => {
        for (const token of [
            '--revenue-recurring',
            '--revenue-other',
            '--revenue-expenses',
        ]) {
            const definitions = css.match(new RegExp(`${token}:`, 'g')) ?? [];

            expect(definitions, token).toHaveLength(2);
        }

        const dark = css.slice(css.indexOf('.dark {'));

        expect(dark).toContain('--revenue-recurring:');
        expect(dark).toContain('--revenue-other:');
        expect(dark).toContain('--revenue-expenses:');
    });
});
