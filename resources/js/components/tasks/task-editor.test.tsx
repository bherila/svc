import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskEditor } from '@/components/tasks/task-editor';
import type { EditableTask } from '@/components/tasks/task-editor';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const { submit } = vi.hoisted(() => ({ submit: vi.fn() }));
vi.mock('@/lib/api', () => ({
    apiRequest: submit,
    newIdempotencyKey: () => crypto.randomUUID(),
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(async () => {}),
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );

            return {
                data,
                errors,
                clearErrors: () => setErrors({}),
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                setError: (key: string, value: string) =>
                    setErrors((current) => ({ ...current, [key]: value })),
                reset: () => setData(initial),
            };
        },
    };
});

const person = {
    id: 'synthetic-person',
    name: 'Synthetic project contributor',
};
const task: EditableTask = {
    id: 'synthetic-task',
    title: 'Synthetic task',
    description: 'Synthetic description',
    status: 'completed',
    is_visible_to_client: false,
    starts_on: '2026-10-20',
    due_on: '2026-10-30',
    assignees: [person],
    version: 'synthetic-opaque-version',
    update_action: '/synthetic/task-update',
    assignees_action: '/synthetic/staff-lookup',
    assignee_options: { data: [person], has_more: false },
};

beforeEach(() => {
    vi.useFakeTimers();
    submit.mockReset();
    vi.mocked(refreshPageProps).mockReset();
    vi.mocked(refreshPageProps).mockResolvedValue();
    submit.mockResolvedValue({
        ok: true,
        status: 200,
        data: { version: 'saved-version' },
    });
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ data: [person], has_more: false }),
        }),
    );
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

async function loadOptions() {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(250);
    });
}

