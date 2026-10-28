import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskPlanningPanel } from '@/components/tasks/task-planning-panel';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type { TaskPlanningNativeDetails } from '@/types/task-planning';

const { request, newKey } = vi.hoisted(() => ({
    request: vi.fn(),
    newKey: vi.fn(),
}));
vi.mock('@/lib/api', () => ({
    apiRequest: request,
    newIdempotencyKey: newKey,
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { reload: vi.fn() },
}));

const label = {
    id: 'synthetic-label',
    name: 'Synthetic label',
    is_archived: false,
    version: 'l'.repeat(64),
};
const contact = {
    id: 'synthetic-contact',
    display_name: 'Synthetic client contact',
    is_archived: false,
    version: 'c'.repeat(64),
};
const task = {
    id: 'synthetic-prerequisite',
    title: 'Synthetic prerequisite',
    status: 'in_progress',
    version: 'r'.repeat(64),
    web_url: '/finished/reference',
};
function fixture(): TaskPlanningNativeDetails {
    return {
        task_id: 'synthetic-source',
        version: 'v'.repeat(64),
        dependency_summary: {
            blocked: true,
            incomplete_prerequisite_count: 1,
            dependent_count: 0,
        },
        labels: {
            selected: [],
            options: { data: [label], next_href: null, previous_href: null },
            options_href: '/finished/label-options',
            replace_href: '/finished/labels',
        },
        dependencies: {
            data: {
                task_id: 'synthetic-source',
                version: 'v'.repeat(64),
                graph_version: 'g'.repeat(64),
                prerequisites: [],
                dependents: [],
            },
            read_href: '/finished/dependencies',
            next_href: null,
            options: { data: [task], next_href: null, previous_href: null },
            options_href: '/finished/dependency-options',
            replace_href: '/finished/prerequisites',
            unsupported_notice: null,
        },
        contacts: {
            selected: [],
            version: 'v'.repeat(64),
            options: { data: [contact], next_href: null, previous_href: null },
            options_href: '/finished/contact-options',
            replace_href: '/finished/contacts',
        },
        label_catalog_href: '/finished/catalog',
    };
}
beforeEach(() => {
    request.mockReset();
    request.mockResolvedValue({
        ok: true,
        status: 200,
        data: { id: 'synthetic-source' },
    });
    newKey.mockReset();
    newKey.mockReturnValue('synthetic-stable-key');
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('task planning panel', () => {
    it('uses bounded initial pages including empty choices without mount requests', async () => {
        const details = fixture();
        details.contacts!.options.data = [];
        render(<TaskPlanningPanel details={details} />);
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeVisible();
        expect(screen.getByText('No matching contacts loaded.')).toBeVisible();
        expect(
            screen.getByText(
                /Business responsibility grants no account access/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Manage workspace labels' }),
        ).toHaveAttribute('href', '/finished/catalog');
        expect(fetch).not.toHaveBeenCalled();
    });
    it('omits unavailable contacts and catalog while preserving ordinary reader labels', () => {
        const details = fixture();
        delete details.contacts;
        details.label_catalog_href = null;
        details.labels.replace_href = null;
        details.labels.selected = [{ ...label, is_archived: true }];
        render(<TaskPlanningPanel details={details} />);
        expect(screen.getByText('Synthetic label (archived)')).toBeVisible();
        expect(
            screen.queryByText('Client contact responsibility'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Manage workspace labels'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save labels' }),
        ).not.toBeInTheDocument();
    });
    it('debounces typed searches, aborts obsolete responses and restores seeded choices', async () => {
        vi.useFakeTimers();
        let resolve!: (response: Response) => void;
        vi.mocked(fetch).mockReturnValueOnce(
            new Promise((done) => {
                resolve = done;
            }),
        );
        render(<TaskPlanningPanel details={fixture()} />);
        const input = screen.getByLabelText('Find labels');
        fireEvent.change(input, { target: { value: 'remote' } });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(199);
        });
        expect(fetch).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1);
        });
        const signal = vi.mocked(fetch).mock.calls[0][1]!.signal!;
        fireEvent.change(input, { target: { value: '' } });
        expect(signal.aborted).toBe(true);
        await act(async () => {
            resolve({
                ok: true,
                json: async () => ({
                    data: [{ ...label, id: 'remote', name: 'Obsolete result' }],
                    next_href: null,
                    previous_href: null,
                }),
            } as Response);
        });
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeVisible();
        expect(screen.queryByText('Obsolete result')).not.toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(1);
    });
    it('preserves a stale form and key across retry, then clears only after an explicit refresh', async () => {
        const refresh = vi.fn().mockResolvedValue(undefined);
        request.mockResolvedValue({ ok: false, status: 409, message: 'stale' });
        const details = fixture();
        const view = render(
            <TaskPlanningPanel details={details} onRefresh={refresh} />,
        );
        fireEvent.click(screen.getByRole('checkbox', { name: label.name }));
        fireEvent.click(screen.getByRole('button', { name: 'Save labels' }));
        await screen.findByRole('alert');
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeChecked();
        view.rerender(
            <TaskPlanningPanel
                details={{ ...details, version: 'n'.repeat(64) }}
                onRefresh={refresh}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save labels' }));
        await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
        expect(request.mock.calls[0]).toEqual([
            'PUT',
            '/finished/labels',
            { expected_version: details.version, label_ids: [label.id] },
            'synthetic-stable-key',
        ]);
        expect(request.mock.calls[1]).toEqual(request.mock.calls[0]);
        expect(refresh).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Reload labels' }));
        await waitFor(() =>
            expect(
                screen.getByRole('checkbox', { name: label.name }),
            ).not.toBeChecked(),
        );
    });
    it('refreshes successful planning independently and preserves other dirty form tokens', async () => {
        const refresh = vi.fn().mockResolvedValue(undefined);
        const details = fixture();
        const view = render(
            <TaskPlanningPanel details={details} onRefresh={refresh} />,
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: contact.display_name }),
        );
        fireEvent.click(screen.getByRole('checkbox', { name: label.name }));
        fireEvent.click(screen.getByRole('button', { name: 'Save labels' }));
        await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
        view.rerender(
            <TaskPlanningPanel
                details={{
                    ...details,
                    version: 'n'.repeat(64),
                    contacts: { ...details.contacts!, version: 'n'.repeat(64) },
                }}
                onRefresh={refresh}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save contacts' }));
        await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
        expect(request.mock.calls[1][2]).toEqual({
            expected_version: details.version,
            contact_ids: [contact.id],
        });
    });
    it('uses the complete outgoing set and captured graph token, with explicit empty arrays to clear', async () => {
        const details = fixture();
        details.dependencies.data!.prerequisites = [task];
        render(
            <TaskPlanningPanel
                details={details}
                onRefresh={vi.fn().mockResolvedValue(undefined)}
            />,
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: /Synthetic prerequisite/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save prerequisites' }),
        );
        await waitFor(() => expect(request).toHaveBeenCalled());
        expect(request.mock.calls[0][2]).toEqual({
            expected_version: details.version,
            expected_graph_version: 'g'.repeat(64),
            prerequisite_ids: [],
        });
    });
    it('continues incoming references only on demand, stops at a null finished URL and rejects changed graph pages', async () => {
        const details = fixture();
        details.dependencies.next_href = '/finished/incoming-next';
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            json: async () => ({
                data: { ...details.dependencies.data!, dependents: [task] },
                next_href: null,
            }),
        } as Response);
        render(<TaskPlanningPanel details={details} />);
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more dependent tasks' }),
        );
        await screen.findByRole('link', { name: task.title });
        expect(fetch).toHaveBeenCalledWith(
            '/finished/incoming-next',
            expect.anything(),
        );
        expect(
            screen.queryByRole('button', { name: 'Load more dependent tasks' }),
        ).not.toBeInTheDocument();
    });
    it('does not cache incoming status facts by an unchanged source version after a fresh page read', async () => {
        const details = fixture();
        details.dependencies.next_href = '/finished/incoming-next';
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            json: async () => ({
                data: { ...details.dependencies.data!, dependents: [task] },
                next_href: null,
            }),
        } as Response);
        const view = render(<TaskPlanningPanel details={details} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more dependent tasks' }),
        );
        await screen.findByRole('link', { name: task.title });
        const fresh = {
            ...details,
            dependencies: {
                ...details.dependencies,
                data: {
                    ...details.dependencies.data!,
                    dependents: [
                        {
                            ...task,
                            title: 'Fresh server dependent',
                            status: 'completed',
                        },
                    ],
                },
                next_href: null,
            },
        };
        view.rerender(<TaskPlanningPanel details={fresh} />);
        expect(
            screen.getByRole('link', { name: 'Fresh server dependent' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('link', { name: task.title }),
        ).not.toBeInTheDocument();
    });

    it('rejects changed incoming graph pages rather than merging a different snapshot', async () => {
        const details = fixture();
        details.dependencies.next_href = '/finished/incoming-next';
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            json: async () => ({
                data: {
                    ...details.dependencies.data!,
                    graph_version: 'n'.repeat(64),
                    dependents: [task],
                },
                next_href: null,
            }),
        } as Response);
        render(<TaskPlanningPanel details={details} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more dependent tasks' }),
        );
        await screen.findByRole('alert');
        expect(
            screen.queryByRole('link', { name: task.title }),
        ).not.toBeInTheDocument();
    });

    it('loads a finished options continuation explicitly and stops after its final page', async () => {
        const details = fixture();
        details.labels.options!.next_href = '/finished/next-labels';
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            json: async () => ({
                data: [
                    {
                        ...label,
                        id: 'synthetic-next',
                        name: 'Synthetic next label',
                    },
                ],
                next_href: null,
                previous_href: null,
            }),
        } as Response);
        render(<TaskPlanningPanel details={details} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Load more labels' }),
        );
        await screen.findByRole('checkbox', { name: 'Synthetic next label' });
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/finished/next-labels',
            expect.anything(),
        );
        expect(
            screen.queryByRole('button', { name: 'Load more labels' }),
        ).not.toBeInTheDocument();
    });

    it('prevents new associations above the selection cap while retaining removal', () => {
        const details = fixture();
        details.labels.selected = Array.from({ length: 20 }, (_, index) => ({
            ...label,
            id: `synthetic-retained-${index}`,
            name: `Retained ${index}`,
        }));
        render(<TaskPlanningPanel details={details} />);
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Retained 0' }),
        );
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeEnabled();
    });

    it('keeps label and contact editing usable when dependency analysis exceeds supported size', () => {
        const details = fixture();
        details.dependencies = {
            ...details.dependencies,
            data: null,
            options: null,
            replace_href: null,
            unsupported_notice:
                'This project exceeds the supported dependency size.',
        };
        render(<TaskPlanningPanel details={details} />);
        expect(
            screen.getByText(/exceeds the supported dependency size/),
        ).toBeVisible();
        expect(
            screen.getByRole('checkbox', { name: label.name }),
        ).toBeVisible();
        expect(
            screen.getByRole('checkbox', { name: contact.display_name }),
        ).toBeVisible();
    });
    it('wraps oversized retained labels, prerequisites and contacts without known overflow risks', () => {
        const details = fixture();
        details.labels.selected = [
            { ...label, name: '合成'.repeat(40), is_archived: true },
        ];
        details.contacts!.selected = [
            { ...contact, display_name: '合成'.repeat(100), is_archived: true },
        ];
        details.dependencies.data!.prerequisites = [
            { ...task, title: 'SyntheticLong'.repeat(35) },
        ];
        const { container } = render(<TaskPlanningPanel details={details} />);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
