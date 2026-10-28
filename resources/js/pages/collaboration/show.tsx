import { Head } from '@inertiajs/react';
import { useState } from 'react';
import CommentThread from '@/components/collaboration/comment-thread';
import {
    CollaborationErrors,
    RemoveContent,
    VisibilityControl,
} from '@/components/collaboration/content-controls';
import { useCollaborationMutation } from '@/components/collaboration/use-collaboration-mutation';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useVersionedDraft } from '@/hooks/use-versioned-draft';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatTimestamp } from '@/lib/datetime';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type {
    CollaborationThread,
    CollaborationTopic,
} from '@/types/collaboration';

function TopicEditor({
    topic,
    onCancel,
}: {
    topic: CollaborationTopic;
    onCancel: () => void;
}) {
    const form = useVersionedDraft(
        {
            title: topic.title,
            body: topic.body ?? '',
        },
        topic.version,
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
                    topic.update_href!,
                    { ...submitted, expected_version: form.version },
                    (saved) => {
                        form.saved(submitted, saved.version);
                        onCancel();
                    },
                );
            }}
        >
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Title
                <Input
                    disabled={mutation.locked}
                    required
                    maxLength={255}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
            </label>
            <label className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                Content
                <Textarea
                    disabled={mutation.locked}
                    required
                    maxLength={50000}
                    className="min-h-64"
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                />
            </label>
            <CollaborationErrors errors={mutation.errors} />
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={mutation.pending}>
                    {mutation.unresolved
                        ? 'Check original change'
                        : 'Save content'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={mutation.locked}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function CollaborationShow({
    topic,
    thread,
}: {
    topic: CollaborationTopic;
    thread: CollaborationThread | null;
}) {
    const [editing, setEditing] = useState(false);

    return (
        <WorkspaceShell
            activeModule={topic.kind === 'discussion' ? 'discussions' : 'notes'}
        >
            <Head title={topic.title} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <div className="min-w-0">
                    <p className="text-sm text-muted-foreground">
                        {topic.kind_label}
                    </p>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {topic.scope_label}
                    </p>
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        {topic.title}
                    </h1>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {topic.author_name}
                        {topic.created_at &&
                            ` · ${formatTimestamp(topic.created_at)}`}
                        {topic.edited_at && ' · Edited'}
                    </p>
                </div>
                {topic.deleted ? (
                    <p className="text-muted-foreground">
                        This content was removed.
                    </p>
                ) : editing && topic.update_href ? (
                    <TopicEditor
                        key={topic.id}
                        topic={topic}
                        onCancel={() => setEditing(false)}
                    />
                ) : (
                    <section
                        aria-label="Content"
                        className="min-w-0 wrap-anywhere whitespace-pre-wrap"
                    >
                        {topic.body}
                    </section>
                )}
                {!topic.deleted && (
                    <>
                        <VisibilityControl
                            key={`sharing-${topic.id}`}
                            content={topic}
                        />
                        <div className="flex flex-wrap items-start gap-2">
                            {topic.update_href && !editing && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditing(true)}
                                >
                                    Edit content
                                </Button>
                            )}
                            <RemoveContent
                                key={`remove-${topic.id}`}
                                content={topic}
                            />
                        </div>
                    </>
                )}
                {topic.attachment_panel && (
                    <AttachmentSharingPanel
                        key={topic.id}
                        panel={topic.attachment_panel}
                    />
                )}
                {thread && <CommentThread thread={thread} />}
            </main>
        </WorkspaceShell>
    );
}
