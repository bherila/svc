/**
 * The client directory's two read-only screens.
 *
 * Money is minor units and durations are whole minutes, matching the columns
 * they come from - the formatting decision belongs to the reader's locale, not
 * to the payload.
 */

export type WorkspaceRef = {
    id: string;
    name: string;
};

/**
 * This period's draw on a company's retainer.
 *
 * Approved work only, over the cycle the agreement's cadence puts today in,
 * clipped to the agreement's own term. Nothing is carried in from earlier
 * cycles: the running balance lives on the time sheet, and this is the
 * narrower question of what this period has spent of what it sells.
 */
export type RetainerUsage = {
    agreement: string;
    period_start: string;
    period_end: string;
    capacity_minutes: number;
    used_minutes: number;
    remaining_minutes: number;
    over_minutes: number;
};

export type CompanyRow = {
    id: string;
    name: string;
    billing_email: string | null;
    is_active: boolean;
    project_count: number;
    draft_invoice_count: number;
    /** Issued and partially paid - invoices with money still outstanding. */
    open_invoice_count: number;
    retainer: RetainerUsage | null;
};

export type ClientIndexProps = {
    workspace: WorkspaceRef;
    companies: CompanyRow[];
};

export type CompanyHeader = {
    id: string;
    name: string;
    billing_email: string | null;
    is_active: boolean;
};

export type CompanyProject = {
    id: string;
    name: string;
    status: string;
    is_visible_to_client: boolean;
};

export type CompanyAgreement = {
    id: string;
    title: string;
    status: string;
    currency: string | null;
    billing_cadence: string | null;
    /** False for a one-time arrangement, whose retainer terms mean nothing. */
    is_recurring: boolean;
    starts_on: string | null;
    ends_on: string | null;
    signed_at: string | null;
    retainer_minutes_per_period: number | null;
    retainer_amount_per_period: number | null;
    hourly_rate_amount: number | null;
    rollover_months: number | null;
    /** Named only when the agreement is scoped to a project of this company. */
    project: string | null;
};

export type CompanyInvoice = {
    id: string;
    invoice_number: string | null;
    status: string;
    currency: string | null;
    issue_date: string | null;
    due_date: string | null;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
};

export type ClientShowProps = {
    workspace: WorkspaceRef;
    company: CompanyHeader;
    projects: CompanyProject[];
    agreements: CompanyAgreement[];
    /** How many invoices the screen will show at most. */
    invoice_limit: number;
    invoices: CompanyInvoice[];
};
