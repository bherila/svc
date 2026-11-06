import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/workspaces/dashboard';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { dashboardRevenue } from '@/test/revenue-fixtures';
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
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        }),
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

const WORKSPACE = { id: 'workspace-1', name: 'Synthetic Workspace' };

describe('dashboard revenue', () => {
    it('stacks the key figures, the chart and the at-risk list above the action queue', async () => {
        const { container } = render(
            <Dashboard
                workspace={WORKSPACE}
                audience="workspace"
                queue={[]}
                revenue={dashboardRevenue()}
            />,
        );

        // The chart is lazy-loaded: its table arrives after the first paint.
        expect(await screen.findByRole('table')).toBeInTheDocument();

        const headings = screen
            .getAllByText(
                /^(Revenue by month|At risk in the next 12 months|Needs you)$/,
            )
            .map((node) => node.textContent);

        expect(headings).toEqual([
            'Revenue by month',
            'At risk in the next 12 months',
            'Needs you',
        ]);
        expect(
            container.querySelectorAll('[data-slot="stat-tile"]'),
        ).toHaveLength(4);
        expect(screen.getByText('Nothing needs you')).toBeInTheDocument();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('keeps one main landmark and one h1', async () => {
        render(
            <Dashboard
                workspace={WORKSPACE}
                audience="workspace"
                queue={[]}
                revenue={dashboardRevenue()}
            />,
        );

        await screen.findByRole('table');

        expect(screen.getAllByRole('main')).toHaveLength(1);
        expect(
            within(screen.getByRole('main')).getAllByRole('heading', {
                level: 1,
            }),
        ).toHaveLength(1);
    });

    it('draws no revenue and no tile for a member', () => {
        const { container } = render(
            <Dashboard workspace={WORKSPACE} audience="member" queue={[]} />,
        );

        expect(container.querySelector('[data-slot="stat-tile"]')).toBeNull();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(screen.queryByText(/Year to date/)).not.toBeInTheDocument();
    });
});
