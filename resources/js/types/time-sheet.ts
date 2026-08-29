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
    available_hours: number;
    worked_hours: number;
    unused_hours: number;
    over_hours: number;
    carried_deficit_hours: number;
    remaining_rollover: number;
};

export type Month = {
    key: string;
    label: string;
    total_minutes: number;
    billable_minutes: number;
    deferred_minutes: number;
    pending_minutes: number;
    capacity: Capacity[];
    entries: TimeEntry[];
};

export type TimeSheetProps = {
    workspace: { id: string; name: string; default_currency: string };
    filters: { company_id: string | null };
    companies: CompanyOption[];
    months: Month[];
};
