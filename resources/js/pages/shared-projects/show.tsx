import { Head, Link } from '@inertiajs/react';
import {
    ParticipantsCard,
    ParticipationCard,
} from '@/components/shared-projects/participation-panels';
import { SharedTaskList } from '@/components/shared-projects/shared-task-list';
import WorkspaceShell from '@/layouts/workspace-shell';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { SharedProjectShowProps } from '@/types/shared-projects';

/**
 * A project shared between workspaces.
 *
 * The host sees who takes part and may invite and remove; a participant sees
 * the tasks the host shares with it and the comments in the shared
 * conversation, and manages its own side. Nothing on this page carries money:
 * each workspace logs time and bills on its own project, in its own books.
 */
export default function SharedProjectShow({
    project,
    tasks,
    tasks_next_cursor: nextCursor,
    urls,
    local_project_options: localProjectOptions,
}: SharedProjectShowProps) {
    const host = project.side === 'host';

    return (
        <WorkspaceShell>
            <Head title={project.name} />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="grid min-w-0 grid-cols-1 gap-6">
                    <section className="grid grid-cols-1 gap-1">
                        <p className="text-sm text-muted-foreground">
                            <Link
                                href={urls.index}
                                className="underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                Shared projects
                            </Link>
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight wrap-anywhere">
                            {project.name}
                        </h1>
                        <p className="text-sm wrap-anywhere text-muted-foreground">
                            {host
                                ? `Hosted by you${project.client_name ? ` for ${project.client_name}` : ''} · ${statusLabel(project.status)}`
                                : `Hosted by ${project.host_workspace_name} · ${statusLabel(project.status)}`}
                            {host && urls.host_project !== null && (
                                <>
                                    {' · '}
                                    <Link
                                        href={urls.host_project}
                                        className="underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        Open the project
                                    </Link>
                                </>
                            )}
                        </p>
                    </section>

                    <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <SharedTaskList
                            tasks={tasks}
                            options={project.assignee_options ?? []}
                            hasMore={nextCursor !== null}
                        />
                        <div className="grid min-w-0 grid-cols-1 content-start gap-6">
                            {host && project.viewer.can_manage_participants && (
                                <ParticipantsCard
                                    project={project}
                                    inviteUrl={urls.invite}
                                />
                            )}
                            {host &&
                                !project.viewer.can_manage_participants &&
                                project.participants !== undefined && (
                                    <ParticipantsCard
                                        project={project}
                                        inviteUrl={null}
                                    />
                                )}
                            {!host && (
                                <ParticipationCard
                                    project={project}
                                    urls={urls}
                                    options={localProjectOptions}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </main>
        </WorkspaceShell>
    );
}
