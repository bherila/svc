import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CommentThread from '@/components/collaboration/comment-thread';
import { refreshPageProps } from '@/lib/refresh-page-props';
import CollaborationIndex from '@/pages/collaboration/index';
import CollaborationShow from '@/pages/collaboration/show';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import type {
    CollaborationContent,
    CollaborationThread,
    CollaborationTopic,
} from '@/types/collaboration';

const mutations = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(),
    visit: vi.fn(),
    request: vi.fn(),
    on: vi.fn(),
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/lib/api', () => ({
    newIdempotencyKey: () => crypto.randomUUID(),
    apiRequest: (
        method: 'POST' | 'PATCH' | 'DELETE',
        href: string,
        body: object,
        key: string,
    ) => {
        mutations[method.toLowerCase() as 'post' | 'patch' | 'delete'](
            href,
            body,
        );

        return mutations.request(method, href, body, key);
    },
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(async () => {}),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => (
        <a {...props}>{children}</a>
    ),
    router: { get: mutations.get, visit: mutations.visit, on: mutations.on },
}));

const comment: CollaborationContent = {
    id: 'synthetic-comment',
    body: '<script>literal comment</script>\n' + 'SyntheticBody'.repeat(40),
    author_name: 'SyntheticAuthor'.repeat(15),
    created_at: '2026-10-22T12:00:00Z',
    edited_at: null,
    deleted: false,
    version: 'c'.repeat(64),
    href: '/synthetic/comments#comment-synthetic-comment',
    is_visible_to_client: true,
    effective_visible_to_client: false,
    update_href: '/synthetic/comments/edit',
    visibility_update_href: '/synthetic/comments/share',
    delete_href: '/synthetic/comments/delete',
};
const topic: CollaborationTopic = {
    ...comment,
    id: 'synthetic-topic',
    title: 'SyntheticTopic'.repeat(20),
    kind: 'note',
    kind_label: 'Note',
    project_id: 'synthetic-project',
    scope_label: 'SyntheticProject'.repeat(15),
    body: '<img src=x onerror=alert(1)>\n' + 'SyntheticTopicBody'.repeat(40),
    update_href: '/synthetic/topic/edit',
    delete_href: '/synthetic/topic/delete',
};
const thread: CollaborationThread = {
    parent_kind: 'note',
    parent_title: topic.title,
    parent_version: 'p'.repeat(64),
    href: '/synthetic/thread',
    store_href: '/synthetic/comments/create',
    visibility_store_href: '/synthetic/comments/create',
    comments: [comment],
    next_href: '/synthetic/thread?page=2',
    previous_href: null,
};

