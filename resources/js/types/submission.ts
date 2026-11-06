/**
 * Submission and the approved timesheet (docs/ux-overhaul.md sections 7b and
 * 7e). Shapes the server sends; every URL is finished and every capability is
 * decided there.
 */

export type SubmissionState = 'draft' | 'submitted' | 'approved' | 'disputed';

type SubmissionRowBase = {
    id: string;
    project: string | null;
    state: SubmissionState;
    dispute_note: string | null;
    can_submit: boolean;
    /** Why it cannot be submitted, in words, when it cannot. */
    why_not: string | null;
    can_withdraw: boolean;
};

export type SubmissionHour = SubmissionRowBase & {
    worked_on: string;
    minutes: number;
    description: string;
    client_description: string | null;
};

export type SubmissionExpense = SubmissionRowBase & {
    spent_on: string;
    description: string;
    amount: number;
    currency: string;
};

export type SubmissionsData = {
    period: string;
    period_label: string;
    previous_period: string;
    next_period: string;
    time: SubmissionHour[];
    expenses: SubmissionExpense[];
    /** Minutes of time in each state. */
    totals: Record<SubmissionState, number>;
    truncated: boolean;
};

export type SubmissionsProps = {
    workspace: { id: string; name: string };
    submissions: SubmissionsData;
    links: {
        submit_url: string;
        withdraw_url: string;
        previous_href: string;
        next_href: string;
        timesheet_href: string;
        my_work_href: string;
    };
};

export type TimesheetEntry = {
    id: string;
    worked_on: string;
    minutes: number;
    description: string;
    project: string | null;
    task: string | null;
    worked_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    approved_via: 'Reviewer' | 'Client' | 'Payment' | 'Unknown';
};

export type TimesheetData = {
    period: string;
    period_label: string;
    previous_period: string;
    next_period: string;
    entries: TimesheetEntry[];
    total_minutes: number;
    truncated: boolean;
};

export type TimesheetProps = {
    workspace: { id: string; name: string };
    timesheet: TimesheetData;
    links: {
        previous_href: string;
        next_href: string;
        csv_href: string;
        pdf_href: string;
        submissions_href: string;
    };
};
