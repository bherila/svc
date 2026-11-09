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
            agreements: `/workspaces/workspace-1/clients/${id}/agreements`,
            invoices: `/workspaces/workspace-1/clients/${id}/invoices`,
            time: `/workspaces/workspace-1/clients/${id}/time`,
            tasks: `/workspaces/workspace-1/clients/${id}/tasks`,
            files: `/workspaces/workspace-1/attachment-records/company/${id}/files`,
            settings: `/workspaces/workspace-1/clients/${id}/settings`,
            billing: null,
            services: null,
            expenses: null,
            notes: `/workspaces/workspace-1/clients/${id}/collaboration?kind=note`,
            discussions: `/workspaces/workspace-1/clients/${id}/collaboration?kind=discussion`,
            ...overrides,
        },
    };
}

export function workspaceNavigation(
    overrides: Partial<WorkspaceNavigation> = {},
): WorkspaceNavigation {
    return {
        create_client_url:
            (overrides.permissions?.create_client ?? true)
                ? '/api/v1/workspaces/workspace-1/clients'
                : null,
        workspace_id: 'workspace-1',
        workspace_name: 'Synthetic Workspace',
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
        mcp_setup_href: '/workspaces/workspace-1/mcp',
        square_href: null,
        workspace_destinations: {
            my_work: null,
            submissions: null,
            timesheet: null,
            dashboard: '/workspaces/workspace-1/dashboard',
            clients: '/workspaces/workspace-1/clients',
            invoices: '/workspaces/workspace-1/all/invoices',
            time: '/workspaces/workspace-1/all/time',
            finance: '/workspaces/workspace-1/finance',
        },
        task_labels_href: '/workspaces/workspace-1/task-labels',
        payments_href: '/workspaces/workspace-1/payments',
        shared_projects_href: '/workspaces/workspace-1/shared-projects',
        reviewers_href: '/workspaces/workspace-1/reviewers',
        approvals_href: null,
        packages_href: '/workspaces/workspace-1/packages',
        ...overrides,
    };
}
