import { Head, Link, router } from '@inertiajs/react';
import { useId, useState } from 'react';
import {
    PlanningMutationError,
    usePlanningMutation,
} from '@/components/tasks/planning-controls';
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
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type {
    PlanningCatalogLabel,
    TaskPlanningCatalogProps,
} from '@/types/task-planning';

const statuses = [
    { value: 'active', label: 'Active' },
    { value: 'archived', label: 'Archived' },
    { value: 'all', label: 'All labels' },
];
const refreshCatalog = () =>
    refreshPageProps({ only: ['catalog'], requireSuccess: true });

function LabelNameForm({
    label,
    createHref,
}: {
    label?: PlanningCatalogLabel;
    createHref?: string;
}) {
    const id = useId();
    const [draft, setDraft] = useState<{
        name: string;
        version: string;
    } | null>(null);
    const mutation = usePlanningMutation(refreshCatalog);
    const name = draft?.name ?? label?.name ?? '';
    const length = Array.from(name).length;

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                const href = label?.update_href ?? createHref;

                if (href) {
                    void mutation.run(
                        label ? 'PATCH' : 'POST',
                        href,
                        {
                            name,
                            ...(label
                                ? {
                                      expected_version:
                                          draft?.version ?? label.version,
                                  }
                                : {}),
                        },
                        () => setDraft(null),
                    );
                }
            }}
        >
            <Label htmlFor={id}>
                {label ? 'Label name' : 'New label name'}
            </Label>
            <Input
                id={id}
                value={name}
                disabled={mutation.locked}
                onChange={(event) =>
                    setDraft((current) => ({
                        name: event.target.value,
                        version: current?.version ?? label?.version ?? '',
                    }))
                }
            />
            <p className="text-xs text-muted-foreground">
                {length} of 80 characters. Labels use one line.
            </p>
            {mutation.error && <PlanningMutationError mutation={mutation} />}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    disabled={
                        mutation.locked ||
                        length === 0 ||
                        length > 80 ||
                        (label !== undefined && draft === null)
                    }
                >
                    {label ? 'Save name' : 'Create label'}
                </Button>
                {label && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={mutation.locked}
                        onClick={() =>
                            void mutation.reload(() => {
                                setDraft(null);
                                mutation.clearError();
                            })
                        }
                    >
                        Reload label
                    </Button>
                )}
            </div>
        </form>
    );
}

function LabelRow({ label }: { label: PlanningCatalogLabel }) {
    const [confirming, setConfirming] = useState<{ version: string } | null>(
        null,
    );
    const mutation = usePlanningMutation(refreshCatalog);
    const href = label.is_archived ? label.restore_href : label.archive_href;

    return (
        <Card className="min-w-0">
            <CardHeader>
                <CardTitle className="wrap-anywhere">{label.name}</CardTitle>
                {label.is_archived && (
                    <p className="text-sm text-muted-foreground">Archived</p>
                )}
            </CardHeader>
            <CardContent className="grid min-w-0 grid-cols-1 gap-4">
                {label.update_href && <LabelNameForm label={label} />}
                {href && (
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        {confirming ? (
                            <>
                                <p className="text-sm">
                                    {label.is_archived
                                        ? 'Restore this label for new assignments?'
                                        : 'Archive this label? Existing task assignments are preserved.'}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        disabled={mutation.locked}
                                        onClick={() =>
                                            void mutation.run(
                                                label.is_archived
                                                    ? 'POST'
                                                    : 'DELETE',
                                                href,
                                                {
                                                    expected_version:
                                                        confirming.version,
                                                    ...(!label.is_archived
                                                        ? { confirm: true }
                                                        : {}),
                                                },
                                                () => setConfirming(null),
                                            )
                                        }
                                    >
                                        {label.is_archived
                                            ? 'Confirm restore'
                                            : 'Confirm archive'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={mutation.locked}
                                        onClick={() => {
                                            setConfirming(null);
                                            mutation.clearError();
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    setConfirming({ version: label.version })
                                }
                            >
                                {label.is_archived
                                    ? 'Restore label'
                                    : 'Archive label'}
                            </Button>
                        )}
                        {mutation.error && (
                            <>
                                <PlanningMutationError mutation={mutation} />
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={mutation.locked}
                                    onClick={() =>
                                        void mutation.reload(() => {
                                            setConfirming(null);
                                            mutation.clearError();
                                        })
                                    }
                                >
                                    Reload current label
                                </Button>
                            </>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function TaskLabelsPage({
    workspace,
    catalog,
}: TaskPlanningCatalogProps) {
    const id = useId();
    const [search, setSearch] = useState(catalog.filters.q);
    const [status, setStatus] = useState(catalog.filters.status);

    return (
        <WorkspaceShell activeModule="tasks">
            <Head title={`Task labels · ${workspace.name}`} />
            <main className={`${SHELL_CONTAINER} grid grid-cols-1 gap-6 py-6`}>
                <div className="grid min-w-0 grid-cols-1 gap-2">
                    <h1 className="text-2xl font-semibold">Task labels</h1>
                    <p className="text-sm text-muted-foreground">
                        Workspace labels organize tasks. Archiving preserves
                        existing assignments.
                    </p>
                </div>
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Create a label</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LabelNameForm createHref={catalog.create_href} />
                    </CardContent>
                </Card>
                <form
                    className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            catalog.search_href,
                            { q: search, status },
                            { preserveState: true },
                        );
                    }}
                >
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor={id}>Find labels</Label>
                        <Input
                            id={id}
                            type="search"
                            value={search}
                            maxLength={100}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <Select
                        value={status}
                        items={statuses}
                        onValueChange={(value) => {
                            if (
                                value === 'active' ||
                                value === 'archived' ||
                                value === 'all'
                            ) {
                                setStatus(value);
                            }
                        }}
                    >
                        <SelectTrigger aria-label="Label status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button type="submit">Apply filters</Button>
                </form>
                <div className="grid min-w-0 grid-cols-1 gap-4">
                    {catalog.labels.map((label) => (
                        <LabelRow key={label.id} label={label} />
                    ))}
                    {catalog.labels.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No labels match these filters.
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-3">
                    {catalog.previous_href && (
                        <Link
                            href={catalog.previous_href}
                            className="underline"
                        >
                            Previous labels
                        </Link>
                    )}
                    {catalog.next_href && (
                        <Link href={catalog.next_href} className="underline">
                            Next labels
                        </Link>
                    )}
                </div>
            </main>
        </WorkspaceShell>
    );
}
