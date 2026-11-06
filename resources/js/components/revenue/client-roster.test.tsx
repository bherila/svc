import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ClientRoster } from '@/components/revenue/client-roster';
import { RetainerMeter } from '@/components/revenue/retainer-meter';
import { Sparkline } from '@/components/revenue/sparkline';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    LONG_CLIENT,
    roster,
    rosterHealth,
    rosterRow,
} from '@/test/revenue-fixtures';

const get = vi.hoisted(() => vi.fn());
const style = vi.hoisted(() => ({ value: 'decimal' as 'decimal' | 'clock' }));

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
    router: { get },
    usePage: () => ({
        props: { auth: { user: { duration_style: style.value } } },
    }),
}));

beforeEach(() => get.mockClear());
afterEach(() => {
    vi.useRealTimers();
    style.value = 'decimal';
});

const HEALTH = {
    'company-1': rosterHealth(),
    'company-2': rosterHealth({
        status: 'no_retainer',
        minutes_used: null,
        minutes_included: null,
    }),
};

describe('ClientRoster', () => {
    it('lists each client with the columns the spec names, as a sortable table', () => {
        render(<ClientRoster roster={roster()} health={HEALTH} />);

        const table = screen.getByRole('table');
        const headers = within(table)
            .getAllByRole('columnheader')
            .map((header) => header.textContent?.trim());

        expect(headers).toEqual([
            'Client',
            'MRR',
            'YTD',
            'TTM',
            'Open / overdue',
            'Agreement health',
        ]);
        expect(
            within(table).getByRole('columnheader', { name: /^Client/ }),
        ).toHaveAttribute('aria-sort', 'ascending');

        const first = within(table).getAllByRole('row')[1];

        expect(
            within(first).getByRole('link', { name: LONG_CLIENT }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/clients/company-1');
        expect(within(first).getByText('$1,234,567.89')).toBeInTheDocument();
        expect(
            within(first).getByText('$20,000.00 overdue (2)'),
        ).toBeInTheDocument();
        expect(within(first).getByText('9 h / 10 h')).toBeInTheDocument();
        expect(
            within(first).getByText('Ends within 60 days'),
        ).toBeInTheDocument();
        expect(first.querySelector('[data-slot="sparkline"]')).not.toBeNull();
    });

    it('prints the retainer hours in the style the person chose', () => {
        style.value = 'clock';

        render(<ClientRoster roster={roster()} health={HEALTH} />);

        expect(
            within(screen.getByRole('table')).getByText('9:00 / 10:00'),
        ).toBeInTheDocument();
    });

    it('says the meters are being read until the deferred answer arrives', () => {
        render(<ClientRoster roster={roster()} health={undefined} />);

        expect(
            within(screen.getByRole('table')).getAllByText('Reading hours…'),
        ).toHaveLength(2);
    });

    it('marks an over-retainer client in words and an icon, and withholds an unreadable ledger', () => {
        render(
            <ClientRoster
                roster={roster()}
                health={{
                    'company-1': rosterHealth({
                        over_retainer: true,
                        minutes_used: 700,
                    }),
                    'company-2': rosterHealth({
                        status: 'ledger_unreadable',
                        minutes_used: null,
                        minutes_included: null,
                    }),
                }}
            />,
        );

        const table = screen.getByRole('table');

        expect(within(table).getByText('Over retainer')).toBeInTheDocument();
        expect(within(table).getByText('11.67 h / 10 h')).toBeInTheDocument();
        expect(
            within(table).getByText('Hours unavailable'),
        ).toBeInTheDocument();
    });

    it('shows the totals row the server sent, labelled for what it adds up', () => {
        const { rerender } = render(
            <ClientRoster roster={roster()} health={HEALTH} />,
        );
        const footer = () =>
            within(screen.getByRole('table')).getAllByRole('row').at(-1)!;

        expect(within(footer()).getByText('Total')).toBeInTheDocument();
        expect(within(footer()).getByText('$1,235,067.89')).toBeInTheDocument();

        rerender(
            <ClientRoster
                roster={roster({
                    filters: {
                        q: 'Synthetic',
                        filter: 'all',
                        sort: 'name',
                        dir: 'asc',
                    },
                    total_count: 5,
                })}
                health={HEALTH}
            />,
        );

        expect(
            within(footer()).getByText('Total of 2 shown'),
        ).toBeInTheDocument();
        expect(screen.getByText('2 of 5 clients')).toBeInTheDocument();
    });

    it('asks the server to sort, largest first for a number and then reversed', () => {
        const { rerender } = render(
            <ClientRoster roster={roster()} health={HEALTH} />,
        );

        fireEvent.click(screen.getByRole('button', { name: /^MRR/ }));

        expect(get).toHaveBeenLastCalledWith(
            '/workspaces/workspace-1/clients',
            { sort: 'mrr', dir: 'desc' },
            expect.objectContaining({
                only: ['roster'],
                preserveState: true,
                replace: true,
            }),
        );

        rerender(
            <ClientRoster
                roster={roster({
                    filters: { q: '', filter: 'all', sort: 'mrr', dir: 'desc' },
                })}
                health={HEALTH}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^MRR/ }));

        expect(get).toHaveBeenLastCalledWith(
            '/workspaces/workspace-1/clients',
            { sort: 'mrr', dir: 'asc' },
            expect.anything(),
        );
        expect(
            screen.getByRole('columnheader', { name: /^MRR/ }),
        ).toHaveAttribute('aria-sort', 'descending');

        fireEvent.click(screen.getByRole('button', { name: /^Client/ }));

        expect(get).toHaveBeenLastCalledWith(
            '/workspaces/workspace-1/clients',
            {},
            expect.anything(),
        );
    });

    it('searches after a pause, and straight away on Enter', () => {
        vi.useFakeTimers();
        render(<ClientRoster roster={roster()} health={HEALTH} />);

        const box = screen.getByRole('searchbox', { name: 'Search clients' });

        fireEvent.change(box, { target: { value: ' second ' } });

        expect(get).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenLastCalledWith(
            '/workspaces/workspace-1/clients',
            { q: 'second' },
            expect.anything(),
        );

        fireEvent.change(box, { target: { value: 'third' } });
        fireEvent.submit(screen.getByRole('search'));

        expect(get).toHaveBeenCalledTimes(2);
        expect(get).toHaveBeenLastCalledWith(
            '/workspaces/workspace-1/clients',
            { q: 'third' },
            expect.anything(),
        );

        act(() => {
            vi.advanceTimersByTime(1000);
        });

        expect(get).toHaveBeenCalledTimes(2);
    });

    it('names the filter, not its code, and reads the meters up front for over retainer', () => {
        render(
            <ClientRoster
                roster={roster({
                    filters: {
                        q: '',
                        filter: 'overdue',
                        sort: 'name',
                        dir: 'asc',
                    },
                })}
                health={HEALTH}
            />,
        );

        expect(
            screen.getByRole('combobox', { name: 'Filter clients' }),
        ).toHaveTextContent('Has overdue invoices');
    });

    it('turns each row into a card on a phone, without the table columns', () => {
        render(<ClientRoster roster={roster()} health={HEALTH} />);

        const cards = within(
            screen.getByRole('list', { name: 'Clients' }),
        ).getAllByRole('listitem');

        expect(cards).toHaveLength(2);
        expect(
            within(cards[0]).getByRole('link', { name: LONG_CLIENT }),
        ).toBeInTheDocument();
        expect(within(cards[0]).getByText('MRR')).toBeInTheDocument();
        expect(within(cards[0]).getByText('Open balance')).toBeInTheDocument();
        expect(within(cards[0]).getByText('9 h / 10 h')).toBeInTheDocument();
    });

    it('stays inside the window with names and amounts that do not fit', () => {
        const { container } = render(
            <ClientRoster
                roster={roster({
                    clients: [
                        rosterRow(),
                        rosterRow({
                            client_id: 'company-3',
                            name: 'x'.repeat(200),
                            is_active: false,
                            href: '/workspaces/workspace-1/clients/company-3',
                        }),
                    ],
                })}
                health={HEALTH}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
        expect(screen.getAllByText('Inactive').length).toBeGreaterThan(0);
    });

    it('teaches when there are no clients, and offers a way out when a filter hides them all', () => {
        const { rerender } = render(
            <ClientRoster
                roster={roster({ clients: [], total_count: 0 })}
                health={undefined}
            />,
        );

        expect(screen.getByText(/No clients yet\./)).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();

        rerender(
            <ClientRoster
                roster={roster({
                    clients: [],
                    total_count: 3,
                    filters: {
                        q: 'zzz',
                        filter: 'all',
                        sort: 'name',
                        dir: 'asc',
                    },
                })}
                health={undefined}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear search and filter' }),
        );

        expect(get).toHaveBeenLastCalledWith(
            '/workspaces/workspace-1/clients',
            {},
            expect.anything(),
        );
    });
});

describe('Sparkline', () => {
    it('draws a flat line for a series with no range rather than dividing by zero', () => {
        const { container } = render(
            <Sparkline
                series={Array.from({ length: 12 }, (_, index) => ({
                    month: `2026-${index + 1}`,
                    amount: 0,
                }))}
            />,
        );
        const points = container
            .querySelector('polyline')!
            .getAttribute('points')!;

        expect(points).not.toContain('NaN');
        expect(
            new Set(points.split(' ').map((point) => point.split(',')[1])),
        ).toEqual(new Set(['10.0']));
        expect(container.querySelector('svg')).toHaveAttribute(
            'aria-hidden',
            'true',
        );
    });

    it('survives an empty series and a single month', () => {
        const { container, rerender } = render(<Sparkline series={[]} />);

        expect(
            container.querySelector('polyline')!.getAttribute('points'),
        ).toBe('');

        rerender(<Sparkline series={[{ month: '2026-01', amount: 5 }]} />);

        expect(
            container.querySelector('polyline')!.getAttribute('points'),
        ).not.toContain('NaN');
    });
});

describe('RetainerMeter', () => {
    it('caps the bar at full for an over-run and never divides by zero', () => {
        const { container, rerender } = render(
            <RetainerMeter
                status="ok"
                minutesUsed={900}
                minutesIncluded={600}
                over
            />,
        );
        const bar = () =>
            container.querySelector<HTMLElement>(
                '.rounded-full > .rounded-full',
            )!;

        expect(bar().style.width).toBe('100%');
        expect(screen.getByText('15 h / 10 h')).toBeInTheDocument();

        rerender(
            <RetainerMeter status="ok" minutesUsed={0} minutesIncluded={0} />,
        );

        expect(bar().style.width).toBe('0%');
    });

    it('shows no figures unless the ledger was read', () => {
        render(
            <RetainerMeter
                status="ledger_unreadable"
                minutesUsed={600}
                minutesIncluded={600}
            />,
        );

        expect(screen.queryByText(/10 h/)).not.toBeInTheDocument();
        expect(screen.getByText('Hours unavailable')).toBeInTheDocument();
    });
});
