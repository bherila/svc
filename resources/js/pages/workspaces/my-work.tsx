import { Head, Link } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { DurationField } from '@/components/time/duration-field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useDurationStyle } from '@/hooks/use-duration-style';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatDay } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { cn } from '@/lib/utils';
import type {
    MyTimeSpan,
    MyWorkProject,
    MyWorkProps,
    MyWorkTask,
} from '@/types/my-work';

/** The statuses a task can be moved between from this page. */
const TASK_STATUSES = ['open', 'in_progress', 'completed'] as const;

type TaskStatus = (typeof TASK_STATUSES)[number];

function isTaskStatus(value: string): value is TaskStatus {
    return (TASK_STATUSES as readonly string[]).includes(value);
}

const STATUS_ITEMS = TASK_STATUSES.map((status) => ({
    value: status,
    label: statusLabel(status),
}));

/**
 * One assigned task. A project owner or manager gets a status control (the
 * server sent the finished update URL and the version it read); everyone else
 * gets the status as words, and the task's own page for the rest.
 */
function TaskRow({
    task,
    onChanged,
}: {
    task: MyWorkTask;
    onChanged: (message: string | null) => void;
}) {
    const [saving, setSaving] = useState(false);

    const change = async (status: string | null) => {
        if (
            status === null ||
            !isTaskStatus(status) ||
            status === task.status ||
            task.update_url === null ||
            task.version === null ||
            saving
        ) {
            return;
        }

        setSaving(true);
        onChanged(null);

        const result = await apiRequest('PATCH', task.update_url, {
            status,
            expected_version: task.version,
        } satisfies ApiSchema<'TaskUpdateRequest'>);

        if (!result.ok) {
            onChanged(result.message);
            setSaving(false);

            return;
        }

        void refreshPageProps({ onFinish: () => setSaving(false) });
    };

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-2 py-3">
            <div className="min-w-0 flex-1 basis-60">
                <Link
                    href={task.href}
                    className="text-sm font-medium wrap-anywhere underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {task.title}
                </Link>
                <p className="text-xs wrap-anywhere text-muted-foreground">
                    {task.project} · {task.client}
                    {task.due_on !== null && ` · due ${formatDay(task.due_on)}`}
                </p>
            </div>
            {task.update_url === null ? (
                <span className="text-sm text-muted-foreground">
                    {statusLabel(task.status)}
                </span>
            ) : (
                <Select
                    items={STATUS_ITEMS}
                    value={task.status}
                    onValueChange={(value: string | null) => {
                        void change(value);
                    }}
                    disabled={saving}
                >
                    <SelectTrigger
                        className="w-40"
                        aria-label={`Status of ${task.title}`}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {STATUS_ITEMS.map((item) => (
                            <SelectItem key={item.value} value={item.value}>
                                {item.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </li>
    );
}

function ThisWeek({
    groups,
    onChanged,
}: {
    groups: MyWorkProps['tasks'];
    onChanged: (message: string | null) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>This week</CardTitle>
            </CardHeader>
            <CardContent>
                {groups.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Tasks assigned to you appear here, grouped by when they
                        are due. Nothing is assigned to you right now.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4">
                        {groups.map((group) => (
                            <section
                                key={group.key}
                                aria-label={group.label}
                                className="grid grid-cols-1 gap-1"
                            >
                                <h3
                                    className={cn(
                                        'text-xs font-semibold tracking-wide text-muted-foreground uppercase',
                                        group.key === 'overdue' &&
                                            'text-destructive',
                                    )}
                                >
                                    {group.label}
                                </h3>
                                <ul className="grid grid-cols-1 divide-y divide-border">
                                    {group.tasks.map((task) => (
                                        <TaskRow
                                            key={task.id}
                                            task={task}
                                            onChanged={onChanged}
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function QuickLog({
    projects,
    today,
}: {
    projects: MyWorkProject[];
    today: string;
}) {
    const [projectId, setProjectId] = useState(projects[0]?.id ?? '');
    const [workedOn, setWorkedOn] = useState(today);
    const [minutes, setMinutes] = useState(60);
    const [description, setDescription] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);
    const attempt = useRef<{ body: string; key: string } | null>(null);

    const project = projects.find((candidate) => candidate.id === projectId);

    const submit = async () => {
        if (saving || project === undefined) {
            return;
        }

        setError(null);
        setSaved(false);
        setSaving(true);

        const body = {
            entries: [
                {
                    project_id: project.id,
                    task_id: null,
                    worked_on: workedOn,
                    minutes,
                    description,
                    is_billable: true,
                    is_deferred: false,
                    is_visible_to_client: false,
                    client_visible_description: null,
                },
            ],
        } satisfies ApiSchema<'TimeLogRequest'>;
        const fingerprint = JSON.stringify(body);

        if (attempt.current?.body !== fingerprint) {
            attempt.current = { body: fingerprint, key: newIdempotencyKey() };
        }

        const result = await apiRequest(
            'POST',
            project.log_time_url,
            body,
            attempt.current.key,
        );

        if (!result.ok) {
            setError(result.message);
            setSaving(false);

            return;
        }

        setDescription('');
        setSaved(true);
        attempt.current = null;
        void refreshPageProps({ onFinish: () => setSaving(false) });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Quick log</CardTitle>
            </CardHeader>
            <CardContent>
                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        You can log time once you are a contributor on a
                        project. Ask whoever runs the workspace to add you to
                        one.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4">
                        <div className="grid grid-cols-1 gap-1.5">
                            <Label htmlFor="quick-log-project">Project</Label>
                            <Select
                                // The trigger prints the raw value unless the
                                // root is told the labels.
                                items={projects.map((candidate) => ({
                                    value: candidate.id,
                                    label: `${candidate.name} (${candidate.client})`,
                                }))}
                                value={projectId}
                                onValueChange={(value: string | null) =>
                                    setProjectId(value ?? '')
                                }
                            >
                                <SelectTrigger
                                    id="quick-log-project"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a project" />
                                </SelectTrigger>
                                <SelectContent>
                                    {projects.map((candidate) => (
                                        <SelectItem
                                            key={candidate.id}
                                            value={candidate.id}
                                        >
                                            {candidate.name} ({candidate.client}
                                            )
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="grid grid-cols-1 gap-1.5">
                                <Label htmlFor="quick-log-date">Date</Label>
                                <Input
                                    id="quick-log-date"
                                    type="date"
                                    value={workedOn}
                                    onChange={(event) =>
                                        setWorkedOn(event.target.value)
                                    }
                                />
                            </div>
                            <DurationField
                                id="quick-log-duration"
                                minutes={minutes}
                                onChange={setMinutes}
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-1.5">
                            <Label htmlFor="quick-log-description">
                                What did you do?
                            </Label>
                            <Textarea
                                id="quick-log-description"
                                rows={2}
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                            />
                        </div>
                        {error !== null && (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {error}
                            </p>
                        )}
                        {saved && (
                            <p
                                className="text-sm text-muted-foreground"
                                role="status"
                            >
                                Saved. It shows under My time below.
                            </p>
                        )}
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={
                                saving ||
                                description.trim() === '' ||
                                workedOn === '' ||
                                project === undefined
                            }
                        >
                            <CheckIcon aria-hidden="true" />
                            Save hours
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function TimeSpan({
    title,
    span,
    style,
}: {
    title: string;
    span: MyTimeSpan;
    style: DurationStyle;
}) {
    return (
        <section aria-label={title} className="grid grid-cols-1 gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-sm tabular-nums">
                    {formatDuration(span.total_minutes, style)}
                </p>
            </div>
            {span.projects.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing logged in {formatDay(span.start)} to{' '}
                    {formatDay(span.end)}.
                </p>
            ) : (
                <ul className="grid grid-cols-1 divide-y divide-border">
                    {span.projects.map((project) => (
                        <li key={project.project_id} className="py-2">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span className="min-w-0 text-sm font-medium wrap-anywhere">
                                    {project.project}
                                </span>
                                <span className="text-sm tabular-nums">
                                    {formatDuration(project.minutes, style)}
                                </span>
                            </div>
                            <p className="text-xs wrap-anywhere text-muted-foreground">
                                {project.states
                                    .map(
                                        (state) =>
                                            `${statusLabel(state.status)} ${formatDuration(state.minutes, style)}`,
                                    )
                                    .join(' · ')}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function MyWork({ today, tasks, projects, time }: MyWorkProps) {
    const style = useDurationStyle();
    const [taskError, setTaskError] = useState<string | null>(null);

    return (
        <WorkspaceShell activeSection="my_work">
            <Head title="My work" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                    My work
                </h1>

                {taskError !== null && (
                    <p className="text-sm text-destructive" role="alert">
                        {taskError}
                    </p>
                )}

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <ThisWeek groups={tasks} onChanged={setTaskError} />
                    <QuickLog projects={projects} today={today} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>My time</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <TimeSpan
                                title="This week"
                                span={time.week}
                                style={style}
                            />
                            <TimeSpan
                                title="This month"
                                span={time.month}
                                style={style}
                            />
                        </div>
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
