import { Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    CollaborationErrors,
    RemoveContent,
    VisibilityControl,
} from '@/components/collaboration/content-controls';
import { useCollaborationMutation } from '@/components/collaboration/use-collaboration-mutation';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useVersionedDraft } from '@/hooks/use-versioned-draft';
import { formatTimestamp } from '@/lib/datetime';
import type {
    CollaborationContent,
    CollaborationThread,
} from '@/types/collaboration';

function CommentEditor({
    comment,
    onCancel,
}: {
    comment: CollaborationContent;
    onCancel: () => void;
}) {
    const form = useVersionedDraft(
        {
            body: comment.body ?? '',
        },
        comment.version,
        false,
    );
    const mutation = useCollaborationMutation();

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                const submitted = { ...form.data };
                void mutation.run(
                    'PATCH',
                    comment.update_href!,
                    { ...submitted, expected_version: form.version },
                    (saved) => {
                        form.saved(submitted, saved.version);
                        onCancel();
                    },
                );
            }}
        >
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Edit comment
                <Textarea
                    disabled={mutation.locked}
                    required
                    maxLength={50000}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    className="min-h-32"
                />
            </label>
            <CollaborationErrors errors={mutation.errors} />
            <div className="flex flex-wrap gap-2">
                <Button disabled={mutation.pending} type="submit">
                    {mutation.unresolved
                        ? 'Check original change'
                        : 'Save comment'}
                </Button>
                <Button
                    variant="outline"
                    type="button"
                    disabled={mutation.locked}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function Comment({ comment }: { comment: CollaborationContent }) {
    const [editing, setEditing] = useState(false);
    const [uploadPending, setUploadPending] = useState(false);

    return (
        <article
            id={`comment-${comment.id}`}
            className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border p-4"
        >
            <p className="text-sm wrap-anywhere text-muted-foreground">
                {comment.author_name}
                {comment.created_at &&
                    ` · ${formatTimestamp(comment.created_at)}`}
                {comment.edited_at && ' · Edited'}
            </p>
            {comment.deleted ? (
                <p className="text-sm text-muted-foreground">
                    Comment removed.
                </p>
            ) : editing && comment.update_href ? (
                <CommentEditor
                    key={comment.id}
                    comment={comment}
                    onCancel={() => setEditing(false)}
                />
            ) : (
                <p className="min-w-0 wrap-anywhere whitespace-pre-wrap">
                    {comment.body}
                </p>
            )}
            {!comment.deleted &&
                comment.attachment_panel &&
                (comment.attachment_panel.files.length > 0 ||
                    comment.attachment_panel.upload !== null ||
                    uploadPending) && (
                    <details className="min-w-0">
                        <summary className="cursor-pointer text-sm">
                            Files
                        </summary>
                        <div className="min-w-0 pt-3">
                            <AttachmentSharingPanel
                                key={comment.id}
                                panel={comment.attachment_panel}
                                onUploadPendingChange={setUploadPending}
                            />
                        </div>
                    </details>
                )}
            {!comment.deleted && (
                <>
                    <VisibilityControl
                        key={`sharing-${comment.id}`}
                        content={comment}
                    />
                    <div className="flex flex-wrap items-start gap-2">
                        {comment.update_href && !editing && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                Edit comment
                            </Button>
                        )}
                        <RemoveContent
                            key={`remove-${comment.id}`}
                            content={comment}
                        />
                    </div>
                </>
            )}
        </article>
    );
}

function NewComment({ thread }: { thread: CollaborationThread }) {
    const form = useVersionedDraft(
        {
            body: '',
            is_visible_to_client: false as boolean,
        },
        thread.parent_version,
        false,
    );
    const mutation = useCollaborationMutation();

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation.run(
                    'POST',
                    thread.store_href!,
                    { ...form.data, expected_version: form.version },
                    undefined,
                    true,
                );
            }}
        >
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                New comment
                <Textarea
                    disabled={mutation.locked}
                    required
                    maxLength={50000}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    className="min-h-32"
                />
            </label>
            {thread.visibility_store_href && (
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        disabled={mutation.locked}
                        checked={form.data.is_visible_to_client}
                        onChange={(event) =>
                            form.setData(
                                'is_visible_to_client',
                                event.target.checked,
                            )
                        }
                    />
                    Visible to client
                </label>
            )}
            <CollaborationErrors errors={mutation.errors} />
            <Button
                type="submit"
                disabled={mutation.pending}
                className="justify-self-start"
            >
                {mutation.unresolved ? 'Check original change' : 'Add comment'}
            </Button>
        </form>
    );
}

export default function CommentThread({
    thread,
}: {
    thread: CollaborationThread;
}) {
    return (
        <section
            aria-label="Comments"
            className="grid min-w-0 grid-cols-1 gap-4"
        >
            <h2 className="text-lg font-semibold">Comments</h2>
            {thread.comments.length ? (
                thread.comments.map((comment) => (
                    <Comment key={comment.id} comment={comment} />
                ))
            ) : (
                <p className="text-sm text-muted-foreground">
                    No comments yet.
                </p>
            )}
            <nav aria-label="Comment pages" className="flex flex-wrap gap-4">
                {thread.previous_href && (
                    <Link href={thread.previous_href} className="underline">
                        Previous comments
                    </Link>
                )}
                {thread.next_href && (
                    <Link href={thread.next_href} className="underline">
                        More comments
                    </Link>
                )}
            </nav>
            {thread.store_href && (
                <NewComment key={thread.href} thread={thread} />
            )}
        </section>
    );
}
