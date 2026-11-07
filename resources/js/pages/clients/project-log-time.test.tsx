import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientProjectDetail from '@/pages/clients/project';

vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { visit: vi.fn() },
}));

const project = {
    id: 'project-1',
    name: 'Synthetic project',
    description: null,
    status: 'active',
    is_visible_to_client: true,
};

function renderProject(logTimeHref: string | null) {
    render(
        <ClientProjectDetail
            workspace={{ id: 'workspace-1' }}
            company={{ id: 'company-1', name: 'Synthetic client' }}
            project={project}
            tasks={[]}
            time={[{ status: 'approved', minutes: 90, entries: 2 }]}
            agreements={[]}
            log_time_href={logTimeHref}
        />,
    );
}

/**
 * The per-project time form was a link on the retired Operations screen. The
 * project's own page carries it now, as the server's finished URL.
 */
describe('logging time from the project page', () => {
    it('links to the time sheet with the log form open on this project', () => {
        renderProject(
            '/workspaces/workspace-1/clients/company-1/time?log_project=project-1',
        );

        expect(screen.getByRole('link', { name: 'Log time' })).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/time?log_project=project-1',
        );
    });

    it('offers nothing to a viewer the server sent no URL for', () => {
        renderProject(null);

        expect(screen.queryByRole('link', { name: 'Log time' })).toBeNull();
        expect(
            screen.getByRole('link', {
                name: 'The Time tab has the entries themselves.',
            }),
        ).toBeInTheDocument();
    });
});
