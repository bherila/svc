import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import SquareExplore from './explore';
import type { SquareExploreProps } from './explore';

const inertia = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: inertia,
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const run: SquareExploreProps['sync']['last_run'] = {
    id: '00000000-0000-4000-8000-000000000001',
    trigger: 'schedule',
    status: 'completed',
    square_version: '2026-09-16',
    requested_at: '2026-10-12T18:00:00Z',
    started_at: '2026-10-12T18:00:00Z',
    finished_at: '2026-10-12T18:00:05Z',
    locations_seen: 1,
    customers_seen: 2,
    customers_changed: 0,
    invoices_seen: 2,
    invoices_changed: 0,
    payments_resolved: 0,
    high_water_mark: null,
    truncated: false,
    error_code: null,
    error: null,
};

function invoice(
    id: string,
    overrides: Partial<SquareExploreProps['invoices']['data'][number]> = {},
): SquareExploreProps['invoices']['data'][number] {
    return {
        square_invoice_id: id,
        invoice_number: '000001-R-0001',
        series_key: '000001',
        series_occurrence: 1,
        subscription_id: null,
        location_id: 'LOCFIXTURE01',
        order_id: 'order_fixture_1',
        customer_id: 'cust_fixture_a',
        recipient_name: 'Fixture Holdings',
        title: 'Monthly retainer',
        status: 'UNPAID',
        currency: 'USD',
        total_amount: 12_345,
        paid_amount: 0,
        next_payment_amount: 12_345,
        due_on: '2026-10-15',
        scheduled_at: null,
        sale_or_service_date: null,
        created_at: null,
        updated_at: null,
        public_url: 'https://squareupsandbox.com/pay-invoice/inv:0-fixture1',
        autopay: true,
        reminders: [],
        payment_ids: null,
        last_seen_at: null,
        ...overrides,
    };
}

function props(
    overrides: Partial<SquareExploreProps> = {},
): SquareExploreProps {
    return {
        filters: {
            location_id: null,
            status: null,
            customer_id: null,
            series_key: null,
            due_from: null,
            due_to: null,
        },
        options: {
            locations: [{ value: 'LOCFIXTURE01', label: 'Synthetic studio' }],
            customers: [{ value: 'cust_fixture_a', label: 'Fixture Holdings' }],
            statuses: [{ value: 'UNPAID' }, { value: 'PAID' }],
        },
        invoices: {
            data: [
                invoice('inv:0-fixture1'),
                invoice('inv:0-fixture2', {
                    invoice_number: '000002',
                    status: 'PAID',
                    paid_amount: 12_345,
                    public_url: null,
                    autopay: false,
                }),
            ],
            page: 1,
            last_page: 2,
            total: 52,
        },
        series: [
            {
                series_key: '000001',
                customer_id: 'cust_fixture_a',
                customer_count: 1,
                recipient_name: 'Fixture Holdings',
                location_id: 'LOCFIXTURE01',
                currency: 'USD',
                invoice_count: 2,
                first_occurrence: 1,
                last_occurrence: 3,
                missing_occurrences: [2],
                missing_occurrences_truncated: false,
                cadence: 'monthly',
                cadence_days: 30,
                amount: 12_345,
                amount_varies: false,
                first_due_on: '2026-09-15',
                last_due_on: '2026-10-15',
                next_due_on: '2026-10-15',
                open_amount: 12_345,
                autopay: true,
                statuses: { UNPAID: 1, PAID: 1 },
            },
        ],
        series_truncated: false,
        sync: {
            last_synced_at: '2026-10-12T18:00:05Z',
            last_run: run,
            sync_url: '/api/v1/workspaces/synthetic/square/sync',
        },
        explore_url: '/workspaces/synthetic/square',
        ...overrides,
    };
}

describe('the Square explore screen', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => vi.unstubAllGlobals());

    it('lists mirrored invoices and series with Square links only where Square gave one', () => {
        render(<SquareExplore {...props()} />);

        expect(screen.getByText('Invoices (52)')).toBeInTheDocument();
        expect(screen.getAllByText('Fixture Holdings').length).toBeGreaterThan(
            0,
        );
        const links = screen.getAllByRole('link', { name: 'Open' });
        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute(
            'href',
            'https://squareupsandbox.com/pay-invoice/inv:0-fixture1',
        );
        expect(links[0]).toHaveAttribute('rel', 'noopener noreferrer');
        expect(screen.getByText('Unpaid')).toBeInTheDocument();
        expect(screen.getByText('(1 missing)')).toBeInTheDocument();
        expect(screen.getByText(/Last synced/)).toBeInTheDocument();
    });

    it('applies a due-date range and pages through the server query', () => {
        render(<SquareExplore {...props()} />);

        fireEvent.change(screen.getByLabelText('Due from'), {
            target: { value: '2026-10-01' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply dates' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/workspaces/synthetic/square',
            { due_from: '2026-10-01' },
            { preserveScroll: true, preserveState: true },
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/workspaces/synthetic/square',
            { page: '2' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('syncs through the API and reloads, and says why a sync failed', async () => {
        const fetch = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        ...run,
                        status: 'failed',
                        error_code: 'SERVICE_UNAVAILABLE',
                        error: 'Square was unavailable. The next sync will try again.',
                    },
                }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        vi.stubGlobal('fetch', fetch);
        render(<SquareExplore {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Sync now' }));

        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(fetch).toHaveBeenCalledWith(
            '/api/v1/workspaces/synthetic/square/sync',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Square was unavailable.',
        );
    });

    it('keeps long unbroken names inside the page', () => {
        const long = 'Synthetic'.repeat(40);
        const { container } = render(
            <SquareExplore
                {...props({
                    invoices: {
                        data: [
                            invoice('inv:0-long', {
                                recipient_name: long,
                                invoice_number: '000009-R-0009',
                            }),
                        ],
                        page: 1,
                        last_page: 1,
                        total: 1,
                    },
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
