import { useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
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
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';

export type TaskAssignee = { id: string; name: string };
export type TaskAssigneeOptions = {
    data: TaskAssignee[];
    has_more: boolean;
};
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
    assignee_options?: TaskAssigneeOptions | null;
    completion_advisory?: string | null;
};
export type TaskEditorProject = {
    id: string;
    name: string;
    create_action: string;
    assignees_action: string;
    assignee_options?: TaskAssigneeOptions | null;
};

const statuses = ['open', 'in_progress', 'completed'].map((value) => ({
    value,
    label: statusLabel(value),
}));

function AssigneePicker({
    action,
    initialOptions,
    selected,
    onChange,
}: {
    action: string;
    initialOptions: TaskAssigneeOptions | null;
    selected: TaskAssignee[];
    onChange: (next: TaskAssignee[]) => void;
}) {
    const searchId = useId();
    const [search, setSearch] = useState('');
    const [lookup, setLookup] = useState<{
        search: string;
        options: TaskAssigneeOptions;
        error: string | null;
    } | null>(null);
    const hasInitialOptions = initialOptions !== null;
    const showInitialOptions = search === '' && hasInitialOptions;
    const currentLookup = lookup?.search === search ? lookup : null;
    const options = showInitialOptions
        ? initialOptions.data
        : (currentLookup?.options.data ?? []);
    const hasMore = showInitialOptions
        ? initialOptions.has_more
        : (currentLookup?.options.has_more ?? false);
    const error = showInitialOptions ? null : currentLookup?.error;

    useEffect(() => {
        // An empty seeded page is a complete initial answer too. Further
        // requests belong to typed searches or a newly selected unseeded project.
        if (search === '' && hasInitialOptions) {
            return;
        }

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

                const data: TaskAssigneeOptions = await response.json();

                if (!abort.signal.aborted) {
                    setLookup({ search, options: data, error: null });
                }
            } catch {
                if (!abort.signal.aborted) {
                    setLookup({
                        search,
                        options: { data: [], has_more: false },
                        error: 'Staff could not be loaded. Try another search.',
                    });
                }
            }
        }, 200);

        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [action, search, hasInitialOptions]);

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
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inFlight = useRef(false);
    const attempt = useRef<{ signature: string; key: string } | null>(null);
    const action = task?.update_action ?? project?.create_action;
    const assigneesAction = task?.assignees_action ?? project?.assignees_action;
    const assigneeOptions =
        task === null
            ? (project?.assignee_options ?? null)
            : (task.assignee_options ?? null);

    if (!action || !assigneesAction) {
        return null;
    }

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (inFlight.current) {
            return;
        }

        const body = {
            title: form.data.title,
            description: form.data.description || null,
            is_visible_to_client: form.data.is_visible_to_client,
            starts_on: form.data.starts_on || null,
            due_on: form.data.due_on || null,
            assignee_ids: form.data.assignee_ids,
            ...(task === null
                ? {}
                : {
                      status: form.data.status,
                      expected_version: form.data.expected_version,
                  }),
        };
        const method = task === null ? 'POST' : 'PATCH';
        const signature = JSON.stringify([method, action, body]);

        if (attempt.current?.signature !== signature) {
            attempt.current = { signature, key: newIdempotencyKey() };
        }

        inFlight.current = true;
        setPending(true);
        setError(null);
        form.clearErrors();

        try {
            const result = await apiRequest<{ version: string }>(
                method,
                action,
                body,
                attempt.current.key,
            );

            if (!result.ok) {
                setError(result.message);

                if (result.status === 409) {
                    form.setError(
                        'expected_version',
                        'This task has changed. Reload it before saving your changes.',
                    );
                } else {
                    for (const [field, messages] of Object.entries(
                        result.errors ?? {},
                    )) {
                        form.setError(
                            field as keyof typeof form.data,
                            messages[0],
                        );
                    }
                }

                if (result.status >= 400 && result.status < 500) {
                    attempt.current = null;
                }

                return;
            }

            await refreshPageProps({
                requireSuccess: true,
                only: ['task', 'tasks', 'task_management', 'planning_details'],
            });

            // Keep the original body/key until the refresh also succeeds, so
            // retrying a saved creation cannot create a second task.
            attempt.current = null;

            if (task !== null) {
                form.setData('expected_version', result.data.version);
            }

            if (task === null) {
                form.reset();
                setSelected([]);
            }

            onSaved?.();
        } catch {
            setError(
                'The task was saved, but current tasks could not be refreshed. Reload before making another change.',
            );
        } finally {
            inFlight.current = false;
            setPending(false);
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
                key={assigneesAction}
                action={assigneesAction}
                initialOptions={assigneeOptions}
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
            {error && !form.errors.expected_version && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            )}
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
            <Button type="submit" disabled={pending}>
                {task === null ? 'Create task' : 'Save task'}
            </Button>
        </form>
    );
}
