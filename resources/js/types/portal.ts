/**
 * The client portal's home (docs/ux-overhaul.md section 6).
 *
 * Everything here is the client's own and already narrowed on the server: no
 * rate, cost, internal note or workspace-internal field is in the payload, and
 * the URLs are finished.
 */

export type PortalBalanceInvoice = {
    id: string;
    number: string;
    balance: number;
    currency: string;
    due_date: string | null;
    /** Whole days from today to the due date; negative once late. */
    days_until_due: number | null;
};

export type PortalBalance = {
    /** Null when the open invoices are in more than one currency. */
    amount_due: number | null;
    currency: string | null;
    invoice_count: number;
    overdue: boolean;
    overdue_amount: number | null;
    /** The invoice to deal with first, or null when nothing is owed. */
    first: PortalBalanceInvoice | null;
};

export type PortalLastPayment = {
    amount: number;
    currency: string;
    received_on: string;
};

export type PortalPay = {
    /** The invoice's hosted payment page; null when it has none. */
    href: string | null;
    /** The invoice's own page in the portal. */
    invoice_href: string | null;
};

export type PortalEstimatePart = {
    kind: string;
    description: string;
    /** Minor units, before tax; null for overage the agreement states no rate for. */
    amount: number | null;
    date: string | null;
    hours: number | null;
};

export type PortalNextInvoice = {
    period_start: string;
    period_end: string;
    currency: string | null;
    total: number;
    parts: PortalEstimatePart[];
    /** What the estimate leaves out, in words. */
    excludes: string;
};

export type PortalThisPeriod = {
    agreement: { id: string; title: string; href: string };
    period_start: string;
    period_end: string;
    /** Null when the agreement grants no retainer hours or the ledger could not be read. */
    use: {
        minutes_used: number;
        minutes_included: number;
        rollover_minutes: number;
        overage_minutes: number;
    } | null;
    next_invoice: PortalNextInvoice | null;
    /** Present only when the agreement lets the client see its deferred backlog. */
    still_deferred: {
        entry_count: number;
        total_hours: number;
        oldest_worked_on: string;
    } | null;
};

export type PortalInvoiceRow = {
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

export type PortalHomeProps = {
    company: { id: string; name: string };
    balance: PortalBalance;
    last_payment: PortalLastPayment | null;
    pay: PortalPay;
    this_period: PortalThisPeriod | null;
    recent_invoices: PortalInvoiceRow[];
    recent_time: {
        id: string;
        worked_on: string;
        project: string | null;
        description: string | null;
        minutes: number;
    }[];
    open_tasks: {
        id: string;
        title: string;
        project: string | null;
        status: string;
        files_href?: string | null;
    }[];
    engagement: {
        proposal_title: string | null;
        proposal_href: string | null;
    } | null;
    links: {
        invoices: string | null;
        time: string | null;
        tasks: string | null;
    };
};
