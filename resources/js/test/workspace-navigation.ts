import type {
    ClientNavigationOption,
    WorkspaceNavigation,
} from '@/types/navigation';

/**
 * A navbar payload shaped like the server's, for tests that render the shell.
 *
 * The destinations are the point: the real ones are generated per viewer after
 * authorization, and a test that concatenated ids instead would keep passing
 * after the frontend stopped reading them. So this hands out finished URLs the
 * same way, and a test that cares about the portal family or a missing module
 * overrides just that.
 */
export function clientOption(
    id: string,
    name: string,
    overrides: Partial<ClientNavigationOption['destinations']> = {},
): ClientNavigationOption {
    return {
        id,
        name,
        destinations: {
            home: `/workspaces/workspace-1/clients/${id}`,
            invoices: `/workspaces/workspace-1/clients/${id}/invoices`,
            time: `/workspaces/workspace-1/clients/${id}/time`,
            expenses: null,
            tasks: `/workspaces/workspace-1/clients/${id}/tasks`,
            ...overrides,
        },
    };
}

export function workspaceNavigation(
    overrides: Partial<WorkspaceNavigation> = {},
): WorkspaceNavigation {
    return {
        workspace_id: 'workspace-1',
        current_client_id: 'company-1',
        clients: [
            clientOption('company-1', 'Aa Synthetic Client'),
            clientOption('company-2', 'Bb Synthetic Client'),
        ],
        permissions: {
            manage_workspace: true,
            create_client: true,
            manage_current_client: true,
            search: true,
        },
        workspace_settings_href: null,
        ...overrides,
    };
}