describe('native collaboration pages', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutations.request.mockReset();
        mutations.request.mockImplementation(() => new Promise(() => {}));
        mutations.visit.mockImplementation((_url, options) => {
            options.onSuccess();
            options.onFinish();
        });
        vi.mocked(refreshPageProps).mockReset();
        vi.mocked(refreshPageProps).mockResolvedValue();
    });

    it('renders complete escaped topic/comment content, parent sharing state and pagination without overflow risks', () => {
        const view = render(
            <CollaborationShow topic={topic} thread={thread} />,
        );
        expect(
            screen.getByRole('heading', { name: topic.title }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('region', { name: 'Content' }),
        ).toHaveTextContent('<img src=x onerror=alert(1)>');
        expect(view.container.querySelector('img')).toBeNull();
        expect(view.container.querySelector('script')).toBeNull();
        expect(
            screen.getAllByText(
                'Hidden from clients because a parent is private.',
            ),
        ).toHaveLength(2);
        expect(
            screen.getByRole('link', { name: 'More comments' }),
        ).toHaveAttribute('href', thread.next_href);
        expect(horizontalOverflowRisks(view.container)).toEqual([]);
    });

    it('offers full-body author editing and submits the server version and supplied URL', () => {
        render(<CollaborationShow topic={topic} thread={null} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit content' }));
        expect(screen.getByRole('textbox', { name: 'Content' })).toHaveValue(
            topic.body,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Content' }), {
            target: { value: 'Synthetic edited body' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save content' }));
        expect(mutations.patch).toHaveBeenCalledWith('/synthetic/topic/edit', {
            title: topic.title,
            body: 'Synthetic edited body',
            expected_version: topic.version,
        });
    });

    it('uses a separate sharing action and confirms removal', () => {
        render(<CommentThread thread={thread} />);
        const checkboxes = screen.getAllByRole('checkbox', {
            name: 'Visible to client',
        });
        fireEvent.click(checkboxes[0]);
        fireEvent.click(screen.getByRole('button', { name: 'Save sharing' }));
        expect(mutations.patch).toHaveBeenCalledWith(
            comment.visibility_update_href,
            { expected_version: comment.version, is_visible_to_client: false },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
        expect(mutations.delete).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm removal' }),
        );
        expect(mutations.delete).toHaveBeenCalledWith(comment.delete_href, {
            expected_version: comment.version,
            confirm: true,
        });
    });

    it('does not offer a contributor publication controls while allowing a new internal reply', () => {
        render(
            <CommentThread
                thread={{
                    ...thread,
                    visibility_store_href: null,
                    comments: [
                        {
                            ...comment,
                            visibility_update_href: null,
                            delete_href: null,
                        },
                    ],
                }}
            />,
        );
        expect(screen.queryByRole('checkbox')).toBeNull();
        fireEvent.change(screen.getByRole('textbox', { name: 'New comment' }), {
            target: { value: 'Synthetic contributor reply' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add comment' }));
        expect(mutations.post).toHaveBeenCalledWith(thread.store_href, {
            body: 'Synthetic contributor reply',
            is_visible_to_client: false,
            expected_version: thread.parent_version,
        });
    });

    it('renders portal and removed content with no mutation controls or leaked deleted text', () => {
        const portalComment = {
            ...comment,
            body: null,
            deleted: true,
            is_visible_to_client: null,
            effective_visible_to_client: null,
            update_href: null,
            visibility_update_href: null,
            delete_href: null,
        };
        render(
            <CommentThread
                thread={{
                    ...thread,
                    comments: [portalComment],
                    store_href: null,
                    visibility_store_href: null,
                }}
            />,
        );
        expect(screen.getByText('Comment removed.')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.queryByText(/literal comment/)).toBeNull();
    });

    it('creates either topic kind with an explicit safe scope and default internal visibility', () => {
        render(
            <CollaborationIndex
                company={{ id: 'synthetic-company', name: 'Synthetic Company' }}
                audience="internal"
                topics={[]}
                filters={{ kind: null, project_id: null }}
                filter_href="/synthetic/topics"
                create_href="/synthetic/topics/create"
                create_scopes={[{ id: '', label: 'Company-wide' }]}
                next_href={null}
                previous_href={null}
            />,
        );
        fireEvent.change(screen.getByRole('combobox', { name: 'Type' }), {
            target: { value: 'discussion' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Title' }), {
            target: { value: 'Synthetic discussion' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Content' }), {
            target: { value: 'Synthetic discussion text' },
        });
        expect(
            screen.getByRole('checkbox', { name: 'Visible to client' }),
        ).not.toBeChecked();
        fireEvent.click(screen.getByRole('button', { name: 'Create' }));
        expect(mutations.post).toHaveBeenCalledWith(
            '/synthetic/topics/create',
            {
                kind: 'discussion',
                title: 'Synthetic discussion',
                body: 'Synthetic discussion text',
                project_id: null,
                is_visible_to_client: false,
            },
        );
    });

    it('keeps the portal index read-only and uses supplied topic links', () => {
        const view = render(
            <CollaborationIndex
                company={{
                    id: 'synthetic-company',
                    name: 'SyntheticCompany'.repeat(30),
                }}
                audience="portal"
                topics={[topic]}
                filters={{ kind: null, project_id: null }}
                filter_href="/synthetic/portal/topics"
                create_href={null}
                create_scopes={[]}
                next_href={null}
                previous_href={null}
            />,
        );
        expect(screen.getByRole('link', { name: topic.title })).toHaveAttribute(
            'href',
            topic.href,
        );
        expect(screen.queryByRole('button', { name: 'Create' })).toBeNull();
        expect(horizontalOverflowRisks(view.container)).toEqual([]);
    });

    it('retains an uncertain comment draft and key, then follows the server focus URL', async () => {
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 503,
            message: 'Synthetic retryable refusal',
        });
        const destination =
            '/synthetic/finished-comment-page?comment=synthetic-comment#comment-synthetic-comment';
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: { version: 'saved-version', web_url: destination },
        });
        render(<CommentThread thread={thread} />);
        fireEvent.change(screen.getByLabelText('New comment'), {
            target: { value: 'Keep this synthetic reply' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Add comment' }),
            );
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Synthetic retryable refusal',
        );
        expect(screen.getByLabelText('New comment')).toHaveValue(
            'Keep this synthetic reply',
        );
        const key = mutations.request.mock.calls[0][3];
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request.mock.calls[1][3]).toBe(key);
        expect(mutations.visit).toHaveBeenCalledWith(
            destination,
            expect.any(Object),
        );
    });

    it('preserves an unsaved text edit and its reviewed revision across a sharing save', async () => {
        mutations.request.mockResolvedValue({
            ok: true,
            status: 200,
            data: { version: 'next-version', web_url: comment.href },
        });
        const view = render(<CommentThread thread={thread} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit comment' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Edit comment' }),
            { target: { value: 'Unsaved synthetic text draft' } },
        );
        fireEvent.click(
            screen.getAllByRole('checkbox', { name: 'Visible to client' })[0],
        );
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save sharing' }),
            );
        });
        view.rerender(
            <CommentThread
                thread={{
                    ...thread,
                    comments: [
                        {
                            ...comment,
                            version: 'next-version',
                            is_visible_to_client: false,
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Edit comment' }),
        ).toHaveValue('Unsaved synthetic text draft');
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 409,
            message: 'Synthetic sharing changed the content revision',
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save comment' }),
            );
        });
        expect(mutations.request).toHaveBeenLastCalledWith(
            'PATCH',
            comment.update_href,
            {
                body: 'Unsaved synthetic text draft',
                expected_version: comment.version,
            },
            expect.any(String),
        );
        expect(
            screen.getByRole('textbox', { name: 'Edit comment' }),
        ).toHaveValue('Unsaved synthetic text draft');
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Synthetic sharing changed',
        );
    });

    it('does not publish or remove content against a revision changed after the operator chose the action', async () => {
        mutations.request.mockResolvedValue({
            ok: false,
            status: 409,
            message: 'Synthetic content changed. Reload before changing it.',
        });
        const view = render(<CommentThread thread={thread} />);
        fireEvent.click(
            screen.getAllByRole('checkbox', { name: 'Visible to client' })[0],
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
        view.rerender(
            <CommentThread
                thread={{
                    ...thread,
                    comments: [
                        {
                            ...comment,
                            body: 'Synthetic unreviewed changed content',
                            version: 'changed-version',
                        },
                    ],
                }}
            />,
        );
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save sharing' }),
            );
        });
        expect(mutations.request).toHaveBeenLastCalledWith(
            'PATCH',
            comment.visibility_update_href,
            expect.objectContaining({ expected_version: comment.version }),
            expect.any(String),
        );
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Confirm removal' }),
            );
        });
        expect(mutations.request).toHaveBeenLastCalledWith(
            'DELETE',
            comment.delete_href,
            { expected_version: comment.version, confirm: true },
            expect.any(String),
        );
        expect(
            screen.getByRole('button', { name: 'Confirm removal' }),
        ).toBeVisible();
    });

    it('retains a new reply original parent version when refreshed parent facts change', async () => {
        mutations.request.mockResolvedValue({
            ok: false,
            status: 409,
            message: 'Synthetic changed parent',
        });
        const view = render(<CommentThread thread={thread} />);
        fireEvent.change(screen.getByLabelText('New comment'), {
            target: { value: 'Keep my original synthetic reply' },
        });
        view.rerender(
            <CommentThread
                thread={{ ...thread, parent_version: 'new-parent-version' }}
            />,
        );
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Add comment' }),
            );
        });
        expect(mutations.request).toHaveBeenCalledWith(
            'POST',
            thread.store_href,
            expect.objectContaining({
                expected_version: thread.parent_version,
            }),
            expect.any(String),
        );
        expect(screen.getByLabelText('New comment')).toHaveValue(
            'Keep my original synthetic reply',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Synthetic changed parent',
        );
    });

    it('retries only navigation when a recorded reply destination cannot load', async () => {
        mutations.request.mockResolvedValue({
            ok: true,
            status: 201,
            data: {
                version: 'saved-version',
                web_url: '/synthetic/finished-comment',
            },
        });
        mutations.visit
            .mockImplementationOnce((_url, options) => {
                expect(
                    options.onNetworkError(
                        new Error('Synthetic network failure'),
                    ),
                ).toBe(false);
                options.onFinish();
            })
            .mockImplementationOnce((_url, options) => {
                expect(options.onHttpException({ status: 503 })).toBe(false);
                options.onFinish();
            });
        render(<CommentThread thread={thread} />);
        fireEvent.change(screen.getByLabelText('New comment'), {
            target: { value: 'Save this synthetic reply once' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Add comment' }),
            );
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Check the original change before editing again',
        );
        const key = mutations.request.mock.calls[0][3];
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(screen.getByLabelText('New comment')).toBeDisabled();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request).toHaveBeenCalledTimes(1);
        expect(mutations.visit).toHaveBeenCalledTimes(3);
        expect(mutations.request.mock.calls[0][3]).toBe(key);
        expect(mutations.visit.mock.calls[1][0]).toBe(
            '/synthetic/finished-comment',
        );
    });

    it('freezes a timed-out topic creation through changed props and later refusals', async () => {
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 408,
            message: 'Synthetic timeout',
        });
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 409,
            message: 'Synthetic current conflict cannot settle the lost result',
        });
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: {
                version: 'saved-topic-version',
                web_url: '/synthetic/created-topic',
            },
        });
        const props = {
            company: { id: 'synthetic-company', name: 'Synthetic company' },
            audience: 'internal' as const,
            topics: [],
            filters: { kind: null, project_id: null },
            filter_href: '/synthetic/filter',
            create_href: '/synthetic/original-create',
            create_scopes: [{ id: '', label: 'Company-wide' }],
            next_href: null,
            previous_href: null,
        };
        const view = render(<CollaborationIndex {...props} />);
        await userEvent.type(
            screen.getByLabelText('Title'),
            'Synthetic original title',
        );
        await userEvent.type(
            screen.getByLabelText('Content'),
            'Synthetic original text',
        );
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Create' }));
        });
        const original = mutations.request.mock.calls[0];
        const navigation = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: {
                    url: '/synthetic/other-client',
                    method: 'get',
                    only: [],
                },
            },
        });
        act(() => {
            document.dispatchEvent(navigation);
        });
        expect(navigation.defaultPrevented).toBe(true);
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        expect(screen.getByLabelText('Title')).toBeDisabled();
        expect(screen.getByLabelText('Content')).toBeDisabled();
        expect(screen.getByRole('combobox', { name: 'Scope' })).toBeDisabled();
        await userEvent.type(screen.getByLabelText('Title'), ' changed');
        expect(screen.getByLabelText('Title')).toHaveValue(
            'Synthetic original title',
        );
        view.rerender(
            <CollaborationIndex
                {...props}
                create_href="/synthetic/changed-create"
            />,
        );
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request.mock.calls[1]).toEqual(original);
        expect(screen.getByLabelText('Title')).toBeDisabled();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request.mock.calls[2]).toEqual(original);
        expect(mutations.visit).toHaveBeenCalledWith(
            '/synthetic/created-topic',
            expect.any(Object),
        );
    });

    it('retains an unknown comment update and disables cancellation across a later authorization refusal', async () => {
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 0,
            message: 'Synthetic lost answer',
        });
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 403,
            message: 'Synthetic current access refusal',
        });
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 200,
            data: { version: 'saved-comment-version', web_url: comment.href },
        });
        const view = render(<CommentThread thread={thread} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit comment' }));
        fireEvent.change(screen.getByLabelText('Edit comment'), {
            target: { value: 'Synthetic original edit' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save comment' }),
            );
        });
        const original = mutations.request.mock.calls[0];
        expect(screen.getByLabelText('Edit comment')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByLabelText('Edit comment')).toBeInTheDocument();
        view.rerender(
            <CommentThread
                thread={{
                    ...thread,
                    comments: [
                        {
                            ...comment,
                            version: 'new-unreviewed-version',
                            update_href: '/synthetic/changed-edit',
                            body: 'Synthetic remote text',
                        },
                    ],
                }}
            />,
        );
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request.mock.calls[1]).toEqual(original);
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request.mock.calls[2]).toEqual(original);
        expect(
            screen.queryByRole('textbox', { name: 'Edit comment' }),
        ).not.toBeInTheDocument();
    });

    it('keeps a known topic save locked until authoritative refresh succeeds without rewriting it', async () => {
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 200,
            data: { version: 'saved-topic-version', web_url: topic.href },
        });
        vi.mocked(refreshPageProps).mockRejectedValueOnce(
            new Error('Synthetic refresh failed'),
        );
        render(<CollaborationShow topic={topic} thread={null} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit content' }));
        fireEvent.change(screen.getByLabelText('Content'), {
            target: { value: 'Synthetic saved topic text' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save content' }),
            );
        });
        expect(screen.getByLabelText('Content')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Check the original change',
        );
        const navigation = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: {
                    url: '/synthetic/other-client',
                    method: 'get',
                    only: [],
                },
            },
        });
        document.dispatchEvent(navigation);
        expect(navigation.defaultPrevented).toBe(false);
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(false);
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request).toHaveBeenCalledTimes(1);
        expect(refreshPageProps).toHaveBeenCalledTimes(2);
        expect(refreshPageProps).toHaveBeenLastCalledWith({
            only: ['topic', 'thread', 'topics'],
            requireSuccess: true,
        });
        expect(
            screen.getByRole('button', { name: 'Edit content' }),
        ).toBeInTheDocument();
    });

    it('allows correction after a definitive validation refusal using a fresh receipt', async () => {
        mutations.request.mockResolvedValueOnce({
            ok: false,
            status: 422,
            message: 'Synthetic validation refusal',
            errors: { body: ['Synthetic body needs correction'] },
        });
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 200,
            data: { version: 'saved-comment-version', web_url: comment.href },
        });
        render(<CommentThread thread={thread} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit comment' }));
        fireEvent.change(screen.getByLabelText('Edit comment'), {
            target: { value: 'Synthetic rejected text' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save comment' }),
            );
        });
        const first = mutations.request.mock.calls[0];
        expect(screen.getByLabelText('Edit comment')).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Synthetic body needs correction',
        );
        fireEvent.change(screen.getByLabelText('Edit comment'), {
            target: { value: 'Synthetic corrected text' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save comment' }),
            );
        });
        expect(mutations.request.mock.calls[1][2]).toEqual({
            body: 'Synthetic corrected text',
            expected_version: comment.version,
        });
        expect(mutations.request.mock.calls[1][3]).not.toBe(first[3]);
    });

    it('keeps an unreadable successful creation unresolved instead of starting another write', async () => {
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: { version: '', web_url: '/synthetic/missing-version' },
        });
        mutations.request.mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: {
                version: 'saved-version',
                web_url: '/synthetic/recovered-comment',
            },
        });
        render(<CommentThread thread={thread} />);
        fireEvent.change(screen.getByLabelText('New comment'), {
            target: { value: 'Synthetic creation needing reconciliation' },
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Add comment' }),
            );
        });
        const original = mutations.request.mock.calls[0];
        expect(screen.getByLabelText('New comment')).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'response could not be read',
        );
        expect(mutations.visit).not.toHaveBeenCalled();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Check original change' }),
            );
        });
        expect(mutations.request.mock.calls[1]).toEqual(original);
        expect(mutations.visit).toHaveBeenCalledWith(
            '/synthetic/recovered-comment',
            expect.any(Object),
        );
    });

    it('embeds authorized comment files without requesting them on mount', () => {
        const filename = 'SyntheticCommentFile'.repeat(35) + '.txt';
        const view = render(
            <CommentThread
                thread={{
                    ...thread,
                    comments: [
                        {
                            ...comment,
                            attachment_panel: {
                                files: [
                                    {
                                        id: 'synthetic-comment-file',
                                        filename,
                                        media_type: 'text/plain',
                                        bytes: 3,
                                        uploaded_at: null,
                                        download_href: '/synthetic/portal-file',
                                        delete_href: null,
                                        sharing: null,
                                    },
                                ],
                                upload: null,
                                next_href: null,
                            },
                        },
                    ],
                }}
            />,
        );
        fireEvent.click(screen.getByText('Files', { selector: 'summary' }));
        expect(screen.getByRole('link', { name: filename })).toHaveAttribute(
            'href',
            '/synthetic/portal-file',
        );
        expect(screen.queryByText('Upload a file')).not.toBeInTheDocument();
        expect(horizontalOverflowRisks(view.container)).toEqual([]);
    });
});
