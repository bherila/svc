import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type {
    TaskOrganizationList,
    TaskOrganizationPageProps,
    TaskOrganizationTarget,
    TaskOrganizationView,
} from '@/types/task-organization';

type Mutation = ReturnType<typeof useOrganizationMutation>;
type OpenDialog =
    | { kind: 'new-list' }
    | {
          kind: 'edit-list' | 'archive-list' | 'restore-list';
          list: TaskOrganizationList;
      }
    | {
          kind: 'new-task';
          href: string;
          target: TaskOrganizationTarget;
          title: string;
      };

function useOrganizationMutation() {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inFlight = useRef(false);
    const keys = useRef(new Map<string, string>());

    async function run(
        method: 'POST' | 'PATCH' | 'PUT' | 'DELETE',
        href: string,
        body: object,
        saved?: () => void,
    ) {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setPending(true);
        setError(null);
        const signature = JSON.stringify([method, href, body]);
        const key = keys.current.get(signature) ?? newIdempotencyKey();
        keys.current.set(signature, key);

        try {
            const result = await apiRequest(method, href, body, key);

            if (!result.ok) {
                setError(result.message);

                return;
            }

            keys.current.delete(signature);
            saved?.();
            await new Promise<void>((resolve) => {
                router.reload({
                    onFinish: () => resolve(),
                });
            });
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    }

    return { pending, error, clearError: () => setError(null), run };
}

function reorder(ids: string[], index: number, offset: number): string[] {
    const target = index + offset;

    if (target < 0 || target >= ids.length) {
        return ids;
    }

    const next = [...ids];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

function OrderButtons({
    name,
    index,
    count,
    disabled,
    change,
}: {
    name: string;
    index: number;
    count: number;
    disabled: boolean;
    change: (offset: number) => void;
}) {
    return (
        <div className="flex shrink-0 gap-1">
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label={`Move ${name} up`}
                disabled={disabled || index === 0}
                onClick={() => change(-1)}
            >
                <ArrowUp />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label={`Move ${name} down`}
                disabled={disabled || index === count - 1}
                onClick={() => change(1)}
            >
                <ArrowDown />
            </Button>
        </div>
    );
}

function MoveHere({
    view,
    target,
    mutation,
    children = 'Move here',
}: {
    view: TaskOrganizationView;
    target: TaskOrganizationTarget | null;
    mutation: Mutation;
    children?: React.ReactNode;
}) {
    if (!view.moving || !target) {
        return null;
    }

    const source = view.moving;

    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={mutation.pending}
            onClick={() =>
                void mutation.run('PATCH', source.move_href, {
                    expected_version: source.version,
                    expected_graph_version: view.graph_version,
                    ...target,
                })
            }
        >
            {children}
        </Button>
    );
}

function ListPanel({
    view,
    mutation,
    open,
}: {
    view: TaskOrganizationView;
    mutation: Mutation;
    open: (dialog: OpenDialog) => void;
}) {
    const [order, setOrder] = useState(view.ordered_list_ids);
    const ordered = order
        .map((id) => view.lists.find((list) => list.id === id))
        .filter((list): list is TaskOrganizationList => list !== undefined);
    const rows = [
        ...ordered,
        ...view.lists.filter((list) => !order.includes(list.id)),
    ];
    const dirty =
        JSON.stringify(order) !== JSON.stringify(view.ordered_list_ids);

    return (
        <Card className="min-w-0 self-start">
            <CardHeader className="gap-3">
                <CardTitle>Lists</CardTitle>
                {view.create_list_href && !view.moving && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={mutation.pending}
                        onClick={() => open({ kind: 'new-list' })}
                    >
                        New list
                    </Button>
                )}
            </CardHeader>
            <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                <div className="grid min-w-0 grid-cols-1 gap-2 rounded-md border p-3">
                    <Link
                        href={view.unlisted.browse_href}
                        className="font-medium wrap-anywhere underline underline-offset-4"
                    >
                        Unlisted tasks
                    </Link>
                    <MoveHere
                        view={view}
                        target={view.unlisted.move_target}
                        mutation={mutation}
                    />
                </div>
                <nav
                    aria-label="Task lists"
                    className="max-h-[32rem] min-w-0 overflow-y-auto"
                >
                    <ul className="grid min-w-0 grid-cols-1 gap-3">
                        {rows.map((list) => {
                            const index = order.indexOf(list.id);

                            return (
                                <li
                                    key={list.id}
                                    className="grid min-w-0 grid-cols-1 gap-3 rounded-md border p-3"
                                >
                                    <Link
                                        href={list.browse_href}
                                        className="font-medium wrap-anywhere underline underline-offset-4"
                                    >
                                        {list.name}
                                    </Link>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary">
                                            {list.archived
                                                ? 'Archived'
                                                : list.is_visible_to_client
                                                  ? 'Client sharing enabled'
                                                  : 'Internal'}
                                        </Badge>
                                        {view.reorder_lists_href &&
                                            index >= 0 &&
                                            !view.moving && (
                                                <OrderButtons
                                                    name={list.name}
                                                    index={index}
                                                    count={order.length}
                                                    disabled={mutation.pending}
                                                    change={(offset) =>
                                                        setOrder((current) =>
                                                            reorder(
                                                                current,
                                                                index,
                                                                offset,
                                                            ),
                                                        )
                                                    }
                                                />
                                            )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {!view.moving && list.update_href && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={mutation.pending}
                                                onClick={() =>
                                                    open({
                                                        kind: 'edit-list',
                                                        list,
                                                    })
                                                }
                                            >
                                                Edit list
                                            </Button>
                                        )}
                                        {!view.moving && list.archive_href && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={mutation.pending}
                                                onClick={() =>
                                                    open({
                                                        kind: 'archive-list',
                                                        list,
                                                    })
                                                }
                                            >
                                                Archive list
                                            </Button>
                                        )}
                                        {!view.moving && list.restore_href && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={mutation.pending}
                                                onClick={() =>
                                                    open({
                                                        kind: 'restore-list',
                                                        list,
                                                    })
                                                }
                                            >
                                                Restore list
                                            </Button>
                                        )}
                                        <MoveHere
                                            view={view}
                                            target={list.move_target}
                                            mutation={mutation}
                                        />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
                {view.reorder_lists_href &&
                    !view.moving &&
                    order.length > 1 && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                disabled={!dirty || mutation.pending}
                                onClick={() =>
                                    void mutation.run(
                                        'PUT',
                                        view.reorder_lists_href!,
                                        {
                                            ordered_ids: order,
                                            expected_container_version:
                                                view.lists_version,
                                        },
                                    )
                                }
                            >
                                Save list order
                            </Button>
                            {dirty && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={mutation.pending}
                                    onClick={() =>
                                        setOrder(view.ordered_list_ids)
                                    }
                                >
                                    Reset order
                                </Button>
                            )}
                        </div>
                    )}
            </CardContent>
        </Card>
    );
}

