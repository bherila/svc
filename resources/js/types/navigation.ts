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

/** The five modules of a client, in the order the tab strip reads them. */
export type ClientModule = 'home' | 'invoices' | 'time' | 'expenses' | 'tasks';

/**
 * Finished URLs, generated per viewer after authorization.
 *
 * Only `home` is guaranteed. A route family need not serve every module - the
 * portal is a different family from the operator screens, and expenses are on
 * the operator screens only for now (#75) - and a null hides that tab rather
 * than linking somewhere that does not exist.
 */
export type ClientModuleDestinations = {
    home: string;
    invoices: string | null;
    time: string | null;
    expenses: string | null;
    tasks: string | null;
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
};
