import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskEditor } from '@/components/tasks/task-editor';
import type { EditableTask } from '@/components/tasks/task-editor';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const { submit } = vi.hoisted(() => ({ submit: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const transform = React.useRef<
                (value: Record<string, unknown>) => Record<string, unknown>
            >((value) => value);

            return {
                data,
                errors,
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                setError: (key: string, value: string) =>
                    setErrors((current) => ({ ...current, [key]: value })),
                transform: (callback: typeof transform.current) => {
                    transform.current = callback;
                },
                post: (url: string, options: object) =>
                    submit('POST', url, transform.current(data), options),
                patch: (url: string, options: object) =>
                    submit('PATCH', url, transform.current(data), options),
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
};

beforeEach(() => {
    vi.useFakeTimers();
    submit.mockClear();
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
            expect.any(Object),
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
            expect.any(Object),
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
        render(<TaskEditor task={task} />);
        await loadOptions();
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        const options = submit.mock.calls[0][3];
        await act(async () => {
            expect(options.onHttpException({ status: 409 })).toBe(false);
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This task has changed. Reload',
        );
        expect(
            screen.getByRole('button', { name: 'Reload task' }),
        ).toBeVisible();
        expect(submit).toHaveBeenCalledTimes(1);
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
