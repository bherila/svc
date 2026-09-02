import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';

type TaskProject = { id: string; name: string };

type CompanyTask = {
    id: string;
    title: string;
    status: string;
    project: string | null;
    is_visible_to_client: boolean;
    completed_at: string | null;
};

const ALL_PROJECTS = 'all';

/**
 * The client's tasks, with the project as a filter rather than the context.
 *
 * Tasks are the one thing on these tabs that genuinely belongs to a project, so
 * this is where the filter the hierarchy promised actually appears. The company
 * stays in the route; narrowing to a project is a query parameter, so a link to
 * one project's tasks survives a client switch by simply dropping back to all
 * of them.
 */
export default function ClientTasks({
    company,
    audience,
    filters,
    projects,
    tasks,
}: {
    company: { id: string; name: string };
    /**
     * Whether "client sees" is a column. It is a statement about disclosure,
     * which means nothing on the copy of this screen the client is reading.
     */
    audience: 'operator' | 'client';
    filters: { project_id: string | null };
    projects: TaskProject[];
    tasks: CompanyTask[];
}) {
    const selected = filters.project_id ?? ALL_PROJECTS;
    const showsVisibility = audience === 'operator';

    return (
        <WorkspaceShell activeModule="tasks">
            <Head title={`${company.name} tasks`} />
            <main className="mx-auto grid max-w-6xl grid-cols-1 gap-6 p-6">
                <Card>
                    <CardHeader className="gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <CardTitle>Tasks</CardTitle>
                            {projects.length > 1 && (
                                <Select
                                    value={selected}
                                    onValueChange={(next) => {
                                        // Base UI can emit null on clear, and
                                        // the filter lives in the query string
                                        // so the company route stays the page's
                                        // identity - clearing it is just
                                        // dropping the parameter.
                                        if (typeof next !== 'string') {
                                            return;
                                        }

                                        const path = window.location.pathname;

                                        router.visit(
                                            next === ALL_PROJECTS
                                                ? path
                                                : `${path}?project=${next}`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <SelectTrigger
                                        className="w-56"
                                        aria-label="Filter by project"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_PROJECTS}>
                                            All projects
                                        </SelectItem>
                                        {projects.map((project) => (
                                            <SelectItem
                                                key={project.id}
                                                value={project.id}
                                            >
                                                {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {tasks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {projects.length === 0
                                    ? 'No projects you can reach for this client.'
                                    : 'No tasks on the selected projects.'}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Task</TableHead>
                                            <TableHead>Project</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Completed</TableHead>
                                            {showsVisibility && (
                                                <TableHead>
                                                    Client sees
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {tasks.map((task) => (
                                            <TableRow key={task.id}>
                                                <TableCell className="font-medium">
                                                    {task.title}
                                                </TableCell>
                                                <TableCell>
                                                    {task.project ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {task.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {task.completed_at ?? '—'}
                                                </TableCell>
                                                {showsVisibility && (
                                                    <TableCell>
                                                        {task.is_visible_to_client
                                                            ? 'Yes'
                                                            : 'No'}
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
