/**
 * The "My work" payload (docs/ux-overhaul.md section 5).
 *
 * Nothing here prices work: a task, a project and a span of minutes, and no
 * rate, amount, invoice or revenue figure, for any viewer.
 */

export type MyWorkTask = {
    id: string;
    title: string;
    status: string;
    due_on: string | null;
    project: string;
    client: string;
    /** The task's own page. */
    href: string;
    /** Finished by the server for a project owner or manager; null for everyone else. */
    update_url: string | null;
    version: string | null;
};

export type MyWorkTaskGroup = {
    key: 'overdue' | 'today' | 'this_week' | 'later' | 'no_date';
    label: string;
    tasks: MyWorkTask[];
};

export type MyWorkProject = {
    id: string;
    name: string;
    client: string;
    log_time_url: string;
};

export type MyTimeProject = {
    project_id: string;
    project: string;
    minutes: number;
    states: { status: string; minutes: number }[];
};

export type MyTimeSpan = {
    start: string;
    end: string;
    total_minutes: number;
    projects: MyTimeProject[];
};

export type MyWorkProps = {
    workspace: { id: string; name: string };
    /** The workspace's calendar day, so the form's date is right whatever the browser's clock says. */
    today: string;
    tasks: MyWorkTaskGroup[];
    projects: MyWorkProject[];
    time: { week: MyTimeSpan; month: MyTimeSpan };
};
