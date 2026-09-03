export type TaskOption = {
    id: string;
    title: string;
};

export type ProjectOption = {
    id: string;
    name: string;
    can_log_time: boolean;
    tasks: TaskOption[];
};

export type CompanyOption = {
    id: string;
    name: string;
    projects: ProjectOption[];
};

export type InvoiceLink = {
    id: string;
    number: string | null;
    status: string;
};

export type TimeEntry = {
    id: string;
    version: string;
    worked_on: string;
    minutes: number;
    description: string;
    client_visible_description: string | null;
    is_billable: boolean;
    is_deferred: boolean;
    is_visible_to_client: boolean;
    subcontractor_billing_mode?: 'flat_hourly' | 'retainer' | 'direct' | null;
    status: string;
    project: { id: string; name: string };
    task: { id: string; title: string } | null;
    worker: string | null;
    invoice: InvoiceLink | null;
    can_edit: boolean;
    can_approve: boolean;
};

export type Capacity = {
    agreement: string;
    /** Identifies the cycle: a mid-month cadence puts two in one month. */
    cycle_start: string;
    available_hours: number;
    /**
     * Where the availability came from: this cycle's own grant, what carried
     * in from earlier months, and what aged out on the way.
     *
     * One `available_hours` figure is unarguable and unexplainable — a month
     * living on carried hours looks identical to one with a large retainer, and
     * the hours that expired are invisible. The ledger has computed all three
     * since the port; only the screen was missing them.
     */
    retainer_hours: number;
    rollover_in_hours: number;
    expired_hours: number;
    /**
     * How many months unused hours survive, by the agreement's own rule.
     *
     * Null is not zero. An unset rollover means nothing carries forward, which
     * is the same outcome and a different statement about what was agreed.
     */
    rollover_months: number | null;
    worked_hours: number;
    unused_hours: number;
    over_hours: number;
    carried_deficit_hours: number;
    remaining_rollover: number;
    /** Draft work that will draw on *this* retainer once approved. */
    pending_minutes: number;
};

export type Month = {
    key: string;
    label: string;
    total_minutes: number;
    billable_minutes: number;
    deferred_minutes: number;
    capacity: Capacity[];
    entries: TimeEntry[];
};

export type TimeSheetProps = {
    workspace: {
        id: string;
        name: string;
        default_currency: string;
        /** The workspace's calendar; date defaults are read on it. */
        timezone: string;
    };
    /** How many entries one approval request may carry. */
    approval_limit: number;
    /**
     * Which company the sheet actually read.
     *
     * The route names it and the navbar shows it, so the page renders nothing
     * from this - it is the server stating what it resolved, which is the thing
     * worth asserting when a route parameter silently does nothing.
     */
    filters: { company_id: string | null };
    companies: CompanyOption[];
    months: Month[];
};
