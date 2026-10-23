import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientProjectDetail from '@/pages/clients/project';
import ClientTasks from '@/pages/clients/tasks';

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

describe('task read pagination', () => {
    it('uses authorized server pagination URLs without requiring task management', () => {
        render(
            <ClientTasks
                company={{ id: 'company', name: 'Synthetic client' }}
                audience="operator"
                filters={{ project_id: 'project' }}
                projects={[{ id: 'project', name: 'Synthetic project' }]}
                tasks={[]}
                pagination={{
                    previous_href: '/authorized/previous?project=project',
                    next_href: '/authorized/next?project=project',
                }}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Previous tasks' }),
        ).toHaveAttribute('href', '/authorized/previous?project=project');
        expect(
            screen.getByRole('link', { name: 'Next tasks' }),
        ).toHaveAttribute('href', '/authorized/next?project=project');
        expect(
            screen.queryByRole('button', { name: 'New task' }),
        ).not.toBeInTheDocument();
    });

    it('gives a project viewer the server read route for the complete task list', () => {
        render(
            <ClientProjectDetail
                workspace={{ id: 'workspace' }}
                company={{ id: 'company', name: 'Synthetic client' }}
                project={{
                    id: 'project',
                    name: 'Synthetic project',
                    description: null,
                    status: 'active',
                    is_visible_to_client: false,
                }}
                tasks={[
                    {
                        id: 'task',
                        title: 'Synthetic preview task',
                        status: 'open',
                        is_visible_to_client: false,
                        completed_at: null,
                    },
                ]}
                time={[]}
                agreements={[]}
                tasks_next_href="/authorized/read-tasks?project=project"
            />,
        );
        expect(
            screen.getByRole('link', { name: 'View all tasks' }),
        ).toHaveAttribute('href', '/authorized/read-tasks?project=project');
        expect(
            screen.queryByRole('button', { name: 'New task' }),
        ).not.toBeInTheDocument();
    });
});
