/**
 * Revenue payloads, as `RevenuePagePresenter` sends them (docs/ux-overhaul.md
 * sections 2 to 4). Money is minor units, tax excluded (open receivables
 * include it). Every figure is the server's: the browser prints them and
 * never adds, subtracts or re-derives one.
 */

import type { CompanyActivity } from '@/components/activity-timeline';

export type RevenueBasis = 'invoiced' | 'collected';

export type RevenueFilters = {
    basis: RevenueBasis;
    expenses: boolean;
    overage: boolean;
};

export type RevenueAssumptions = {
    as_of: string;
    basis: RevenueBasis;
    /** The basis the forecast is on, whatever `basis` says. */
    projection_basis: RevenueBasis;
    currency: string | null;
};

export type RevenueWindow = {
    amount: number;
    prior_amount: number;
    /** Null when there is no prior figure to compare with. */
    change_percent: number | null;
    from: string;
    to: string;
    prior_from: string;
    prior_to: string;
};

export type RevenueKpis = {
    ytd: RevenueWindow;
    ttm: RevenueWindow;
    next_12_months: {
        from: string;
        to: string;
        contracted: number;
        /** Null while the projected-overage toggle is off. */
        projected_overage: number | null;
    };
    open_receivables: {
        outstanding: number;
        invoice_count: number;
        overdue_amount: number;
        overdue_count: number;
    };
    /** The client overview only. */
    mrr?: number;
};

export type ChartClient = {
    client_id: string;
    name: string;
    total: number;
    href: string;
};

export type ChartMonth = {
    month: string;
    kind: 'actual' | 'current' | 'projected';
    recurring: number | null;
    overage_and_other: number | null;
    expenses: number | null;
    contracted: number | null;
    projected_overage: number | null;
    total: number;
    /** The invoices of this month. */
    href: string;
    /** The top five clients of the month. */
    clients: ChartClient[];
    others: { count: number; total: number } | null;
};

export type AtRiskAgreement = {
    agreement_id: string;
    client_id: string;
    client_name: string;
    title: string;
    ends_on: string;
    monthly_amount: number;
    client_href: string;
    href: string;
};

export type DashboardRevenue = {
    /** Where a toggle asks for fresh figures. */
    toggle_href: string;
    filters: RevenueFilters;
    assumptions: RevenueAssumptions;
    kpis: RevenueKpis;
    months: ChartMonth[];
    at_risk: { agreements: AtRiskAgreement[]; monthly_total: number };
};

export type ThisPeriodRow = {
    agreement_id: string;
    title: string;
    period_start: string;
    period_end: string;
    minutes_used: number | null;
    minutes_included: number | null;
    over_retainer: boolean | null;
    ends_within_60_days: boolean;
    ends_on: string | null;
    ledger_status: 'ok' | 'no_retainer' | 'ledger_unreadable';
    href: string;
};

export type AgreementCard = {
    agreement_id: string;
    title: string;
    status: string;
    billing_cadence: string;
    starts_on: string;
    ends_on: string | null;
    signed: boolean;
    retainer: {
        period_fee: number;
        monthly_equivalent: number;
        first_cycle_proration: string;
    };
    monthly_recurring: number;
    recurring_items: {
        item_id: string;
        description: string;
        cadence: string;
        amount: number;
        is_active: boolean;
        expires_on: string | null;
        next_charge_on: string | null;
    }[];
    href: string;
};

export type OverviewInvoice = {
    id: string;
    invoice_number: string;
    status: string;
    currency: string;
    issue_date: string | null;
    due_date: string | null;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    href: string;
};

export type ClientOverview = {
    toggle_href: string;
    filters: RevenueFilters;
    assumptions: RevenueAssumptions;
    kpis: RevenueKpis & { mrr: number };
    months: ChartMonth[];
    agreements: AgreementCard[];
    recent_invoices: OverviewInvoice[];
    projects: {
        id: string;
        name: string;
        status: string;
        href: string;
        log_time_href: string;
    }[];
    proposals: {
        id: string;
        title: string;
        status: string;
        sent_at: string | null;
        href: string;
    }[];
    /** The latest recorded events for this client, newest first. */
    activities: CompanyActivity[];
};

export type RosterRow = {
    client_id: string;
    name: string;
    is_active: boolean;
    href: string;
    mrr: number;
    ytd: number;
    ttm: { amount: number; series: { month: string; amount: number }[] };
    open_amount: number;
    overdue_amount: number;
    overdue_count: number;
    ending_soon: boolean;
};

export type RosterHealth = {
    status: 'none' | 'ok' | 'no_retainer' | 'ledger_unreadable';
    minutes_used: number | null;
    minutes_included: number | null;
    over_retainer: boolean;
};

export type RosterSort = 'name' | 'mrr' | 'ytd' | 'ttm' | 'open' | 'overdue';

export type RosterFilter =
    'all' | 'active' | 'ending_soon' | 'over_retainer' | 'overdue';

export type Roster = {
    href: string;
    filters: {
        q: string;
        filter: RosterFilter;
        sort: RosterSort;
        dir: 'asc' | 'desc';
    };
    currency: string | null;
    as_of: string;
    total_count: number;
    clients: RosterRow[];
    totals: {
        mrr: number;
        ytd: number;
        ttm: number;
        open_amount: number;
        overdue_amount: number;
        overdue_count: number;
    };
};
