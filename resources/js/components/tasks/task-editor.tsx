import { useForm } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { statusLabel } from '@/lib/labels';

export type TaskAssignee = { id: string; name: string };
export type EditableTask = {
    id: string;
    title: string;
    description: string | null;
    status: string;
    is_visible_to_client: boolean;
    starts_on: string | null;
    due_on: string | null;
    assignees: TaskAssignee[];
    version: string;
    update_action: string;
    assignees_action: string;
    completion_advisory?: string | null;
};
export type TaskEditorProject = {
    id: string;
    name: string;
    create_action: string;
    assignees_action: string;
};

const statuses = ['open', 'in_progress', 'completed'].map((value) => ({
    value,
    label: statusLabel(value),
}));

function AssigneePicker({
    action,
    selected,
    onChange,
}: {
    action: string;
    selected: TaskAssignee[];
    onChange: (next: TaskAssignee[]) => void;
}) {
    const searchId = useId();
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<TaskAssignee[]>([]);
    const [hasMore, setHasMore] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        const abort = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const url = new URL(action, window.location.origin);
                url.searchParams.set('q', search);
                const response = await fetch(url, {
                    signal: abort.signal,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('lookup');
                }

                const data: { data: TaskAssignee[]; has_more: boolean } =
                    await response.json();

                if (!abort.signal.aborted) {
                    setOptions(data.data);
                    setHasMore(data.has_more);
                    setError(null);
                }
            } catch {
                if (!abort.signal.aborted) {
                    setError('Staff could not be loaded. Try another search.');
                }
            }
        }, 200);

        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [action, search]);

    return (
        <fieldset className="grid min-w-0 grid-cols-1 gap-2">
            <legend className="mb-2 text-sm font-medium">Assignees</legend>
            <div className="flex min-w-0 flex-wrap gap-2">
                {selected.map((person) => (
                    <Button
                        key={person.id}
                        type="button"
                        variant="outline"
                        className="h-auto max-w-full min-w-0 wrap-anywhere whitespace-normal"
                        onClick={() =>
                            onChange(
                                selected.filter(
                                    (item) => item.id !== person.id,
                                ),
                            )
                        }
                        aria-label={`Remove ${person.name}`}
                    >
                        {person.name} ×
                    </Button>
                ))}
            </div>
            <Label htmlFor={searchId}>Find staff with project access</Label>
            <Input
                id={searchId}
                type="search"
                maxLength={100}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
            />
            <div className="grid max-h-48 min-w-0 grid-cols-1 gap-2 overflow-y-auto rounded-lg border p-3">
                {options.map((person) => {
                    const checked = selected.some(
                        (item) => item.id === person.id,
                    );

                    return (
                        <label
                            key={person.id}
                            className="flex min-w-0 items-start gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={checked}
                                disabled={!checked && selected.length >= 10}
                                onChange={() =>
                                    onChange(
                                        checked
                                            ? selected.filter(
                                                  (item) =>
                                                      item.id !== person.id,
                                              )
                                            : [...selected, person],
                                    )
                                }
                            />
                            <span className="min-w-0 wrap-anywhere">
                                {person.name}
                            </span>
                        </label>
                    );
                })}
                {options.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No matching staff loaded.
                    </p>
                )}
            </div>
            {hasMore && (
                <p className="text-sm text-muted-foreground">
                    More staff match. Refine the search to find them.
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                Up to 10 assignees. Assignment does not change access.
            </p>
        </fieldset>
    );
}