function TaskPanel({
    view,
    mutation,
    open,
}: {
    view: TaskOrganizationView;
    mutation: Mutation;
    open: (dialog: OpenDialog) => void;
}) {
    const { container } = view;
    const [order, setOrder] = useState(container.tasks.map((task) => task.id));
    const dirty =
        JSON.stringify(order) !==
        JSON.stringify(container.tasks.map((task) => task.id));

    return (
        <Card className="min-w-0 self-start">
            <CardHeader className="gap-3">
                {container.breadcrumbs.length > 0 && (
                    <nav aria-label="Task hierarchy">
                        <ol className="flex flex-wrap gap-x-3 gap-y-2 text-sm">
                            {container.breadcrumbs.map((crumb) => (
                                <li
                                    key={crumb.href}
                                    className="min-w-0 wrap-anywhere"
                                >
                                    <Link
                                        href={crumb.href}
                                        className="underline underline-offset-4"
                                    >
                                        {crumb.label}
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    </nav>
                )}
                <CardTitle className="wrap-anywhere">
                    {container.title}
                </CardTitle>
                {container.sharing_note && (
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {container.sharing_note}
                    </p>
                )}
                <div className="flex flex-wrap gap-2">
                    {!view.moving && container.create_task_href && (
                        <Button
                            type="button"
                            disabled={mutation.pending}
                            onClick={() =>
                                open({
                                    kind: 'new-task',
                                    href: container.create_task_href!,
                                    target: container.target,
                                    title: container.target.parent_id
                                        ? 'New subtask'
                                        : 'New task',
                                })
                            }
                        >
                            {container.target.parent_id
                                ? 'New subtask'
                                : 'New task'}
                        </Button>
                    )}
                    <MoveHere
                        view={view}
                        target={container.move_target}
                        mutation={mutation}
                    />
                </div>
            </CardHeader>
            <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                {order.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No tasks in this container yet.
                    </p>
                )}
                <ol className="grid min-w-0 grid-cols-1 gap-3">
                    {order.map((id, index) => {
                        const task = container.tasks.find(
                            (row) => row.id === id,
                        );

                        if (!task) {
                            return null;
                        }

                        return (
                            <li
                                key={task.id}
                                className="grid min-w-0 grid-cols-1 gap-3 rounded-md border p-4"
                            >
                                <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 flex-1">
                                        <Link
                                            href={task.task_href}
                                            className="font-medium wrap-anywhere underline underline-offset-4"
                                        >
                                            {task.title}
                                        </Link>
                                    </div>
                                    {container.reorder_href && !view.moving && (
                                        <OrderButtons
                                            name={task.title}
                                            index={index}
                                            count={order.length}
                                            disabled={mutation.pending}
                                            change={(offset) =>
                                                setOrder((current) =>
                                                    reorder(
                                                        current,
                                                        index,
                                                        offset,
                                                    ),
                                                )
                                            }
                                        />
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="secondary">
                                        {task.status_label}
                                    </Badge>
                                    <Badge variant="outline">
                                        {task.effective_visible_to_client
                                            ? 'Client sharing enabled'
                                            : task.is_visible_to_client
                                              ? 'Hidden by project or parent'
                                              : 'Internal'}
                                    </Badge>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link
                                        href={task.browse_href}
                                        className="text-sm underline underline-offset-4"
                                    >
                                        Subtasks
                                    </Link>
                                    {!view.moving && task.create_child_href && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={mutation.pending}
                                            onClick={() =>
                                                open({
                                                    kind: 'new-task',
                                                    href: task.create_child_href!,
                                                    target: task.child_target,
                                                    title: 'New subtask',
                                                })
                                            }
                                        >
                                            New subtask
                                        </Button>
                                    )}
                                    {!view.moving && (
                                        <Link
                                            href={task.move_picker_href}
                                            className="text-sm underline underline-offset-4"
                                        >
                                            Move task
                                        </Link>
                                    )}
                                    <MoveHere
                                        view={view}
                                        target={task.move_target}
                                        mutation={mutation}
                                    >
                                        Move under this task
                                    </MoveHere>
                                </div>
                            </li>
                        );
                    })}
                </ol>
                {container.reorder_href && !view.moving && order.length > 1 && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            disabled={!dirty || mutation.pending}
                            onClick={() =>
                                void mutation.run(
                                    'PUT',
                                    container.reorder_href!,
                                    {
                                        ...container.target,
                                        ordered_ids: order,
                                        expected_container_version:
                                            container.version,
                                    },
                                )
                            }
                        >
                            Save task order
                        </Button>
                        {dirty && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={mutation.pending}
                                onClick={() =>
                                    setOrder(
                                        container.tasks.map((task) => task.id),
                                    )
                                }
                            >
                                Reset order
                            </Button>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ListForm({
    list,
    href,
    view,
    mutation,
    saved,
}: {
    list: TaskOrganizationList | null;
    href: string;
    view: TaskOrganizationView;
    mutation: Mutation;
    saved: () => void;
}) {
    const [name, setName] = useState(list?.name ?? '');
    const [visible, setVisible] = useState(list?.is_visible_to_client ?? false);

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation.run(
                    list ? 'PATCH' : 'POST',
                    href,
                    {
                        name,
                        is_visible_to_client: visible,
                        expected_graph_version: view.graph_version,
                        ...(list
                            ? {
                                  expected_version: list.version,
                              }
                            : {}),
                    },
                    saved,
                );
            }}
        >
            <div className="grid grid-cols-1 gap-2">
                <Label htmlFor="organization-list-name">List name</Label>
                <Input
                    id="organization-list-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    required
                    maxLength={255}
                />
            </div>
            <div className="flex items-center gap-2">
                <Switch
                    id="organization-list-sharing"
                    checked={visible}
                    onCheckedChange={(value) => setVisible(value === true)}
                />
                <Label htmlFor="organization-list-sharing">
                    Visible to client
                </Label>
            </div>
            <p className="text-sm text-muted-foreground">
                Tasks keep their own sharing choices. Making this list visible
                can reveal tasks already selected for client sharing.
            </p>
            {mutation.error && (
                <p role="alert" className="wrap-anywhere text-destructive">
                    {mutation.error}
                </p>
            )}
            <Button type="submit" disabled={mutation.pending}>
                {list ? 'Save list' : 'Create list'}
            </Button>
        </form>
    );
}

function NewTaskForm({
    dialog,
    view,
    mutation,
    saved,
}: {
    dialog: Extract<OpenDialog, { kind: 'new-task' }>;
    view: TaskOrganizationView;
    mutation: Mutation;
    saved: () => void;
}) {
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [visible, setVisible] = useState(false);
    const [startsOn, setStartsOn] = useState('');
    const [dueOn, setDueOn] = useState('');

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation.run(
                    'POST',
                    dialog.href,
                    {
                        expected_graph_version: view.graph_version,
                        ...dialog.target,
                        task: {
                            title,
                            description:
                                description === '' ? null : description,
                            is_visible_to_client: visible,
                            starts_on: startsOn || null,
                            due_on: dueOn || null,
                        },
                    },
                    saved,
                );
            }}
        >
            <div className="grid grid-cols-1 gap-2">
                <Label htmlFor="organization-task-title">Title</Label>
                <Input
                    id="organization-task-title"
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    required
                    maxLength={255}
                />
            </div>
            <div className="grid grid-cols-1 gap-2">
                <Label htmlFor="organization-task-description">
                    Description
                </Label>
                <Textarea
                    id="organization-task-description"
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                    maxLength={10000}
                    rows={5}
                />
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="grid grid-cols-1 gap-2">
                    <Label htmlFor="organization-task-start">Start date</Label>
                    <Input
                        id="organization-task-start"
                        type="date"
                        value={startsOn}
                        onChange={(event) => setStartsOn(event.target.value)}
                    />
                </div>
                <div className="grid grid-cols-1 gap-2">
                    <Label htmlFor="organization-task-due">Due date</Label>
                    <Input
                        id="organization-task-due"
                        type="date"
                        value={dueOn}
                        onChange={(event) => setDueOn(event.target.value)}
                    />
                </div>
            </div>
            <div className="flex items-center gap-2">
                <Switch
                    id="organization-task-sharing"
                    checked={visible}
                    onCheckedChange={(value) => setVisible(value === true)}
                />
                <Label htmlFor="organization-task-sharing">
                    Visible to client
                </Label>
            </div>
            <p className="text-sm text-muted-foreground">
                Open the created task to assign people. The project and parent
                visibility still apply.
            </p>
            {mutation.error && (
                <p role="alert" className="wrap-anywhere text-destructive">
                    {mutation.error}
                </p>
            )}
            <Button type="submit" disabled={mutation.pending}>
                Create task
            </Button>
        </form>
    );
}

export default function TaskOrganizationPage({
    company,
    project,
    organization: view,
}: TaskOrganizationPageProps) {
    const mutation = useOrganizationMutation();
    const [dialog, setDialog] = useState<OpenDialog | null>(null);
    const open = (next: OpenDialog) => {
        mutation.clearError();
        setDialog(next);
    };
    const close = () => setDialog(null);
    const reloadCurrent = () => {
        mutation.clearError();
        close();
        router.reload();
    };
    const dialogTitle =
        dialog?.kind === 'new-task'
            ? dialog.title
            : dialog
              ? {
                    'new-list': 'New list',
                    'edit-list': 'Edit list',
                    'archive-list': 'Archive list',
                    'restore-list': 'Restore list',
                }[dialog.kind]
              : '';

    return (
        <WorkspaceShell activeModule="tasks">
            <Head title={`Organize tasks · ${project.name}`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <div className="grid min-w-0 grid-cols-1 gap-2">
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        Organize tasks
                    </h1>
                    <p className="wrap-anywhere text-muted-foreground">
                        {company.name} · {project.name}
                    </p>
                </div>
                {view.moving && (
                    <Card className="min-w-0">
                        <CardContent className="grid min-w-0 grid-cols-1 gap-3 pt-6">
                            <p className="wrap-anywhere">
                                Choose a destination for{' '}
                                <strong>{view.moving.title}</strong>.
                            </p>
                            <Link
                                href={view.moving.cancel_href}
                                className="text-sm underline underline-offset-4"
                            >
                                Cancel move
                            </Link>
                        </CardContent>
                    </Card>
                )}
                {mutation.error && !dialog && (
                    <div
                        role="alert"
                        className="grid min-w-0 grid-cols-1 gap-3 rounded-md border border-destructive p-4"
                    >
                        <p className="wrap-anywhere text-destructive">
                            {mutation.error}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            className="justify-self-start"
                            disabled={mutation.pending}
                            onClick={reloadCurrent}
                        >
                            Reload current state
                        </Button>
                    </div>
                )}
                <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                    <ListPanel
                        key={view.lists_version}
                        view={view}
                        mutation={mutation}
                        open={open}
                    />
                    <TaskPanel
                        key={view.container.version}
                        view={view}
                        mutation={mutation}
                        open={open}
                    />
                </div>
                <Dialog
                    open={dialog !== null}
                    onOpenChange={(value) => {
                        if (!value && !mutation.pending) {
                            close();
                        }
                    }}
                >
                    <DialogContent className="max-h-[90dvh] min-w-0 overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>{dialogTitle}</DialogTitle>
                            <DialogDescription>
                                Organize work within this project. Client
                                visibility remains subject to its parents and
                                current access.
                            </DialogDescription>
                        </DialogHeader>
                        {dialog?.kind === 'new-list' &&
                            view.create_list_href && (
                                <ListForm
                                    list={null}
                                    href={view.create_list_href}
                                    view={view}
                                    mutation={mutation}
                                    saved={close}
                                />
                            )}
                        {dialog?.kind === 'edit-list' &&
                            dialog.list.update_href && (
                                <ListForm
                                    list={dialog.list}
                                    href={dialog.list.update_href}
                                    view={view}
                                    mutation={mutation}
                                    saved={close}
                                />
                            )}
                        {(dialog?.kind === 'archive-list' ||
                            dialog?.kind === 'restore-list') && (
                            <div className="grid min-w-0 grid-cols-1 gap-4">
                                <p className="wrap-anywhere">
                                    {dialog.kind === 'archive-list'
                                        ? 'Archive'
                                        : 'Restore'}{' '}
                                    <strong>{dialog.list.name}</strong>?
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {dialog.kind === 'archive-list'
                                        ? 'Tasks stay in this list. Archived lists and their tasks are hidden from clients.'
                                        : 'The list keeps its saved visibility setting. Its tasks also keep their own sharing choices.'}
                                </p>
                                {mutation.error && (
                                    <p
                                        role="alert"
                                        className="wrap-anywhere text-destructive"
                                    >
                                        {mutation.error}
                                    </p>
                                )}
                                <Button
                                    type="button"
                                    disabled={mutation.pending}
                                    onClick={() => {
                                        const href =
                                            dialog.kind === 'archive-list'
                                                ? dialog.list.archive_href
                                                : dialog.list.restore_href;

                                        if (href) {
                                            void mutation.run(
                                                dialog.kind === 'archive-list'
                                                    ? 'DELETE'
                                                    : 'POST',
                                                href,
                                                {
                                                    expected_version:
                                                        dialog.list.version,
                                                    expected_graph_version:
                                                        view.graph_version,
                                                    ...(dialog.kind ===
                                                    'archive-list'
                                                        ? { confirm: true }
                                                        : {}),
                                                },
                                                close,
                                            );
                                        }
                                    }}
                                >
                                    {dialog.kind === 'archive-list'
                                        ? 'Confirm archive'
                                        : 'Confirm restore'}
                                </Button>
                            </div>
                        )}
                        {dialog?.kind === 'new-task' && (
                            <NewTaskForm
                                dialog={dialog}
                                view={view}
                                mutation={mutation}
                                saved={close}
                            />
                        )}
                        {mutation.error && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={mutation.pending}
                                onClick={reloadCurrent}
                            >
                                Reload current state
                            </Button>
                        )}
                    </DialogContent>
                </Dialog>
            </main>
        </WorkspaceShell>
    );
}
