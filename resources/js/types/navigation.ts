/**
 * Chrome shared with every page, rather than passed by each one.
 *
 * The company switcher and the tab strip are the same on every client screen,
 * so they come from `HandleInertiaRequests` as one shared prop. A page that had
 * to supply them would be free to supply a different set - which is how two
 * views of the same navigation drift apart.
 */

export type ClientContextCompany = {
    id: string;
    name: string;
};

export type ClientContext = {
    workspace: {
        id: string;
        name: string;
    };
    /** Every company in this workspace, in the switcher's order. */
    companies: ClientContextCompany[];
    /** Null on a workspace screen that is not inside one company. */
    current_company_id: string | null;
    /**
     * Whether this viewer may manage the workspace.
     *
     * Decides only whether the Manage tab is offered. The action behind it
     * authorizes on its own, because a link nobody rendered is not a check.
     */
    can_manage: boolean;
};

export type ClientTab = {
    key: 'overview' | 'tasks' | 'time' | 'invoices' | 'manage';
    label: string;
    /** Appended to the company route; empty for Overview, which is the root. */
    segment: string;
};
