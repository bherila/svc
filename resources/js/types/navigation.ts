/**
 * The chrome shared with every page inside an entered workspace.
 *
 * `ResolveWorkspaceNavigation` shares this on the routes that render a navbar,
 * so a page never supplies it - a page free to supply the switcher would be
 * free to supply a different one, which is how two views of the same navigation
 * drift apart.
 *
 * The workspace's name is here and used not to be. The old argument was that a
 * tenant label beside the switcher competes with it for the reader's attention;
 * what it missed is that an operator working across two workspaces had nothing
 * on the screen telling them which one they were in, and the way back out was a
 * wordmark that gave no sign of leading anywhere.
 */

/**
 * The modules of a client. The first six are the client tier's tabs, in the
 * order the strip reads them; the rest live in the menu on the client's name.
 */
export type ClientModule =
    | 'home'
    | 'agreements'
    | 'invoices'
    | 'time'
    | 'tasks'
    | 'files'
    | 'settings'
    | 'expenses'
    | 'notes'
    | 'discussions';

/** The workspace tier's tabs, shown while no client is selected. */
export type WorkspaceSection =
    | 'my_work'
    | 'dashboard'
    | 'clients'
    | 'invoices'
    | 'time'
    | 'finance'
    | 'submissions'
    | 'timesheet';

/**
 * Finished URLs, generated per viewer after authorization.
 *
 * Only `home` (the client's Overview) is guaranteed. A route family need not
 * serve every module - the portal is a different family from the operator
 * screens, and files and settings are for managers - and a null hides that tab
 * rather than linking somewhere that does not exist.
 */
export type ClientModuleDestinations = {
    home: string;
    agreements: string | null;
    invoices: string | null;
    time: string | null;
    tasks: string | null;
    files: string | null;
    settings: string | null;
    expenses: string | null;
    notes: string | null;
    discussions: string | null;
};

/** The workspace tier, chosen on the server per role; a null is not rendered. */
export type WorkspaceDestinations = {
    /** Members who do not run the workspace, and everyone in a light one; null for owners and admins of a full one. */
    my_work: string | null;
    /** A light workspace's own hours for the month, to submit. Null in a full one. */
    submissions: string | null;
    /** A light workspace's approved timesheet. Null in a full one. */
    timesheet: string | null;
    dashboard: string | null;
    clients: string | null;
    invoices: string | null;
    time: string | null;
    finance: string | null;
};

export type ClientNavigationOption = {
    id: string;
    name: string;
    destinations: ClientModuleDestinations;
};

export type WorkspaceNavigation = {
    workspace_id: string;
    /** Named in the bar, with the exit control beside it. */
    workspace_name: string;
    current_client_id: string | null;
    /** Every client this viewer may enter, alphabetically. */
    clients: ClientNavigationOption[];
    permissions: {
        manage_workspace: boolean;
        create_client: boolean;
        manage_current_client: boolean;
        /**
         * Whether the command palette has anything to find. Search covers the
         * workspaces this person is a member of, and a portal user is a member
         * of none - so for them the trigger was a control that could never
         * return a result.
         */
        search: boolean;
    };
    workspace_settings_href: string | null;
    mcp_setup_href: string | null;
    /** The Square mirror's explore screen, for managers where Square is connected. */
    square_href: string | null;
    history_href?: string | null;
    /** The authorized API destination for creating a client. */
    create_client_url?: string | null;
    /** Null for a viewer with no workspace tier (a portal user). */
    workspace_destinations?: WorkspaceDestinations | null;
    task_labels_href?: string | null;
    /** Owners and admins only; null for anyone else. */
    payments_href?: string | null;
    /** Shared projects this workspace hosts or takes part in; members only. */
    shared_projects_href?: string | null;
    /** Owners and admins only: who outside the team may review this workspace's hours. */
    reviewers_href?: string | null;
    /** Everything waiting for this person across providers; null when nothing could be. */
    approvals_href?: string | null;
    /** The member's own hours and expenses for a month, to submit for review. */
    submissions_href?: string | null;
    /** What a reviewer approved of the member's hours, for a month. */
    timesheet_href?: string | null;
    /** A partner's light workspace: agreements, invoicing and revenue are off. */
    light?: boolean;
    /** Where an owner turns the rest of SVC on; null unless the workspace is light and they manage it. */
    full_workspace_url?: string | null;
};
