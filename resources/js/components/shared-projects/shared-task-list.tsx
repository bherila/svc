import { CheckIcon, MessageSquareIcon, SendIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest } from '@/lib/api';
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type {
    AssigneeOption,
    SharedAssignee,
    SharedComment,
    SharedTask,
} from '@/types/shared-projects';

function AssigneeLine({ assignees }: { assignees: SharedAssignee[] }) {
    if (assignees.length === 0) {
        return <span>Nobody assigned</span>;
    }

    return (
        <span className="wrap-anywhere">
            {assignees
                .map((person) => `${person.name} (${person.workspace_name})`)
                .join(', ')}
        </span>
    );
}

/**
 * Hand a task to people of the host and of each participant. The options are
 * the server's: the people it is willing to offer. Anyone already on the task
 * is kept in the list so that unchecking them is a choice.
 */
function AssignDialog({
    task,
    options,
    open,
    onOpenChange,
}: {
    task: SharedTask;
    options: AssigneeOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const key = (workspaceId: string, userId: string) =>
        `${workspaceId}|${userId}`;
    const current = task.assignees.flatMap((person) =>
        person.user_id !== undefined && person.workspace_id !== undefined
            ? [
                  {
                      ...person,
                      user_id: person.user_id,
                      workspace_id: person.workspace_id,
                  },
              ]
            : [],
    );
    const choices = new Map<string, AssigneeOption>();

    for (const option of options) {
        choices.set(key(option.workspace_id, option.user_id), option);
    }

    for (const person of current) {
        const id = key(person.workspace_id, person.user_id);

        if (!choices.has(id)) {
            choices.set(id, {
                user_id: person.user_id,
                name: person.name,
                workspace_id: person.workspace_id,
                workspace_name: person.workspace_name,
            });
        }
    }

    const [selected, setSelected] = useState<Set<string>>(
        new Set(current.map((p) => key(p.workspace_id, p.user_id))),
    );
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const grouped = new Map<string, AssigneeOption[]>();

    for (const option of choices.values()) {
        grouped.set(option.workspace_name, [
            ...(grouped.get(option.workspace_name) ?? []),
            option,
        ]);
    }

    const save = async () => {
        if (task.assign_url === null || saving) {
            return;
        }

        setSaving(true);
        setError(null);

        const result = await apiRequest('PUT', task.assign_url, {
            assignees: [...choices.values()]
                .filter((option) =>
                    selected.has(key(option.workspace_id, option.user_id)),
                )
                .map((option) => ({
                    user_id: option.user_id,
                    workspace_id: option.workspace_id,
                })),
        });

        if (!result.ok) {
            setError(result.message);
            setSaving(false);

            return;
        }

        onOpenChange(false);
        void refreshPageProps({ onFinish: () => setSaving(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Assign this task</DialogTitle>
                    <DialogDescription className="wrap-anywhere">
                        Choose who works on “{task.title}”. Each person is shown
                        with the workspace they work for.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-80 grid-cols-1 gap-4 overflow-y-auto">
                    {[...grouped.entries()].map(([workspaceName, people]) => (
                        <fieldset
                            key={workspaceName}
                            className="grid grid-cols-1 gap-1"
                        >
                            <legend className="text-xs font-semibold tracking-wide wrap-anywhere text-muted-foreground uppercase">
                                {workspaceName}
                            </legend>
                            {people.map((person) => {
                                const id = key(
                                    person.workspace_id,
                                    person.user_id,
                                );
                                const inputId = `assign-${task.id}-${id}`;

                                return (
                                    <div
                                        key={id}
                                        className="flex items-center gap-2 py-1"
                                    >
                                        <input
                                            id={inputId}
                                            type="checkbox"
                                            className="size-4"
                                            checked={selected.has(id)}
                                            onChange={(event) => {
                                                const next = new Set(selected);

                                                if (event.target.checked) {
                                                    next.add(id);
                                                } else {
                                                    next.delete(id);
                                                }

                                                setSelected(next);
                                            }}
                                        />
                                        <Label
                                            htmlFor={inputId}
                                            className="min-w-0 wrap-anywhere"
                                        >
                                            {person.name}
                                        </Label>
                                    </div>
                                );
                            })}
                        </fieldset>
                    ))}
                </div>
                {error !== null && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button onClick={() => void save()} disabled={saving}>
                        <CheckIcon aria-hidden />
                        Save assignees
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The shared conversation on one task: loaded when opened, appended on send. */
function Discussion({ task }: { task: SharedTask }) {
    const [comments, setComments] = useState<SharedComment[] | null>(null);
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let current = true;

        void apiRequest<SharedComment[]>('GET', task.comments_url, {}).then(
            (result) => {
                if (!current) {
                    return;
                }

                if (result.ok) {
                    setComments(result.data);
                } else {
                    setError(result.message);
                }
            },
        );

        return () => {
            current = false;
        };
    }, [task.comments_url]);

    const send = async () => {
        const body = draft.trim();

        if (body === '' || busy) {
            return;
        }

        setBusy(true);
        setError(null);

        const result = await apiRequest<SharedComment>(
            'POST',
            task.comments_url,
            { body },
        );

        setBusy(false);

        if (!result.ok) {
            setError(result.message);

            return;
        }

        setComments([...(comments ?? []), result.data]);
        setDraft('');
    };

    return (
        <div className="grid grid-cols-1 gap-3 pt-2">
            {comments !== null && comments.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No comments yet.
                </p>
            )}
            {comments !== null && comments.length > 0 && (
                <ul className="grid grid-cols-1 gap-3">
                    {comments.map((comment) => (
                        <li key={comment.id} className="grid grid-cols-1 gap-1">
                            <p className="text-xs text-muted-foreground">
                                <span className="font-medium wrap-anywhere text-foreground">
                                    {comment.author_name}
                                </span>{' '}
                                · {comment.author_workspace_name} ·{' '}
                                {formatTimestamp(comment.created_at)}
                                {!comment.shared_with_participants &&
                                    ' · Internal'}
                            </p>
                            <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                                {comment.body}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
            {error !== null && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {task.can_comment && (
                <div className="grid grid-cols-1 gap-2">
                    <Label htmlFor={`comment-${task.id}`}>Add a comment</Label>
                    <Textarea
                        id={`comment-${task.id}`}
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        maxLength={50000}
                        rows={3}
                    />
                    <div className="flex justify-end">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => void send()}
                            disabled={busy || draft.trim() === ''}
                        >
                            <SendIcon aria-hidden />
                            Comment
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

function TaskItem({
    task,
    options,
}: {
    task: SharedTask;
    options: AssigneeOption[];
}) {
    const [open, setOpen] = useState(false);
    const [assigning, setAssigning] = useState(false);

    return (
        <li id={`task-${task.id}`} className="grid grid-cols-1 gap-1 py-3">
            <div className="flex flex-wrap items-start gap-x-3 gap-y-1">
                <p className="min-w-0 flex-1 basis-60 text-sm font-medium wrap-anywhere">
                    {task.title}
                </p>
                <span className="text-sm text-muted-foreground">
                    {statusLabel(task.status)}
                    {task.due_on !== null && ` · due ${formatDay(task.due_on)}`}
                </span>
            </div>
            <p className="text-xs text-muted-foreground">
                <AssigneeLine assignees={task.assignees} />
            </p>
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    variant="link"
                    size="sm"
                    aria-expanded={open}
                    onClick={() => setOpen(!open)}
                    className="h-auto px-0"
                >
                    <MessageSquareIcon aria-hidden />
                    {open ? 'Hide discussion' : 'Discussion'}
                </Button>
                {task.assign_url !== null && (
                    <Button
                        variant="link"
                        size="sm"
                        onClick={() => setAssigning(true)}
                        className="h-auto px-0"
                    >
                        Assign…
                    </Button>
                )}
            </div>
            {open && <Discussion task={task} />}
            {task.assign_url !== null && assigning && (
                <AssignDialog
                    task={task}
                    options={options}
                    open
                    onOpenChange={setAssigning}
                />
            )}
        </li>
    );
}

export function SharedTaskList({
    tasks,
    options,
    hasMore,
}: {
    tasks: SharedTask[];
    options: AssigneeOption[];
    hasMore: boolean;
}) {
    return (
        <Card data-test="shared-tasks">
            <CardHeader>
                <CardTitle>Tasks</CardTitle>
            </CardHeader>
            <CardContent>
                {tasks.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing is shared with you here yet.
                    </p>
                ) : (
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {tasks.map((task) => (
                            <TaskItem
                                key={task.id}
                                task={task}
                                options={options}
                            />
                        ))}
                    </ul>
                )}
                {hasMore && (
                    <p className="pt-3 text-xs text-muted-foreground">
                        The first {tasks.length} tasks are shown.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
