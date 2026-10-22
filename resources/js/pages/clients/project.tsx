import { Head, Link } from '@inertiajs/react';
import { TaskCreateDialog } from '@/components/tasks/task-create-dialog';
import type { TaskEditorProject } from '@/components/tasks/task-editor';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatHours } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { CompanyAgreement } from '@/types/clients';

type ProjectTask = {
    id: string;
    title: string;
    status: string;
    is_visible_to_client: boolean;
    completed_at: string | null;
    starts_on?: string | null;
    due_on?: string | null;
    detail_url?: string | null;
    comment_thread_url?: string | null;
};

type TimeByStatus = {
    status: string;
    minutes: number;
    entries: number;
};

/**
 * One project, reached from the Overview that lists it.
 *
 * The time figures are totals rather than rows: a project can carry a year of
 * entries, and this answers "how much" while the Time tab answers "which". It
 * is split by status because approved and draft hours mean different things -
 * only one of them has been agreed to be billable, and summing them into a
 * single number would say something the ledger does not.
 */
export default function ClientProjectDetail({
    workspace,
    company,
    project,
    tasks,
    time,
    agreements,
    task_management = null,
}: {
    workspace: { id: string };
    company: { id: string; name: string };
    project: {
        id: string;
        name: string;
        description: string | null;
        status: string;
        is_visible_to_client: boolean;
    };
    tasks: ProjectTask[];
    task_management?: { projects: TaskEditorProject[] } | null;
    time: TimeByStatus[];
    agreements: CompanyAgreement[];
}) {
    return (
        <WorkspaceShell activeModule="home">
            <Head title={project.name} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <header className="grid grid-cols-1 gap-1">
                    <Link
                        href={`/workspaces/${workspace.id}/clients/${company.id}`}
                        className="text-sm wrap-anywhere text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {company.name} overview
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="min-w-0 text-2xl font-semibold wrap-anywhere">
                            {project.name}
                        </h1>
                        <Badge variant="outline">
                            {statusLabel(project.status)}
                        </Badge>
                        {!project.is_visible_to_client && (
                            <Badge variant="secondary">
                                Hidden from client
                            </Badge>
                        )}
                    </div>
                    {project.description !== null && (
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {project.description}
                        </p>
                    )}
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>Time logged</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {time.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No time logged against this project.
                            </p>
                        ) : (
                            <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                                {time.map((row) => (
                                    <div
                                        key={row.status}
                                        className="flex gap-2"
                                    >
                                        <dt className="text-muted-foreground">
                                            {statusLabel(row.status)}
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatHours(row.minutes)}
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {row.entries}{' '}
                                                {row.entries === 1
                                                    ? 'entry'
                                                    : 'entries'}
                                            </span>
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        <p className="mt-3 text-xs text-muted-foreground">
                            <Link
                                href={`/workspaces/${workspace.id}/clients/${company.id}/time`}
                                className="underline-offset-4 hover:underline"
                            >
                                The Time tab has the entries themselves.
                            </Link>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <CardTitle>Tasks</CardTitle>
                            {task_management !== null && (
                                <TaskCreateDialog
                                    projects={task_management.projects}
                                />
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {tasks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No tasks on this project.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="min-w-64">
                                                Task
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Completed</TableHead>
                                            <TableHead>Client sees</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {tasks.map((task) => (
                                            <TableRow key={task.id}>
                                                <TableCell className="max-w-0 font-medium wrap-anywhere whitespace-normal">
                                                    {task.detail_url ? (
                                                        <Link
                                                            href={
                                                                task.detail_url
                                                            }
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {task.title}
                                                        </Link>
                                                    ) : (
                                                        task.title
                                                    )}
                                                    {task.comment_thread_url && (
                                                        <Link
                                                            href={
                                                                task.comment_thread_url
                                                            }
                                                            className="mt-1 block text-sm font-normal underline underline-offset-4"
                                                        >
                                                            Comments
                                                        </Link>
                                                    )}
                                                    {task.starts_on && (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            Starts{' '}
                                                            {formatDay(
                                                                task.starts_on,
                                                            )}
                                                        </p>
                                                    )}
                                                    {task.due_on && (
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            Due{' '}
                                                            {formatDay(
                                                                task.due_on,
                                                            )}
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {statusLabel(
                                                            task.status,
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {formatDay(
                                                        task.completed_at,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {task.is_visible_to_client
                                                        ? 'Yes'
                                                        : 'No'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Agreements scoped to this project</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-2">
                        {agreements.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                None. A company-wide agreement may still cover
                                this project — the client Overview lists those.
                            </p>
                        ) : (
                            agreements.map((agreement) => (
                                <Link
                                    key={agreement.id}
                                    href={`/workspaces/${workspace.id}/clients/${company.id}/agreements/${agreement.id}`}
                                    className="flex flex-wrap items-center gap-2 rounded-xl border p-3 hover:bg-accent"
                                >
                                    <span className="font-medium">
                                        {agreement.title}
                                    </span>
                                    <Badge variant="outline">
                                        {agreement.status}
                                    </Badge>
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
