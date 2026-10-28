import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TaskLabelsPage from '@/pages/clients/task-labels';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type { TaskPlanningCatalogProps } from '@/types/task-planning';

const { request, refresh, get } = vi.hoisted(() => ({
    request: vi.fn(),
    refresh: vi.fn(),
    get: vi.fn(),
}));
vi.mock('@/lib/api', () => ({
    apiRequest: request,
    newIdempotencyKey: () => 'synthetic-catalog-key',
}));
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: refresh }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { get },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
function fixture(): TaskPlanningCatalogProps {
    return {
        workspace: { id: 'synthetic-workspace', name: 'Synthetic workspace' },
        catalog: {
            labels: [
                {
                    id: 'synthetic-label',
                    name: 'Synthetic label',
                    is_archived: false,
                    version: 'v'.repeat(64),
                    update_href: '/finished/update',
                    archive_href: '/finished/archive',
                    restore_href: null,
                },
                {
                    id: 'synthetic-archived',
                    name: 'Synthetic archived',
                    is_archived: true,
                    version: 'a'.repeat(64),
                    update_href: null,
                    archive_href: null,
                    restore_href: '/finished/restore',
                },
            ],
            filters: { status: 'all', q: '' },
            search_href: '/finished/catalog',
            create_href: '/finished/create',
            next_href: '/finished/next-page',
            previous_href: null,
        },
    };
}
beforeEach(() => {
    request.mockReset();
    request.mockResolvedValue({ ok: true, data: { id: 'synthetic-label' } });
    refresh.mockReset();
    refresh.mockResolvedValue(undefined);
    get.mockReset();
});
describe('native task label catalog', () => {
    it('uses server pages and only searches on submit', () => {
        render(<TaskLabelsPage {...fixture()} />);
        fireEvent.change(screen.getByLabelText('Find labels'), {
            target: { value: '合成' },
        });
        expect(get).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
        expect(get).toHaveBeenCalledWith(
            '/finished/catalog',
            { q: '合成', status: 'all' },
            { preserveState: true },
        );
        expect(
            screen.getByRole('link', { name: 'Next labels' }),
        ).toHaveAttribute('href', '/finished/next-page');
    });
    it('counts Unicode code points and allows a valid eighty-character astral label', async () => {
        render(<TaskLabelsPage {...fixture()} />);
        const name = '🧪'.repeat(80);
        fireEvent.change(screen.getByLabelText('New label name'), {
            target: { value: name },
        });
        expect(
            screen.getByText('80 of 80 characters. Labels use one line.'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Create label' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Create label' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'POST',
                '/finished/create',
                { name },
                'synthetic-catalog-key',
            ),
        );
        expect(refresh).toHaveBeenCalledWith({ only: ['catalog'] });
    });
    it('requires confirmation and captures the selected archive token rather than silently updating it', async () => {
        const props = fixture();
        const view = render(<TaskLabelsPage {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Archive label' }));
        expect(request).not.toHaveBeenCalled();
        view.rerender(
            <TaskLabelsPage
                {...{
                    ...props,
                    catalog: {
                        ...props.catalog,
                        labels: props.catalog.labels.map((row) => ({
                            ...row,
                            version: 'n'.repeat(64),
                        })),
                    },
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm archive' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'DELETE',
                '/finished/archive',
                { expected_version: 'v'.repeat(64), confirm: true },
                'synthetic-catalog-key',
            ),
        );
    });
    it('retains a dirty rename and stale token across updated catalog props and a refusal', async () => {
        request.mockResolvedValue({ ok: false, status: 409, message: 'stale' });
        const props = fixture();
        const view = render(<TaskLabelsPage {...props} />);
        fireEvent.change(screen.getByLabelText('Label name'), {
            target: { value: 'Synthetic renamed' },
        });
        view.rerender(
            <TaskLabelsPage
                {...{
                    ...props,
                    catalog: {
                        ...props.catalog,
                        labels: props.catalog.labels.map((row) => ({
                            ...row,
                            version: 'n'.repeat(64),
                        })),
                    },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save name' }));
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Label name')).toHaveValue(
            'Synthetic renamed',
        );
        expect(request).toHaveBeenCalledWith(
            'PATCH',
            '/finished/update',
            { name: 'Synthetic renamed', expected_version: 'v'.repeat(64) },
            'synthetic-catalog-key',
        );
        expect(refresh).not.toHaveBeenCalled();
    });
    it('restores archived labels without rewriting their names or assignments', async () => {
        render(<TaskLabelsPage {...fixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Restore label' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm restore' }),
        );
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                'POST',
                '/finished/restore',
                { expected_version: 'a'.repeat(64) },
                'synthetic-catalog-key',
            ),
        );
        expect(screen.getAllByLabelText('Label name')).toHaveLength(1);
    });
    it('wraps long names while exposing empty and finished pagination states', () => {
        const props = fixture();
        props.catalog.labels[0].name = '合成'.repeat(40);
        const { container } = render(<TaskLabelsPage {...props} />);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