describe('task editor', () => {
    it('renders initial staff immediately and makes no mount request', async () => {
        render(<TaskEditor task={task} />);
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).toBeChecked();
        expect(fetch).not.toHaveBeenCalled();
        await loadOptions();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('accepts an empty initial page and its has-more state without requesting it again', async () => {
        render(
            <TaskEditor
                task={{
                    ...task,
                    assignee_options: { data: [], has_more: true },
                }}
            />,
        );
        expect(screen.getByText('No matching staff loaded.')).toBeVisible();
        expect(screen.getByText(/More staff match/)).toBeVisible();
        await loadOptions();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('debounces typed searches and restores the initial page when cleared', async () => {
        const match = {
            id: 'synthetic-remote',
            name: 'Synthetic remote match',
        };
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ data: [match], has_more: false }),
        } as Response);
        render(
            <TaskEditor
                task={{
                    ...task,
                    assignee_options: { data: [person], has_more: true },
                }}
            />,
        );
        const search = screen.getByLabelText('Find staff with project access');
        fireEvent.change(search, { target: { value: 'remote' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(199);
        });
        expect(fetch).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1);
        });
        expect(fetch).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ search: '?q=remote' }),
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(
            screen.getByRole('checkbox', { name: match.name }),
        ).toBeVisible();
        expect(screen.queryByText(/More staff match/)).not.toBeInTheDocument();

        fireEvent.change(search, { target: { value: '' } });
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).toBeVisible();
        expect(
            screen.queryByRole('checkbox', { name: match.name }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/More staff match/)).toBeVisible();
        await loadOptions();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows failed remote lookups without replacing the initial answer', async () => {
        vi.mocked(fetch).mockRejectedValueOnce(
            new Error('Synthetic network error'),
        );
        render(<TaskEditor task={task} />);
        const search = screen.getByLabelText('Find staff with project access');
        fireEvent.change(search, { target: { value: 'missing' } });
        await loadOptions();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Staff could not be loaded',
        );
        fireEvent.change(search, { target: { value: '' } });
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).toBeVisible();
        await loadOptions();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('aborts a superseded search and ignores its late response', async () => {
        let resolveOld: ((response: Response) => void) | undefined;
        const current = {
            id: 'synthetic-current',
            name: 'Synthetic current result',
        };
        const old = { id: 'synthetic-old', name: 'Synthetic old result' };
        vi.mocked(fetch)
            .mockImplementationOnce(
                () =>
                    new Promise<Response>((resolve) => {
                        resolveOld = resolve;
                    }),
            )
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ data: [current], has_more: false }),
            } as Response);
        render(<TaskEditor task={task} />);
        const search = screen.getByLabelText('Find staff with project access');
        fireEvent.change(search, { target: { value: 'old' } });
        await loadOptions();
        const previousSignal = vi.mocked(fetch).mock.calls[0][1]?.signal;
        fireEvent.change(search, { target: { value: 'current' } });
        expect(previousSignal?.aborted).toBe(true);
        await loadOptions();
        expect(
            screen.getByRole('checkbox', { name: current.name }),
        ).toBeVisible();
        await act(async () => {
            resolveOld?.({
                ok: true,
                json: async () => ({ data: [old], has_more: true }),
            } as Response);
        });
        expect(
            screen.queryByRole('checkbox', { name: old.name }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', { name: current.name }),
        ).toBeVisible();
        expect(screen.queryByText(/More staff match/)).not.toBeInTheDocument();
    });

    it('aborts an outstanding search when cleared and keeps the initial answer', async () => {
        let resolveSearch: ((response: Response) => void) | undefined;
        vi.mocked(fetch).mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    resolveSearch = resolve;
                }),
        );
        render(<TaskEditor task={task} />);
        const search = screen.getByLabelText('Find staff with project access');
        fireEvent.change(search, { target: { value: 'late' } });
        await loadOptions();
        const previousSignal = vi.mocked(fetch).mock.calls[0][1]?.signal;
        fireEvent.change(search, { target: { value: '' } });
        expect(previousSignal?.aborted).toBe(true);
        await act(async () => {
            resolveSearch?.({
                ok: true,
                json: async () => ({ data: [], has_more: true }),
            } as Response);
        });
        await loadOptions();
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).toBeVisible();
        expect(screen.queryByText(/More staff match/)).not.toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('loads a newly selected unseeded project without reusing another project roster', async () => {
        const next = {
            id: 'synthetic-next',
            name: 'Synthetic next project member',
        };
        const project = {
            id: 'synthetic-project',
            name: 'Synthetic project',
            create_action: '/synthetic/create',
            assignees_action: '/synthetic/project-staff',
            assignee_options: { data: [person], has_more: false },
        };
        const { rerender } = render(<TaskEditor project={project} />);
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).toBeVisible();
        await loadOptions();
        expect(fetch).not.toHaveBeenCalled();
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ data: [next], has_more: false }),
        } as Response);
        rerender(
            <TaskEditor
                project={{
                    ...project,
                    id: 'synthetic-next-project',
                    assignees_action: '/synthetic/next-project-staff',
                    assignee_options: null,
                }}
            />,
        );
        expect(
            screen.queryByRole('checkbox', { name: person.name }),
        ).not.toBeInTheDocument();
        await loadOptions();
        expect(fetch).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({
                pathname: '/synthetic/next-project-staff',
                search: '?q=',
            }),
            expect.any(Object),
        );
        expect(screen.getByRole('checkbox', { name: next.name })).toBeVisible();
    });

    it('never publishes a pending old project answer into a newly seeded project', async () => {
        let resolveOld: ((response: Response) => void) | undefined;
        const next = {
            id: 'synthetic-next',
            name: 'Synthetic next project member',
        };
        vi.mocked(fetch).mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    resolveOld = resolve;
                }),
        );
        const project = {
            id: 'synthetic-project',
            name: 'Synthetic project',
            create_action: '/synthetic/create',
            assignees_action: '/synthetic/project-staff',
        };
        const { rerender } = render(<TaskEditor project={project} />);
        await loadOptions();
        const previousSignal = vi.mocked(fetch).mock.calls[0][1]?.signal;
        rerender(
            <TaskEditor
                project={{
                    ...project,
                    id: 'synthetic-next-project',
                    assignees_action: '/synthetic/next-project-staff',
                    assignee_options: { data: [next], has_more: false },
                }}
            />,
        );
        expect(previousSignal?.aborted).toBe(true);
        expect(screen.getByRole('checkbox', { name: next.name })).toBeVisible();
        await act(async () => {
            resolveOld?.({
                ok: true,
                json: async () => ({ data: [person], has_more: true }),
            } as Response);
        });
        await loadOptions();
        expect(
            screen.queryByRole('checkbox', { name: person.name }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/More staff match/)).not.toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows the server completion advisory without blocking the parent-only save', async () => {
        const advisory =
            'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.';
        const { rerender } = render(
            <TaskEditor task={{ ...task, completion_advisory: advisory }} />,
        );
        await loadOptions();
        expect(screen.getByText(advisory)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        expect(submit).toHaveBeenCalledOnce();
        expect(submit.mock.calls[0][0]).toBe('PATCH');
        expect(submit.mock.calls[0][1]).toBe(task.update_action);
        expect(submit.mock.calls[0][2]).toEqual({
            title: task.title,
            description: task.description,
            status: 'completed',
            expected_version: task.version,
            starts_on: task.starts_on,
            due_on: task.due_on,
            assignee_ids: [person.id],
            is_visible_to_client: false,
        });
        rerender(<TaskEditor task={task} />);
        expect(screen.queryByText(advisory)).not.toBeInTheDocument();
    });

    it('does not show completion advice while editing an open task', async () => {
        const advisory =
            'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.';
        render(
            <TaskEditor
                task={{
                    ...task,
                    status: 'open',
                    completion_advisory: advisory,
                }}
            />,
        );
        await loadOptions();
        expect(screen.getByRole('combobox')).toHaveTextContent('Open');
        expect(screen.queryByText(advisory)).not.toBeInTheDocument();
    });

    it('submits the rendered revision, preserves completion and clears nullable dates explicitly', async () => {
        render(<TaskEditor task={task} />);
        await loadOptions();
        expect(screen.getByRole('combobox')).toHaveTextContent('Completed');
        fireEvent.change(screen.getByLabelText('Due date'), {
            target: { value: '' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        expect(submit).toHaveBeenCalledWith(
            'PATCH',
            '/synthetic/task-update',
            expect.objectContaining({
                expected_version: task.version,
                status: 'completed',
                starts_on: '2026-10-20',
                due_on: null,
                assignee_ids: [person.id],
                is_visible_to_client: false,
            }),
            expect.any(String),
        );
    });

    it('creates an internal task and passes selected account IDs to the server URL', async () => {
        render(
            <TaskEditor
                project={{
                    id: 'synthetic-project',
                    name: 'Synthetic project',
                    create_action: '/synthetic/create',
                    assignees_action: '/synthetic/lookup',
                }}
            />,
        );
        await loadOptions();
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Synthetic new task' },
        });
        fireEvent.click(screen.getByRole('checkbox', { name: person.name }));
        fireEvent.click(screen.getByRole('button', { name: 'Create task' }));
        expect(submit).toHaveBeenCalledWith(
            'POST',
            '/synthetic/create',
            {
                title: 'Synthetic new task',
                description: null,
                starts_on: null,
                due_on: null,
                assignee_ids: [person.id],
                is_visible_to_client: false,
            },
            expect.any(String),
        );
    });

    it('uses a bounded searchable lookup and enforces the 10 person cap', async () => {
        const selected = Array.from({ length: 10 }, (_, i) => ({
            id: `synthetic-${i}`,
            name: `Synthetic person ${i}`,
        }));
        render(<TaskEditor task={{ ...task, assignees: selected }} />);
        await loadOptions();
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Synthetic person 0' }),
        );
        expect(
            screen.getByRole('checkbox', { name: person.name }),
        ).not.toBeDisabled();
        fireEvent.change(
            screen.getByLabelText('Find staff with project access'),
            { target: { value: 'Synthetic' } },
        );
        await loadOptions();
        expect(fetch).toHaveBeenLastCalledWith(
            expect.objectContaining({
                pathname: '/synthetic/staff-lookup',
                search: '?q=Synthetic',
            }),
            expect.objectContaining({ credentials: 'same-origin' }),
        );
    });

    it('explains stale conflicts and offers to reload without retrying the stale form', async () => {
        submit.mockResolvedValueOnce({
            ok: false,
            status: 409,
            message: 'Synthetic stale task',
        });
        render(<TaskEditor task={task} />);
        await loadOptions();
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This task has changed. Reload',
        );
        expect(
            screen.getByRole('button', { name: 'Reload task' }),
        ).toBeVisible();
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('keeps a task creation draft and receipt key after an uncertain failure', async () => {
        submit.mockResolvedValueOnce({
            ok: false,
            status: 503,
            message: 'Synthetic retryable failure',
        });
        render(
            <TaskEditor
                project={{
                    id: 'synthetic-project',
                    name: 'Synthetic project',
                    create_action: '/synthetic/create',
                    assignees_action: '/synthetic/lookup',
                    assignee_options: { data: [], has_more: false },
                }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Keep this synthetic draft' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Create task' }),
            );
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Synthetic retryable failure',
        );
        expect(screen.getByLabelText('Title')).toHaveValue(
            'Keep this synthetic draft',
        );
        const key = submit.mock.calls[0][3];
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Create task' }),
            );
        });
        expect(submit.mock.calls[1][3]).toBe(key);
        expect(screen.getByLabelText('Title')).toHaveValue('');
    });

    it('replays a saved creation when refreshing its list fails', async () => {
        vi.mocked(refreshPageProps).mockRejectedValueOnce(
            new Error('Synthetic refresh failure'),
        );
        render(
            <TaskEditor
                project={{
                    id: 'synthetic-project',
                    name: 'Synthetic project',
                    create_action: '/synthetic/create',
                    assignees_action: '/synthetic/lookup',
                    assignee_options: { data: [], has_more: false },
                }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Save this synthetic draft once' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Create task' }),
            );
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'saved, but current tasks could not be refreshed',
        );
        const key = submit.mock.calls[0][3];
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Create task' }),
            );
        });
        expect(submit.mock.calls[1][3]).toBe(key);
        expect(submit.mock.calls[1][2]).toEqual(submit.mock.calls[0][2]);
        expect(screen.getByLabelText('Title')).toHaveValue('');
    });

    it('refreshes continuation capabilities with the task rows after creation', async () => {
        render(
            <TaskEditor
                project={{
                    id: 'synthetic-project',
                    name: 'Synthetic project',
                    create_action: '/synthetic/create',
                    assignees_action: '/synthetic/lookup',
                    assignee_options: { data: [], has_more: false },
                }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Synthetic task crossing a page limit' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Create task' }),
            );
        });
        expect(refreshPageProps).toHaveBeenCalledWith(
            expect.objectContaining({
                only: expect.arrayContaining([
                    'tasks',
                    'tasks_next_href',
                    'pagination',
                ]),
            }),
        );
    });

    it('gives oversized identities and descriptions room to wrap', async () => {
        const long = 'SyntheticIdentity'.repeat(30);
        const { container } = render(
            <TaskEditor
                task={{
                    ...task,
                    title: long,
                    description: `${long}\n${long}`,
                    assignees: [{ id: 'synthetic-long', name: long }],
                }}
            />,
        );
        await loadOptions();
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
