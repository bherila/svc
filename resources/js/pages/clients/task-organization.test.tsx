import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TaskOrganizationPage from '@/pages/clients/task-organization';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type { TaskOrganizationPageProps } from '@/types/task-organization';

const { request, reload, newKey } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    newKey: vi.fn(),
}));
vi.mock('@/lib/api', () => ({
    apiRequest: request,
    newIdempotencyKey: newKey,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { reload },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

function fixture(): TaskOrganizationPageProps {
    const listIds = [
        '10000000-0000-4000-8000-000000000001',
        '10000000-0000-4000-8000-000000000002',
    ];
    const taskIds = [
        '20000000-0000-4000-8000-000000000001',
        '20000000-0000-4000-8000-000000000002',
        '20000000-0000-4000-8000-000000000003',
    ];

    return {
        company: { name: 'Synthetic client', href: '/synthetic/client' },
        project: {
            id: '30000000-0000-4000-8000-000000000001',
            name: 'Synthetic project',
            href: '/synthetic/project',
        },
        organization: {
            graph_version: 'a'.repeat(64),
            lists_version: 'b'.repeat(64),
            create_list_href: '/synthetic/finished-list-create',
            reorder_lists_href: '/synthetic/finished-list-order',
            ordered_list_ids: listIds,
            assignees_href: '/synthetic/people',
            unlisted: { browse_href: '/synthetic/unlisted', move_target: null },
            lists: listIds.map((id, index) => ({
                id,
                name: index === 0 ? 'Discovery' : 'Delivery',
                version: 'c'.repeat(64),
                is_visible_to_client: false,
                archived: false,
                browse_href: `/synthetic/browse-${index}`,
                update_href: `/synthetic/edit-${index}`,
                archive_href: `/synthetic/archive-${index}`,
                restore_href: null,
                move_target: null,
            })),
            container: {
                title: 'Unlisted tasks',
                target: { parent_id: null, list_id: null },
                move_target: null,
                sharing_note:
                    'This project is internal. Its tasks stay hidden from clients.',
                version: 'd'.repeat(64),
                create_task_href: '/synthetic/finished-task-create',
                reorder_href: '/synthetic/finished-task-order',
                breadcrumbs: [],
                tasks: taskIds.map((id, index) => ({
                    id,
                    title: ['First task', 'Second task', 'Third task'][index],
                    status_label: 'Open',
                    version: 'e'.repeat(64),
                    is_visible_to_client: index === 0,
                    effective_visible_to_client: false,
                    task_href: `/synthetic/editor-${index}`,
                    browse_href: `/synthetic/children-${index}`,
                    create_child_href: `/synthetic/create-child-${index}`,
                    child_target: { parent_id: id, list_id: null },
                    move_picker_href: `/synthetic/pick-${index}`,
                    move_target: null,
                })),
            },
            moving: null,
        },
    };
}

beforeEach(() => {
    request.mockReset().mockResolvedValue({
        ok: true,
        status: 200,
        data: { affected_ids: [] },
    });
    reload
        .mockReset()
        .mockImplementation((options?: { onFinish?: () => void }) =>
            options?.onFinish?.(),
        );
    newKey.mockReset().mockReturnValue('synthetic-stable-retry-key');
});

describe('task organization', () => {
    it('creates an internal subtask using the supplied target and graph revision', async () => {
        const props = fixture();
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'New subtask' })[0],
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Title'), {
            target: { value: 'Synthetic child' },
        });
        fireEvent.change(within(dialog).getByLabelText('Description'), {
            target: { value: 'Complete synthetic description' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Create task' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'POST',
                '/synthetic/create-child-0',
                {
                    expected_graph_version: props.organization.graph_version,
                    parent_id: props.organization.container.tasks[0].id,
                    list_id: null,
                    task: {
                        title: 'Synthetic child',
                        description: 'Complete synthetic description',
                        is_visible_to_client: false,
                        starts_on: null,
                        due_on: null,
                    },
                },
                'synthetic-stable-retry-key',
            ),
        );
    });

    it('saves the complete sibling order and the rendered container revision', async () => {
        const props = fixture();
        const ids = props.organization.container.tasks.map((task) => task.id);
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Move Second task up' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save task order' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'PUT',
                '/synthetic/finished-task-order',
                {
                    parent_id: null,
                    list_id: null,
                    ordered_ids: [ids[1], ids[0], ids[2]],
                    expected_container_version:
                        props.organization.container.version,
                },
                'synthetic-stable-retry-key',
            ),
        );
    });

    it('keeps a lost-answer retry key while retaining the entered list name', async () => {
        request.mockResolvedValueOnce({
            ok: false,
            status: 0,
            message: 'Synthetic lost answer. Try again.',
        });
        const props = fixture();
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'New list' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('List name'), {
            target: { value: 'Synthetic new list' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Create list' }),
        );
        await waitFor(() =>
            expect(within(dialog).getByRole('alert')).toHaveTextContent(
                'Synthetic lost answer',
            ),
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Create list' }),
        );
        await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
        expect(newKey).toHaveBeenCalledTimes(1);
        expect(request.mock.calls[0]).toEqual(request.mock.calls[1]);
        expect(request.mock.calls[1][2]).toEqual({
            name: 'Synthetic new list',
            is_visible_to_client: false,
            expected_graph_version: props.organization.graph_version,
        });
    });

    it('offers only supplied move targets and sends the selected task revision', async () => {
        const props = fixture();
        props.organization.moving = {
            id: props.organization.container.tasks[0].id,
            title: 'First task',
            version: 'f'.repeat(64),
            move_href: '/synthetic/finished-move',
            cancel_href: '/synthetic/finished-cancel',
        };
        props.organization.container.move_target = {
            parent_id: null,
            list_id: props.organization.lists[1].id,
        };
        render(<TaskOrganizationPage {...props} />);
        expect(
            screen.queryByRole('button', { name: 'Move under this task' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Cancel move' }),
        ).toHaveAttribute('href', '/synthetic/finished-cancel');
        fireEvent.click(screen.getByRole('button', { name: 'Move here' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'PATCH',
                '/synthetic/finished-move',
                {
                    expected_version: 'f'.repeat(64),
                    expected_graph_version: props.organization.graph_version,
                    parent_id: null,
                    list_id: props.organization.lists[1].id,
                },
                'synthetic-stable-retry-key',
            ),
        );
    });

    it('requires explicit archive confirmation and preserves the list and graph versions', async () => {
        const props = fixture();
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Archive list' })[0],
        );
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm archive' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'DELETE',
                '/synthetic/archive-0',
                {
                    expected_version: props.organization.lists[0].version,
                    expected_graph_version: props.organization.graph_version,
                    confirm: true,
                },
                'synthetic-stable-retry-key',
            ),
        );
    });

    it('reorders all active lists while retaining archived lists outside the order', async () => {
        const props = fixture();
        const ids = props.organization.ordered_list_ids;
        props.organization.lists.push({
            ...props.organization.lists[0],
            id: '10000000-0000-4000-8000-000000000003',
            name: 'Archived synthetic list',
            archived: true,
            archive_href: null,
            restore_href: '/synthetic/restore-archived',
        });
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Move Delivery up' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save list order' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'PUT',
                '/synthetic/finished-list-order',
                {
                    ordered_ids: [ids[1], ids[0]],
                    expected_container_version:
                        props.organization.lists_version,
                },
                'synthetic-stable-retry-key',
            ),
        );
        expect(screen.getByText('Archived synthetic list')).toBeInTheDocument();
    });

    it('edits list sharing with both revisions and the supplied path', async () => {
        const props = fixture();
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Edit list' })[0],
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('List name'), {
            target: { value: 'Updated synthetic list' },
        });
        fireEvent.click(
            within(dialog).getByRole('switch', { name: 'Visible to client' }),
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Save list' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'PATCH',
                '/synthetic/edit-0',
                {
                    name: 'Updated synthetic list',
                    is_visible_to_client: true,
                    expected_version: props.organization.lists[0].version,
                    expected_graph_version: props.organization.graph_version,
                },
                'synthetic-stable-retry-key',
            ),
        );
    });

    it('restores an archived list only after confirmation without adding body identifiers', async () => {
        const props = fixture();
        props.organization.lists[0].archived = true;
        props.organization.lists[0].archive_href = null;
        props.organization.lists[0].restore_href = '/synthetic/restore-0';
        props.organization.ordered_list_ids = [props.organization.lists[1].id];
        render(<TaskOrganizationPage {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Restore list' }));
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm restore' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'POST',
                '/synthetic/restore-0',
                {
                    expected_version: props.organization.lists[0].version,
                    expected_graph_version: props.organization.graph_version,
                },
                'synthetic-stable-retry-key',
            ),
        );
    });

    it('discards a stale editor before reloading after a revision conflict', async () => {
        request.mockResolvedValueOnce({
            ok: false,
            status: 409,
            message: 'Synthetic stale revision',
        });
        render(<TaskOrganizationPage {...fixture()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Edit list' })[0],
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Save list',
            }),
        );
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'Synthetic stale revision',
            ),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload current state' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(reload).toHaveBeenCalledOnce();
    });

    it('shows effective sharing and retains hostile names without horizontal overflow risks', () => {
        const props = fixture();
        props.company.name = 'SyntheticClient'.repeat(40);
        props.project.name = 'SyntheticProject'.repeat(40);
        props.organization.lists[0].name = 'SyntheticList'.repeat(40);
        props.organization.container.tasks[0].title = 'SyntheticTask'.repeat(
            40,
        );
        const { container } = render(<TaskOrganizationPage {...props} />);
        expect(
            screen.getByText('Hidden by project or parent'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(props.organization.container.sharing_note!),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: props.organization.container.tasks[0].title,
            }),
        ).toHaveAttribute('href', '/synthetic/editor-0');
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
