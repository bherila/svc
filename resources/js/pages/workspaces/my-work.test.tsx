import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MyWork from '@/pages/workspaces/my-work';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { MyWorkProps } from '@/types/my-work';

const api = vi.hoisted(() => ({ apiRequest: vi.fn() }));
const refresh = vi.hoisted(() => ({ refreshPageProps: vi.fn() }));
const style = vi.hoisted(() => ({ value: 'decimal' as 'decimal' | 'clock' }));

vi.mock('@/lib/api', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    apiRequest: api.apiRequest,
}));
vi.mock('@/lib/refresh-page-props', () => refresh);
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: vi.fn() },
    usePage: () => {
        const shared = sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        });

        return {
            props: {
                ...shared,
                auth: {
                    user: { ...shared.auth.user!, duration_style: style.value },
                },
            },
        };
    },
}));

const LONG = 'Unbroken'.repeat(12);

function props(overrides: Partial<MyWorkProps> = {}): MyWorkProps {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        today: '2026-11-06',
        tasks: [
            {
                key: 'overdue',
                label: 'Overdue',
                tasks: [
                    {
                        id: 'task-1',
                        title: 'Managed synthetic task',
                        status: 'open',
                        due_on: '2026-11-01',
                        project: 'Synthetic Project',
                        client: 'Synthetic Client',
                        href: '/workspaces/workspace-1/tasks/task-1',
                        update_url:
                            '/api/v1/workspaces/workspace-1/tasks/task-1',
                        version: 'v'.repeat(64),
                    },
                ],
            },
            {
                key: 'no_date',
                label: 'No due date',
                tasks: [
                    {
                        id: 'task-2',
                        title: 'Read only synthetic task',
                        status: 'in_progress',
                        due_on: null,
                        project: 'Synthetic Project',
                        client: 'Synthetic Client',
                        href: '/workspaces/workspace-1/tasks/task-2',
                        update_url: null,
                        version: null,
                    },
                ],
            },
        ],
        projects: [
            {
                id: 'project-1',
                name: 'Synthetic Project',
                client: 'Synthetic Client',
                log_time_url: '/api/v1/workspaces/workspace-1/time-entries',
            },
        ],
        time: {
            week: {
                start: '2026-11-02',
                end: '2026-11-08',
                total_minutes: 150,
                projects: [
                    {
                        project_id: 'project-1',
                        project: 'Synthetic Project',
                        minutes: 150,
                        states: [
                            { status: 'draft', minutes: 60 },
                            { status: 'approved', minutes: 90 },
                        ],
                    },
                ],
            },
            month: {
                start: '2026-11-01',
                end: '2026-11-30',
                total_minutes: 0,
                projects: [],
            },
        },
        ...overrides,
    };
}

beforeEach(() => {
    api.apiRequest.mockReset();
    refresh.refreshPageProps.mockReset();
    refresh.refreshPageProps.mockResolvedValue(undefined);
    style.value = 'decimal';
});

describe('My work', () => {
    it('groups tasks by due date and prints statuses as words', () => {
        render(<MyWork {...props()} />);

        const overdue = screen.getByRole('region', { name: 'Overdue' });

        expect(
            within(overdue).getByRole('link', {
                name: 'Managed synthetic task',
            }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/tasks/task-1');
        expect(
            screen.getByRole('region', { name: 'No due date' }),
        ).toBeInTheDocument();
        expect(screen.getByText('In progress')).toBeInTheDocument();
        expect(screen.queryByText('in_progress')).not.toBeInTheDocument();
    });

    it('offers a status control only where the server sent an update URL', () => {
        render(<MyWork {...props()} />);

        expect(
            screen.getByRole('combobox', {
                name: 'Status of Managed synthetic task',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('combobox', {
                name: 'Status of Read only synthetic task',
            }),
        ).not.toBeInTheDocument();
        // The control shows the status as words, never the stored value.
        expect(screen.getByText('Open')).toBeInTheDocument();
    });

    it('changes a status with the version it read', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 200, data: {} });
        render(<MyWork {...props()} />);

        await user.click(
            screen.getByRole('combobox', {
                name: 'Status of Managed synthetic task',
            }),
        );
        await user.click(
            await screen.findByRole('option', { name: 'Completed' }),
        );

        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));
        expect(api.apiRequest).toHaveBeenCalledWith(
            'PATCH',
            '/api/v1/workspaces/workspace-1/tasks/task-1',
            { status: 'completed', expected_version: 'v'.repeat(64) },
        );
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('teaches what the empty task list means', () => {
        render(<MyWork {...props({ tasks: [] })} />);

        expect(
            screen.getByText(/Nothing is assigned to you right now/),
        ).toBeInTheDocument();
    });

    it('logs time against the chosen project with the typed duration', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 201, data: {} });
        render(<MyWork {...props()} />);

        const duration = screen.getByLabelText('Duration');
        await user.clear(duration);
        await user.type(duration, '2:30');
        await user.tab();
        await user.type(
            screen.getByLabelText('What did you do?'),
            'Wrote the synthetic thing',
        );
        await user.click(screen.getByRole('button', { name: /Save hours/ }));

        await waitFor(() => expect(api.apiRequest).toHaveBeenCalledTimes(1));

        const [method, url, body] = api.apiRequest.mock.calls[0];

        expect(method).toBe('POST');
        expect(url).toBe('/api/v1/workspaces/workspace-1/time-entries');
        expect(body.entries[0]).toMatchObject({
            project_id: 'project-1',
            worked_on: '2026-11-06',
            minutes: 150,
            description: 'Wrote the synthetic thing',
            task_id: null,
        });
        // Nothing here prices the work.
        expect(JSON.stringify(body)).not.toMatch(/rate|amount|currency/);
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('will not save without a description', () => {
        render(<MyWork {...props()} />);

        expect(
            screen.getByRole('button', { name: /Save hours/ }),
        ).toBeDisabled();
    });

    it('says why the form is missing when the member holds no project', () => {
        render(<MyWork {...props({ projects: [] })} />);

        expect(
            screen.getByText(/once you are a contributor on a project/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Save hours/ }),
        ).not.toBeInTheDocument();
    });

    it('shows the server refusal instead of pretending it saved', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'The synthetic refusal.',
        });
        render(<MyWork {...props()} />);

        await user.type(screen.getByLabelText('What did you do?'), 'Work');
        await user.click(screen.getByRole('button', { name: /Save hours/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'The synthetic refusal.',
        );
        expect(refresh.refreshPageProps).not.toHaveBeenCalled();
    });

    it('lists my time by project with each review state', () => {
        render(<MyWork {...props()} />);

        const week = screen.getByRole('region', { name: 'This week' });

        expect(within(week).getAllByText('2.5 h')).toHaveLength(2);
        expect(
            within(week).getByText('Draft 1 h · Approved 1.5 h'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Nothing logged in/)).toBeInTheDocument();
    });

    it('reads durations in the style the person chose', () => {
        style.value = 'clock';
        render(<MyWork {...props()} />);

        expect(screen.getAllByText('2:30').length).toBeGreaterThan(0);
    });

    it('holds with unbroken names and no overflow risk', () => {
        const hostile = props();
        hostile.tasks[0].tasks[0].title = LONG;
        hostile.tasks[0].tasks[0].project = LONG;
        hostile.tasks[0].tasks[0].client = LONG;
        hostile.projects[0].name = LONG;
        hostile.projects[0].client = LONG;
        hostile.time.week.projects[0].project = LONG;

        const { container } = render(<MyWork {...hostile} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
