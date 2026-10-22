import { Head } from '@inertiajs/react';
import CommentThread from '@/components/collaboration/comment-thread';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { CollaborationThread } from '@/types/collaboration';

export default function CollaborationThreadPage({
    thread,
}: {
    thread: CollaborationThread;
}) {
    return (
        <WorkspaceShell
            activeModule={
                thread.parent_kind === 'task'
                    ? 'tasks'
                    : thread.parent_kind === 'discussion'
                      ? 'discussions'
                      : 'notes'
            }
        >
            <Head title={`${thread.parent_title} comments`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <h1 className="text-2xl font-semibold wrap-anywhere">
                    {thread.parent_title}
                </h1>
                <CommentThread thread={thread} />
            </main>
        </WorkspaceShell>
    );
}
