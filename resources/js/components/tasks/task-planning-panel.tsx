import { Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    PlanningPicker,
    usePlanningMutation,
} from '@/components/tasks/planning-controls';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type {
    PlanningContact,
    PlanningDependencyDetails,
    PlanningLabel,
    PlanningTaskReference,
    TaskPlanningNativeDetails,
} from '@/types/task-planning';

const refreshPlanning = () =>
    refreshPageProps({ only: ['planning_details'], requireSuccess: true });
const labelName = (row: PlanningLabel) =>
    `${row.name}${row.is_archived ? ' (archived)' : ''}`;
const contactName = (row: PlanningContact) =>
    `${row.display_name}${row.is_archived ? ' (archived)' : ''}`;
const referenceName = (row: PlanningTaskReference) =>
    `${row.title} · ${statusLabel(row.status)}`;

type SelectionDraft<T> = { selected: T[]; version: string };

function SelectionForm<T extends { id: string }>({
    name,
    initial,
    version,
    options,
    optionsHref,
    replaceHref,
    field,
    limit,
    display,
    refresh,
    children,
}: {
    name: string;
    initial: T[];
    version: string;
    options: Parameters<typeof PlanningPicker<T>>[0]['initial'];
    optionsHref: string;
    replaceHref: string;
    field: string;
    limit: number;
    display: (row: T) => string;
    refresh: () => Promise<void>;
    children?: React.ReactNode;
}) {
    const [draft, setDraft] = useState<SelectionDraft<T> | null>(null);
    const mutation = usePlanningMutation(refresh);
    const selected = draft?.selected ?? initial;

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                void mutation.run(
                    'PUT',
                    replaceHref,
                    {
                        expected_version: draft?.version ?? version,
                        [field]: selected.map((row) => row.id),
                    },
                    () => setDraft(null),
                );
            }}
        >
            {children}
            <PlanningPicker
                key={optionsHref}
                name={name}
                initial={options}
                optionsHref={optionsHref}
                selected={selected}
                limit={limit}
                display={display}
                disabled={mutation.pending}
                onChange={(rows) =>
                    setDraft((current) => ({
                        selected: rows,
                        version: current?.version ?? version,
                    }))
                }
            />
            {mutation.error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-destructive"
                >
                    {mutation.error}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    disabled={mutation.pending || draft === null}
                >
                    Save {name.toLowerCase()}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={mutation.pending}
                    onClick={() =>
                        void mutation.reload(() => {
                            setDraft(null);
                            mutation.clearError();
                        })
                    }
                >
                    Reload {name.toLowerCase()}
                </Button>
            </div>
        </form>
    );
}

