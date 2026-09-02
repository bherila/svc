/**
 * The chrome shared with every page inside an entered workspace.
 *
 * `ResolveWorkspaceNavigation` shares this on the routes that render a navbar,
 * so a page never supplies it - a page free to supply the switcher would be
 * free to supply a different one, which is how two views of the same navigation
 * drift apart.
 *
 * Note what is *not* here: the workspace's name. The bar names the client, and
 * the only way to change workspace is the SVC wordmark, so a workspace label
 * beside the switcher would read as a second, competing context. Leaving it out
 * of the payload makes that a property of the contract rather than a rule the
 * markup has to keep remembering.
 */

/** The five modules of a client, in the order the tab strip reads them. */
export type ClientModule = 'home' | 'invoices' | 'time' | 'expenses' | 'tasks';

/**
 * Finished URLs, generated per viewer after authorization.
 *
 * Only `home` is guaranteed. A route family need not serve every module - the
 * portal is a different family from the operator screens, and expenses have no
 * record in the schema yet - and a null hides that tab rather than linking
 * somewhere that does not exist.
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
    current_client_id: string | null;
    /** Every client this viewer may enter, alphabetically. */
    clients: ClientNavigationOption[];
    permissions: {
        manage_workspace: boolean;
        create_client: boolean;
        manage_current_client: boolean;
    };
    workspace_settings_href: string | null;
};
