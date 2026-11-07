import type {
    ChartMonth,
    ClientOverview,
    DashboardRevenue,
    Roster,
    RosterHealth,
    RosterRow,
} from '@/types/revenue';

/**
 * Revenue payloads shaped like the server's, for the page tests. Names are
 * deliberately hostile (no break opportunity, paragraph length) because
 * jsdom measures nothing: the overflow rules only fire on data that does not
 * fit. Everything is synthetic.
 */
export const LONG_CLIENT = 'Unbroken'.repeat(9);

export const LONG_TITLE =
    'An agreement title that runs on for a whole paragraph of words and then ' +
    'continues with an identifier-' +
    'x'.repeat(60);

function monthOf(index: number): string {
    // 2025-11 is index 0; index 12 is the current month, 2026-11.
    const base = 2025 * 12 + 10 + index;

    return `${Math.floor(base / 12)}-${String((base % 12) + 1).padStart(2, '0')}`;
}

export function chartMonths(): ChartMonth[] {
    return Array.from({ length: 25 }, (_, index): ChartMonth => {
        const kind =
            index < 12 ? 'actual' : index === 12 ? 'current' : 'projected';
        const past = kind !== 'projected';
        const month = monthOf(index);

        return {
            month,
            kind,
            recurring: past ? 100000 : null,
            overage_and_other: past ? 25000 : null,
            expenses: past ? 5000 : null,
            contracted: kind === 'actual' ? 0 : 100000,
            projected_overage: kind === 'actual' ? null : 20000,
            total: past ? 130000 : 120000,
            href: `/workspaces/workspace-1/all/invoices?month=${month}`,
            clients: [
                {
                    client_id: 'company-1',
                    name: LONG_CLIENT,
                    total: 90000,
                    href: '/workspaces/workspace-1/clients/company-1',
                },
                {
                    client_id: 'company-2',
                    name: 'Synthetic Second Client',
                    total: 40000,
                    href: '/workspaces/workspace-1/clients/company-2',
                },
            ],
            others: index % 2 === 0 ? { count: 3, total: 1500 } : null,
        };
    });
}

export function dashboardRevenue(
    overrides: Partial<DashboardRevenue> = {},
): DashboardRevenue {
    return {
        toggle_href: '/workspaces/workspace-1/dashboard',
        filters: { basis: 'invoiced', expenses: true, overage: false },
        assumptions: {
            as_of: '2026-11-06',
            basis: 'invoiced',
            projection_basis: 'invoiced',
            currency: 'USD',
        },
        kpis: {
            ytd: {
                amount: 123456789,
                prior_amount: 100000000,
                change_percent: 23.5,
                from: '2026-01-01',
                to: '2026-11-06',
                prior_from: '2025-01-01',
                prior_to: '2025-11-06',
            },
            ttm: {
                amount: 150000000,
                prior_amount: 0,
                change_percent: null,
                from: '2025-11-01',
                to: '2026-11-06',
                prior_from: '2024-11-01',
                prior_to: '2025-11-06',
            },
            next_12_months: {
                from: '2026-12-01',
                to: '2027-11-30',
                contracted: 90000000,
                projected_overage: null,
            },
            open_receivables: {
                outstanding: 7000000,
                invoice_count: 4,
                overdue_amount: 3000000,
                overdue_count: 2,
            },
        },
        months: chartMonths(),
        at_risk: {
            agreements: [
                {
                    agreement_id: 'agreement-1',
                    client_id: 'company-1',
                    client_name: LONG_CLIENT,
                    title: LONG_TITLE,
                    ends_on: '2026-12-15',
                    monthly_amount: 50000,
                    client_href: '/workspaces/workspace-1/clients/company-1',
                    href: '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
                },
            ],
            monthly_total: 50000,
        },
        ...overrides,
    };
}

