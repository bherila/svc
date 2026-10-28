import { Head, Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import {
    PlanningMutationError,
    usePlanningMutation,
} from '@/components/tasks/planning-controls';
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
import { SHELL_CONTAINER } from '@/lib/layout';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { cn } from '@/lib/utils';
import type {
    TaskOrganizationList,
    TaskOrganizationPageProps,
    TaskOrganizationTarget,
    TaskOrganizationView,
} from '@/types/task-organization';

type Mutation = ReturnType<typeof useOrganizationMutation>;
type DialogSelection =
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
type OpenDialog = DialogSelection & { graphVersion: string };

function useOrganizationMutation() {
    return usePlanningMutation(
        () =>
            refreshPageProps({ only: ['organization'], requireSuccess: true }),
        'Task organization',
    );
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
            disabled={mutation.locked}
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
    open: (dialog: DialogSelection) => void;
}) {
    const [draft, setDraft] = useState<{
        ids: string[];
        version: string;
    } | null>(null);
    const order = draft?.ids ?? view.ordered_list_ids;
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
                        disabled={mutation.locked}
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
                                                    disabled={mutation.locked}
                                                    change={(offset) =>
                                                        setDraft((current) => ({
                                                            ids: reorder(
                                                                current?.ids ??
                                                                    view.ordered_list_ids,
                                                                index,
                                                                offset,
                                                            ),
                                                            version:
                                                                current?.version ??
                                                                view.lists_version,
                                                        }))
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
                                                disabled={mutation.locked}
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
                                                disabled={mutation.locked}
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
                                                disabled={mutation.locked}
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
                                disabled={!dirty || mutation.locked}
                                onClick={() =>
                                    void mutation.run(
                                        'PUT',
                                        view.reorder_lists_href!,
                                        {
                                            ordered_ids: order,
                                            expected_container_version:
                                                draft?.version ??
                                                view.lists_version,
                                        },
                                        () => setDraft(null),
                                    )
                                }
                            >
                                Save list order
                            </Button>
                            {dirty && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={mutation.locked}
                                    onClick={() => setDraft(null)}
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
    open: (dialog: DialogSelection) => void;
}) {
    const { container } = view;
    const [draft, setDraft] = useState<{
        ids: string[];
        version: string;
        target: TaskOrganizationTarget;
    } | null>(null);
    const order = draft?.ids ?? container.tasks.map((task) => task.id);
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
                            disabled={mutation.locked}
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
                                            disabled={mutation.locked}
                                            change={(offset) =>
                                                setDraft((current) => ({
                                                    ids: reorder(
                                                        current?.ids ??
                                                            container.tasks.map(
                                                                (task) =>
                                                                    task.id,
                                                            ),
                                                        index,
                                                        offset,
                                                    ),
                                                    version:
                                                        current?.version ??
                                                        container.version,
                                                    target:
                                                        current?.target ??
                                                        container.target,
                                                }))
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
                                            disabled={mutation.locked}
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
                            disabled={!dirty || mutation.locked}
                            onClick={() =>
                                void mutation.run(
                                    'PUT',
                                    container.reorder_href!,
                                    {
                                        ...(draft?.target ?? container.target),
                                        ordered_ids: order,
                                        expected_container_version:
                                            draft?.version ?? container.version,
                                    },
                                    () => setDraft(null),
                                )
                            }
                        >
                            Save task order
                        </Button>
                        {dirty && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={mutation.locked}
                                onClick={() => setDraft(null)}
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
    graphVersion,
    mutation,
    saved,
}: {
    list: TaskOrganizationList | null;
    href: string;
    graphVersion: string;
    mutation: Mutation;
    saved: () => void;
}) {
    const [baseline] = useState({
        href,
        graphVersion,
        version: list?.version,
    });
    const [name, setName] = useState(list?.name ?? '');
    const [visible, setVisible] = useState(list?.is_visible_to_client ?? false);

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation.run(
                    list ? 'PATCH' : 'POST',
                    baseline.href,
                    {
                        name,
                        is_visible_to_client: visible,
                        expected_graph_version: baseline.graphVersion,
                        ...(list
                            ? {
                                  expected_version: baseline.version,
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
                    disabled={mutation.locked}
                    id="organization-list-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    required
                    maxLength={255}
                />
            </div>
            <div className="flex items-center gap-2">
                <Switch
                    disabled={mutation.locked}
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
            {mutation.error && <PlanningMutationError mutation={mutation} />}
            <Button type="submit" disabled={mutation.locked}>
                {list ? 'Save list' : 'Create list'}
            </Button>
        </form>
    );
}

function NewTaskForm({
    dialog,
    mutation,
    saved,
}: {
    dialog: Extract<OpenDialog, { kind: 'new-task' }>;
    mutation: Mutation;
    saved: () => void;
}) {
    const [baseline] = useState({
        href: dialog.href,
        graphVersion: dialog.graphVersion,
        target: dialog.target,
    });
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
                    baseline.href,
                    {
                        expected_graph_version: baseline.graphVersion,
                        ...baseline.target,
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
                    disabled={mutation.locked}
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
                    disabled={mutation.locked}
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
                        disabled={mutation.locked}
                        id="organization-task-start"
                        type="date"
                        value={startsOn}
                        onChange={(event) => setStartsOn(event.target.value)}
                    />
                </div>
                <div className="grid grid-cols-1 gap-2">
                    <Label htmlFor="organization-task-due">Due date</Label>
                    <Input
                        disabled={mutation.locked}
                        id="organization-task-due"
                        type="date"
                        value={dueOn}
                        onChange={(event) => setDueOn(event.target.value)}
                    />
                </div>
            </div>
            <div className="flex items-center gap-2">
                <Switch
                    disabled={mutation.locked}
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
            {mutation.error && <PlanningMutationError mutation={mutation} />}
            <Button type="submit" disabled={mutation.locked}>
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
    const [draftEpoch, setDraftEpoch] = useState(0);
    const open = (next: DialogSelection) => {
        if (mutation.locked) {
            return;
        }

        mutation.clearError();
        setDialog({ ...next, graphVersion: view.graph_version });
    };
    const close = () => setDialog(null);
    const reloadCurrent = () =>
        void mutation.reload(() => {
            close();
            setDraftEpoch((current) => current + 1);
        });
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
                    <div className="grid min-w-0 grid-cols-1 gap-3 rounded-md border border-destructive p-4">
                        <PlanningMutationError mutation={mutation} />
                        <Button
                            type="button"
                            variant="outline"
                            className="justify-self-start"
                            disabled={mutation.locked}
                            onClick={reloadCurrent}
                        >
                            Reload current state
                        </Button>
                    </div>
                )}
                <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
                    <ListPanel
                        key={`${project.id}:${draftEpoch}`}
                        view={view}
                        mutation={mutation}
                        open={open}
                    />
                    <TaskPanel
                        key={`${JSON.stringify(view.container.target)}:${draftEpoch}`}
                        view={view}
                        mutation={mutation}
                        open={open}
                    />
                </div>
                <Dialog
                    open={dialog !== null}
                    onOpenChange={(value) => {
                        if (!value && !mutation.locked) {
                            close();
                        }
                    }}
                >
                    <DialogContent
                        showCloseButton={!mutation.locked}
                        className="max-h-[90dvh] min-w-0 overflow-y-auto"
                    >
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
                                    graphVersion={dialog.graphVersion}
                                    list={null}
                                    href={view.create_list_href}
                                    mutation={mutation}
                                    saved={close}
                                />
                            )}
                        {dialog?.kind === 'edit-list' &&
                            dialog.list.update_href && (
                                <ListForm
                                    graphVersion={dialog.graphVersion}
                                    list={dialog.list}
                                    href={dialog.list.update_href}
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
                                    <PlanningMutationError
                                        mutation={mutation}
                                    />
                                )}
                                <Button
                                    type="button"
                                    disabled={mutation.locked}
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
                                                        dialog.graphVersion,
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
                                mutation={mutation}
                                saved={close}
                            />
                        )}
                        {mutation.error && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={mutation.locked}
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