/** The same form creates tasks and edits the exact revision the operator read. */
export function TaskEditor({
    task = null,
    project = null,
    onSaved,
}: {
    task?: EditableTask | null;
    project?: TaskEditorProject | null;
    onSaved?: () => void;
}) {
    const prefix = useId();
    const [selected, setSelected] = useState(task?.assignees ?? []);
    const form = useForm({
        title: task?.title ?? '',
        description: task?.description ?? '',
        status: task?.status ?? 'open',
        is_visible_to_client: task?.is_visible_to_client ?? false,
        starts_on: task?.starts_on ?? '',
        due_on: task?.due_on ?? '',
        assignee_ids: task?.assignees.map((person) => person.id) ?? [],
        expected_version: task?.version ?? '',
    });
    const action = task?.update_action ?? project?.create_action;
    const assigneesAction = task?.assignees_action ?? project?.assignees_action;

    if (!action || !assigneesAction) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            title: data.title,
            description: data.description || null,
            is_visible_to_client: data.is_visible_to_client,
            starts_on: data.starts_on || null,
            due_on: data.due_on || null,
            assignee_ids: data.assignee_ids,
            ...(task === null
                ? {}
                : {
                      status: data.status,
                      expected_version: data.expected_version,
                  }),
        }));
        const options = {
            preserveScroll: true,
            onHttpException: (response: { status: number }) => {
                if (response.status === 409) {
                    form.setError(
                        'expected_version',
                        'This task has changed. Reload it before saving your changes.',
                    );

                    return false;
                }
            },
            onSuccess: () => {
                if (task === null) {
                    form.reset();
                    setSelected([]);
                }

                onSaved?.();
            },
        };

        if (task === null) {
            form.post(action, options);
        } else {
            form.patch(action, options);
        }
    };

    return (
        <form onSubmit={submit} className="grid min-w-0 grid-cols-1 gap-4">
            <div className="grid min-w-0 grid-cols-1 gap-2">
                <Label htmlFor={`${prefix}-title`}>Title</Label>
                <Input
                    id={`${prefix}-title`}
                    required
                    maxLength={255}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
            </div>
            <div className="grid min-w-0 grid-cols-1 gap-2">
                <Label htmlFor={`${prefix}-description`}>Description</Label>
                <Textarea
                    className="min-w-0 wrap-anywhere"
                    id={`${prefix}-description`}
                    maxLength={10000}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </div>
            <div className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="grid min-w-0 grid-cols-1 gap-2">
                    <Label htmlFor={`${prefix}-start`}>Start date</Label>
                    <Input
                        id={`${prefix}-start`}
                        type="date"
                        value={form.data.starts_on}
                        onChange={(event) =>
                            form.setData('starts_on', event.target.value)
                        }
                    />
                </div>
                <div className="grid min-w-0 grid-cols-1 gap-2">
                    <Label htmlFor={`${prefix}-due`}>Due date</Label>
                    <Input
                        id={`${prefix}-due`}
                        type="date"
                        min={form.data.starts_on || undefined}
                        value={form.data.due_on}
                        onChange={(event) =>
                            form.setData('due_on', event.target.value)
                        }
                    />
                </div>
            </div>
            {task !== null && (
                <div className="grid min-w-0 grid-cols-1 gap-2">
                    <Label htmlFor={`${prefix}-status`}>Status</Label>
                    <Select
                        items={statuses}
                        value={form.data.status}
                        onValueChange={(value) => {
                            if (value !== null) {
                                form.setData('status', value);
                            }
                        }}
                    >
                        <SelectTrigger id={`${prefix}-status`}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {form.data.status === 'completed' &&
                        task.completion_advisory && (
                            <p className="text-sm wrap-anywhere text-muted-foreground">
                                {task.completion_advisory}
                            </p>
                        )}
                </div>
            )}
            <AssigneePicker
                action={assigneesAction}
                selected={selected}
                onChange={(next) => {
                    setSelected(next);
                    form.setData(
                        'assignee_ids',
                        next.map((person) => person.id),
                    );
                }}
            />
            <div className="flex items-center gap-2">
                <Switch
                    id={`${prefix}-visibility`}
                    checked={form.data.is_visible_to_client}
                    onCheckedChange={(checked) =>
                        form.setData('is_visible_to_client', checked)
                    }
                />
                <Label htmlFor={`${prefix}-visibility`}>
                    Visible to client
                </Label>
            </div>
            {Object.entries(form.errors).map(([key, error]) => (
                <p
                    key={key}
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            ))}
            {form.errors.expected_version && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => window.location.reload()}
                >
                    Reload task
                </Button>
            )}
            <Button type="submit" disabled={form.processing}>
                {task === null ? 'Create task' : 'Save task'}
            </Button>
        </form>
    );
}
