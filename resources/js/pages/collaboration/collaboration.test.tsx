import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CommentThread from '@/components/collaboration/comment-thread';
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
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({
            children,
            ...props
        }: {
            children: ReactNode;
            href: string;
        }) => <a {...props}>{children}</a>,
        router: { get: mutations.get },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = useState(initial);
            let transform = (value: Record<string, unknown>) => value;
            const form = {
                data,
                processing: false,
                errors: {},
                setData: (field: string, value: unknown) =>
                    setData((prior) => ({ ...prior, [field]: value })),
                transform: (callback: typeof transform) => {
                    transform = callback;

                    return form;
                },
                reset: () => setData(initial),
                post: (href: string) => mutations.post(href, transform(data)),
                patch: (href: string) => mutations.patch(href, transform(data)),
                delete: (href: string) =>
                    mutations.delete(href, transform(data)),
            };

            return form;
        },
    };
});

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
    kind_label: 'Note',
    project_id: 'synthetic-project',
    scope_label: 'SyntheticProject'.repeat(15),
    body: '<img src=x onerror=alert(1)>\n' + 'SyntheticTopicBody'.repeat(40),
    update_href: '/synthetic/topic/edit',
    delete_href: '/synthetic/topic/delete',
};
const thread: CollaborationThread = {
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
    beforeEach(() => vi.clearAllMocks());

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
});