export function rosterRow(overrides: Partial<RosterRow> = {}): RosterRow {
    return {
        client_id: 'company-1',
        name: LONG_CLIENT,
        is_active: true,
        href: '/workspaces/workspace-1/clients/company-1',
        mrr: 123456789,
        ytd: 987654321,
        ttm: {
            amount: 1234567890,
            series: Array.from({ length: 12 }, (_, index) => ({
                month: monthOf(index),
                amount: 1000 * ((index * 7) % 11),
            })),
        },
        open_amount: 5000000,
        overdue_amount: 2000000,
        overdue_count: 2,
        ending_soon: true,
        ...overrides,
    };
}

export function rosterHealth(
    overrides: Partial<RosterHealth> = {},
): RosterHealth {
    return {
        status: 'ok',
        minutes_used: 540,
        minutes_included: 600,
        over_retainer: false,
        ...overrides,
    };
}

export function roster(overrides: Partial<Roster> = {}): Roster {
    return {
        href: '/workspaces/workspace-1/clients',
        filters: { q: '', filter: 'all', sort: 'name', dir: 'asc' },
        currency: 'USD',
        as_of: '2026-11-06',
        total_count: 2,
        clients: [
            rosterRow(),
            rosterRow({
                client_id: 'company-2',
                name: 'Synthetic Second Client',
                href: '/workspaces/workspace-1/clients/company-2',
                mrr: 50000,
                overdue_count: 0,
                overdue_amount: 0,
                open_amount: 0,
                ending_soon: false,
            }),
        ],
        totals: {
            mrr: 123506789,
            ytd: 987654321,
            ttm: 1234567890,
            open_amount: 5000000,
            overdue_amount: 2000000,
            overdue_count: 2,
        },
        ...overrides,
    };
}

export function clientOverview(
    overrides: Partial<ClientOverview> = {},
): ClientOverview {
    const revenue = dashboardRevenue();

    return {
        toggle_href: '/workspaces/workspace-1/clients/company-1',
        filters: revenue.filters,
        assumptions: revenue.assumptions,
        kpis: { mrr: 123456789, ...revenue.kpis },
        months: revenue.months,
        agreements: [
            {
                agreement_id: 'agreement-1',
                title: LONG_TITLE,
                status: 'active',
                billing_cadence: 'monthly',
                starts_on: '2026-01-01',
                ends_on: '2026-12-15',
                signed: true,
                retainer: {
                    period_fee: 100000,
                    monthly_equivalent: 100000,
                    first_cycle_proration: 'none',
                },
                monthly_recurring: 110000,
                recurring_items: [
                    {
                        item_id: 'item-1',
                        description: `Hosting ${'x'.repeat(70)}`,
                        cadence: 'quarterly',
                        amount: 30000,
                        is_active: true,
                        expires_on: null,
                        next_charge_on: '2027-01-01',
                    },
                ],
                href: '/workspaces/workspace-1/clients/company-1/agreements/agreement-1',
            },
        ],
        recent_invoices: [
            {
                id: 'invoice-1',
                invoice_number: `INV-${'9'.repeat(50)}`,
                status: 'partially_paid',
                currency: 'USD',
                issue_date: '2026-10-01',
                due_date: '2026-10-31',
                total_amount: 100000,
                paid_amount: 40000,
                balance_amount: 60000,
                href: '/workspaces/workspace-1/clients/company-1/invoices/invoice-1',
            },
        ],
        projects: [
            {
                id: 'project-1',
                name: `Project ${'LongProjectName'.repeat(8)}`,
                status: 'active',
                href: '/workspaces/workspace-1/clients/company-1/projects/project-1',
                log_time_href:
                    '/workspaces/workspace-1/clients/company-1/time?log_project=project-1',
            },
        ],
        proposals: [
            {
                id: 'proposal-1',
                title: `Proposal ${'Titled'.repeat(14)}`,
                status: 'sent',
                sent_at: '2026-10-15',
                href: '/workspaces/workspace-1/clients/company-1/proposals/proposal-1',
            },
        ],
        activities: [
            {
                id: 'activity-1',
                action: 'invoice.issued',
                actor_name: `Actor${'Unbroken'.repeat(8)}`,
                subject_type: null,
                subject_id: null,
                payload: {},
                correction_url: null,
                created_at: '2026-10-20T09:00:00.000Z',
            },
        ],
        ...overrides,
    };
}
