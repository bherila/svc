import { Head, Link } from '@inertiajs/react';
import { TaskEditor } from '@/components/tasks/task-editor';
import type { EditableTask } from '@/components/tasks/task-editor';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

export default function ClientTaskDetail({
    company,
    project,
    task,
    comment_thread_url = null,
    files_href = null,
}: {
    company: { name: string };
    project: { name: string };
    task: EditableTask;
    comment_thread_url?: string | null;
    files_href?: string | null;
}) {
    return (
        <WorkspaceShell activeModule="tasks">
            <Head title={task.title} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle className="wrap-anywhere">
                            {task.title}
                        </CardTitle>
                        <p className="text-sm wrap-anywhere text-muted-foreground">
                            {company.name} · {project.name}
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {comment_thread_url && (
                            <Link
                                href={comment_thread_url}
                                className="block text-sm underline underline-offset-4"
                            >
                                Comments
                            </Link>
                        )}
                        {files_href && (
                            <Link
                                href={files_href}
                                className="block text-sm underline underline-offset-4"
                            >
                                Task files
                            </Link>
                        )}
                        <TaskEditor key={task.version} task={task} />
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