function References({
    title,
    rows,
}: {
    title: string;
    rows: PlanningTaskReference[];
}) {
    return (
        <div className="grid min-w-0 grid-cols-1 gap-2">
            <h4 className="text-sm font-medium">{title}</h4>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">None.</p>
            ) : (
                <ul className="grid min-w-0 grid-cols-1 gap-2">
                    {rows.map((row) => (
                        <li
                            key={row.id}
                            className="min-w-0 text-sm wrap-anywhere"
                        >
                            <Link href={row.web_url} className="underline">
                                {row.title}
                            </Link>
                            <span className="ml-2 text-muted-foreground">
                                {statusLabel(row.status)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Dependencies({
    details,
    refresh,
}: {
    details: TaskPlanningNativeDetails;
    refresh: () => Promise<void>;
}) {
    const section = details.dependencies;
    const [draft, setDraft] = useState<{
        selected: PlanningTaskReference[];
        version: string;
        graphVersion: string;
    } | null>(null);
    const [incoming, setIncoming] = useState<{
        base: PlanningDependencyDetails;
        rows: PlanningTaskReference[];
        next: string | null;
    } | null>(null);
    const [morePending, setMorePending] = useState(false);
    const moreFlight = useRef(false);
    const [moreError, setMoreError] = useState<string | null>(null);
    const mutation = usePlanningMutation(refresh);
    const data = section.data;

    if (!data) {
        return (
            <p className="text-sm text-muted-foreground">
                {section.unsupported_notice}
            </p>
        );
    }

    const currentIncoming = incoming?.base === data ? incoming : null;
    const next = currentIncoming ? currentIncoming.next : section.next_href;
    const selected = draft?.selected ?? data.prerequisites;
    async function loadMore() {
        if (!next || moreFlight.current || !data) {
            return;
        }

        moreFlight.current = true;
        setMorePending(true);
        setMoreError(null);

        try {
            const response = await fetch(next, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('continuation');
            }

            const page: {
                data: PlanningDependencyDetails;
                next_href: string | null;
            } = await response.json();

            if (
                page.data.version !== data.version ||
                page.data.graph_version !== data.graph_version
            ) {
                throw new Error('changed');
            }

            setIncoming({
                base: data,
                rows: [
                    ...new Map(
                        [
                            ...(currentIncoming?.rows ?? data.dependents),
                            ...page.data.dependents,
                        ].map((row) => [row.id, row]),
                    ).values(),
                ],
                next: page.next_href,
            });
        } catch {
            setMoreError(
                'Dependent tasks could not be continued. Reload planning to get their current state.',
            );
        } finally {
            moreFlight.current = false;
            setMorePending(false);
        }
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-4">
            <References title="Prerequisite tasks" rows={data.prerequisites} />
            <References
                title="Tasks that depend on this task"
                rows={currentIncoming?.rows ?? data.dependents}
            />
            {next && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={morePending}
                    onClick={() => void loadMore()}
                >
                    Load more dependent tasks
                </Button>
            )}
            {moreError && (
                <p role="alert" className="text-sm text-destructive">
                    {moreError}
                </p>
            )}
            {section.replace_href &&
                section.options &&
                section.options_href && (
                    <form
                        className="grid min-w-0 grid-cols-1 gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void mutation.run(
                                'PUT',
                                section.replace_href!,
                                {
                                    expected_version:
                                        draft?.version ?? data.version,
                                    expected_graph_version:
                                        draft?.graphVersion ??
                                        data.graph_version,
                                    prerequisite_ids: selected.map(
                                        (row) => row.id,
                                    ),
                                },
                                () => setDraft(null),
                            );
                        }}
                    >
                        <PlanningPicker
                            key={section.options_href}
                            name="Prerequisites"
                            initial={section.options}
                            optionsHref={section.options_href}
                            selected={selected}
                            limit={25}
                            display={referenceName}
                            disabled={mutation.pending}
                            onChange={(rows) =>
                                setDraft((current) => ({
                                    selected: rows,
                                    version: current?.version ?? data.version,
                                    graphVersion:
                                        current?.graphVersion ??
                                        data.graph_version,
                                }))
                            }
                        />
                        {mutation.error && (
                            <p
                                role="alert"
                                className="text-sm wrap-anywhere text-destructive"
                            >
                                {mutation.error}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={mutation.pending || draft === null}
                            >
                                Save prerequisites
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={mutation.pending}
                                onClick={() =>
                                    void mutation.reload(() => {
                                        setDraft(null);
                                        setIncoming(null);
                                        mutation.clearError();
                                    })
                                }
                            >
                                Reload prerequisites
                            </Button>
                        </div>
                    </form>
                )}
            <p className="text-xs text-muted-foreground">
                Prerequisites are advisory. You can complete this task at any
                time; other tasks do not change automatically.
            </p>
        </div>
    );
}

/** Independent forms retain their captured tokens and drafts across other planning saves. */
export function TaskPlanningPanel({
    details,
    onRefresh = refreshPlanning,
}: {
    details: TaskPlanningNativeDetails | null;
    onRefresh?: () => Promise<void>;
}) {
    if (!details) {
        return null;
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-4" key={details.task_id}>
            <Card className="min-w-0">
                <CardHeader>
                    <CardTitle>Task labels</CardTitle>
                </CardHeader>
                <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                    {details.label_catalog_href && (
                        <Link
                            href={details.label_catalog_href}
                            className="text-sm underline"
                        >
                            Manage workspace labels
                        </Link>
                    )}
                    {details.labels.options &&
                    details.labels.options_href &&
                    details.labels.replace_href ? (
                        <SelectionForm
                            name="Labels"
                            initial={details.labels.selected}
                            version={details.version}
                            options={details.labels.options}
                            optionsHref={details.labels.options_href}
                            replaceHref={details.labels.replace_href}
                            field="label_ids"
                            limit={20}
                            display={labelName}
                            refresh={onRefresh}
                        />
                    ) : (
                        <ul className="grid min-w-0 grid-cols-1 gap-2">
                            {details.labels.selected.map((row) => (
                                <li
                                    key={row.id}
                                    className="text-sm wrap-anywhere"
                                >
                                    {labelName(row)}
                                </li>
                            ))}
                            {details.labels.selected.length === 0 && (
                                <li className="text-sm text-muted-foreground">
                                    No labels.
                                </li>
                            )}
                        </ul>
                    )}
                </CardContent>
            </Card>
            <Card className="min-w-0">
                <CardHeader>
                    <CardTitle>Task dependencies</CardTitle>
                </CardHeader>
                <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                    {details.dependency_summary.blocked && (
                        <p role="status" className="text-sm">
                            {
                                details.dependency_summary
                                    .incomplete_prerequisite_count
                            }{' '}
                            incomplete prerequisite tasks.
                        </p>
                    )}
                    <Dependencies details={details} refresh={onRefresh} />
                </CardContent>
            </Card>
            {details.contacts && (
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Client contact responsibility</CardTitle>
                    </CardHeader>
                    <CardContent className="min-w-0">
                        <SelectionForm
                            name="Contacts"
                            initial={details.contacts.selected}
                            version={details.contacts.version}
                            options={details.contacts.options}
                            optionsHref={details.contacts.options_href}
                            replaceHref={details.contacts.replace_href}
                            field="contact_ids"
                            limit={10}
                            display={contactName}
                            refresh={onRefresh}
                        >
                            <p className="text-sm text-muted-foreground">
                                Business responsibility grants no account access
                                and does not assign a staff account.
                            </p>
                        </SelectionForm>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
