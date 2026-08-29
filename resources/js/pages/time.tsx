import { Head, router } from '@inertiajs/react';
import { CheckIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
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
import { formatDate, formatDecimalHours, formatHours } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    Capacity,
    Month,
    TimeEntry,
    TimeSheetProps,
} from '@/types/time-sheet';

/** What the row's badge should say, and where it links. */
function stateOf(entry: TimeEntry): {
    label: string;
    variant: 'default' | 'secondary' | 'outline' | 'destructive';
    className?: string;
    href?: string;
} {
    if (entry.invoice !== null) {
        const sent = entry.invoice.status !== 'draft';

        return {
            label: sent ? 'Invoiced' : 'Upcoming',
            variant: 'secondary',
            className: sent
                ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                : 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
        };
    }

    if (entry.status === 'approved') {
        return { label: 'Approved', variant: 'secondary' };
    }

    return { label: 'Draft', variant: 'outline' };
}

function CapacityStrip({ capacity }: { capacity: Capacity[] }) {
    if (capacity.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap gap-3">
                {capacity.map((row, index) => {
                    const over = row.over_hours > 0;
                    // A cadence anchored mid-month puts two cycles in one
                    // month, so the agreement name does not identify a row -
                    // neither as a key nor to the reader looking at two
                    // strips under the same heading.
                    const shareTheName =
                        capacity.filter(
                            (other) => other.agreement === row.agreement,
                        ).length > 1;
                    const used = row.worked_hours;
                    const available = row.available_hours;
                    const fraction =
                        available > 0
                            ? Math.min(1, used / available)
                            : used > 0
                              ? 1
                              : 0;

                    return (
                        <div
                            key={`${row.agreement}-${row.cycle_start}-${index}`}
                            className="min-w-56 flex-1 rounded-lg border border-border bg-muted/40 p-3"
                        >
                            <p className="truncate text-xs font-medium text-muted-foreground">
                                {row.agreement}
                                {shareTheName && row.cycle_start !== '' && (
                                    <span className="ml-1 font-normal">
                                        · cycle from{' '}
                                        {formatDate(row.cycle_start)}
                                    </span>
                                )}
                            </p>
                            <p className="mt-1 font-medium tabular-nums">
                                <span
                                    className={
                                        over ? 'text-destructive' : undefined
                                    }
                                >
                                    {formatDecimalHours(used)}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    / {formatDecimalHours(available)} h
                                </span>
                            </p>
                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-border">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        over
                                            ? 'bg-destructive'
                                            : 'bg-emerald-500',
                                    )}
                                    style={{ width: `${fraction * 100}%` }}
                                />
                            </div>
                            <p className="mt-1.5 text-xs tabular-nums">
                                {over ? (
                                    <span className="text-destructive">
                                        {formatDecimalHours(row.over_hours)} h
                                        over
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        {formatDecimalHours(row.unused_hours)} h
                                        left
                                    </span>
                                )}
                                {row.carried_deficit_hours > row.over_hours && (
                                    <span className="text-destructive">
                                        {' · '}
                                        {formatDecimalHours(
                                            row.carried_deficit_hours,
                                        )}{' '}
                                        h carried deficit
                                    </span>
                                )}
                                {row.remaining_rollover > 0 && (
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {formatDecimalHours(
                                            row.remaining_rollover,
                                        )}{' '}
                                        h rollover
                                    </span>
                                )}
                            </p>
                            {row.pending_minutes > 0 && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    <span className="font-medium text-foreground tabular-nums">
                                        {formatHours(row.pending_minutes)}
                                    </span>{' '}
                                    awaiting approval
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function TimeSheet({
    workspace,
    filters,
    companies,
    months,
}: TimeSheetProps) {
    const [dialogEntry, setDialogEntry] = useState<TimeEntry | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState<TimeEntry | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    // Approval can fail for a reason the row cannot show - most often a
    // billable entry with no agreement rate to stamp. Without this the row
    // simply stays a draft and the click looks like it did nothing.
    const [notice, setNotice] = useState<string | null>(null);
    const [approving, setApproving] = useState(false);

    const reportFailure = (errors: Record<string, string>) => {
        setNotice(
            Object.values(errors)[0] ?? 'That action could not be completed.',
        );
    };

    const company = useMemo(
        () =>
            companies.find(
                (candidate) => candidate.id === filters.company_id,
            ) ?? companies[0],
        [companies, filters.company_id],
    );

    const approvable = useMemo(
        () =>
            months
                .flatMap((month) => month.entries)
                .filter((entry) => entry.can_approve),
        [months],
    );

    const selectedEntries = approvable.filter((entry) =>
        selected.includes(entry.id),
    );

    const openDialog = (entry: TimeEntry | null) => {
        setDialogEntry(entry);
        setDialogOpen(true);
    };

    // Approval carries the version each row was rendered with, so a second
    // click sends versions the first click has already spent: the first
    // request approves, the second comes back 409 and the operator is told
    // their successful approval failed.
    const approve = (entries: TimeEntry[]) => {
        if (approving) {
            return;
        }

        setApproving(true);
        router.post(
            `/workspaces/${workspace.id}/time-entries/approve`,
            {
                entries: entries.map((entry) => ({
                    id: entry.id,
                    expected_version: entry.version,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setNotice(null);
                },
                onError: reportFailure,
                onFinish: () => setApproving(false),
            },
        );
    };

    const remove = (entry: TimeEntry) => {
        router.delete(`/workspaces/${workspace.id}/time-entries/${entry.id}`, {
            data: { expected_version: entry.version },
            preserveScroll: true,
            onSuccess: () => setNotice(null),
            onError: reportFailure,
            onFinish: () => setPendingDelete(null),
        });
    };

    return (
        <>
            <Head title="Time" />

            <div className="min-h-screen bg-background text-foreground">
                <div className="mx-auto max-w-6xl px-6 py-10">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <a
                                href="/app"
                                className="text-sm text-muted-foreground hover:text-foreground"
                            >
                                ← {workspace.name}
                            </a>
                            <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                                Time
                            </h1>
                        </div>

                        <div className="flex items-center gap-2">
                            {companies.length > 1 && (
                                <Select
                                    value={company?.id ?? ''}
                                    onValueChange={(value: string | null) => {
                                        if (value === null) {
                                            return;
                                        }

                                        router.get(
                                            `/workspaces/${workspace.id}/time`,
                                            { company: value },
                                            { preserveState: false },
                                        );
                                    }}
                                >
                                    <SelectTrigger className="min-w-48">
                                        <SelectValue placeholder="Choose a client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {companies.map((candidate) => (
                                            <SelectItem
                                                key={candidate.id}
                                                value={candidate.id}
                                            >
                                                {candidate.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}

                            {company?.projects.some(
                                (project) => project.can_log_time,
                            ) && (
                                <Button onClick={() => openDialog(null)}>
                                    <PlusIcon />
                                    Log time
                                </Button>
                            )}
                        </div>
                    </header>

                    {notice !== null && (
                        <div
                            role="alert"
                            className="mt-6 flex items-start justify-between gap-4 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        >
                            <p>{notice}</p>
                            <Button
                                variant="ghost"
                                size="xs"
                                onClick={() => setNotice(null)}
                            >
                                Dismiss
                            </Button>
                        </div>
                    )}

                    {selectedEntries.length > 0 && (
                        <div className="mt-6 flex items-center justify-between rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <p className="text-sm">
                                {selectedEntries.length} selected ·{' '}
                                <span className="tabular-nums">
                                    {formatHours(
                                        selectedEntries.reduce(
                                            (total, entry) =>
                                                total + entry.minutes,
                                            0,
                                        ),
                                    )}
                                </span>
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSelected([])}
                                >
                                    Clear
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={approving}
                                    onClick={() => approve(selectedEntries)}
                                >
                                    <CheckIcon />
                                    Approve
                                </Button>
                            </div>
                        </div>
                    )}

                    {company === undefined && (
                        <p className="mt-10 text-muted-foreground">
                            No clients yet. Add one before logging time against
                            it.
                        </p>
                    )}

                    {company !== undefined && months.length === 0 && (
                        <p className="mt-10 text-muted-foreground">
                            No time logged for {company.name} in the last twelve
                            months.
                        </p>
                    )}

                    <div className="mt-8 grid gap-8">
                        {months.map((month) => (
                            <MonthCard
                                key={month.key}
                                month={month}
                                workspaceId={workspace.id}
                                selected={selected}
                                onToggle={(id) =>
                                    setSelected((current) =>
                                        current.includes(id)
                                            ? current.filter(
                                                  (value) => value !== id,
                                              )
                                            : [...current, id],
                                    )
                                }
                                onEdit={openDialog}
                                onDelete={setPendingDelete}
                                onApprove={(entry) => approve([entry])}
                                approving={approving}
                            />
                        ))}
                    </div>
                </div>
            </div>

            {company !== undefined && dialogOpen && (
                <TimeEntryDialog
                    key={dialogEntry?.id ?? 'new'}
                    workspaceId={workspace.id}
                    company={company}
                    entry={dialogEntry}
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                />
            )}

            <AlertDialog
                open={pendingDelete !== null}
                onOpenChange={(open: boolean) => {
                    if (!open) {
                        setPendingDelete(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this entry?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete === null
                                ? ''
                                : `${formatHours(pendingDelete.minutes)} on ${formatDate(
                                      pendingDelete.worked_on,
                                  )} — ${pendingDelete.description}`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            render={<Button variant="outline">Cancel</Button>}
                        />
                        <AlertDialogAction
                            render={
                                <Button
                                    variant="destructive"
                                    onClick={() =>
                                        pendingDelete !== null &&
                                        remove(pendingDelete)
                                    }
                                >
                                    Delete
                                </Button>
                            }
                        />
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function MonthCard({
    month,
    workspaceId,
    selected,
    onToggle,
    onEdit,
    onDelete,
    onApprove,
    approving,
}: {
    month: Month;
    workspaceId: string;
    selected: string[];
    onToggle: (id: string) => void;
    onEdit: (entry: TimeEntry) => void;
    onDelete: (entry: TimeEntry) => void;
    onApprove: (entry: TimeEntry) => void;
    approving: boolean;
}) {
    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-lg font-semibold">{month.label}</h2>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {formatHours(month.total_minutes)} logged ·{' '}
                        {formatHours(month.billable_minutes)} billable
                        {month.deferred_minutes > 0 && (
                            <>
                                {' '}
                                · {formatHours(month.deferred_minutes)} deferred
                            </>
                        )}
                    </p>
                </div>
                <CapacityStrip capacity={month.capacity} />
            </CardHeader>

            <CardContent>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead className="w-28">Date</TableHead>
                                <TableHead>Work</TableHead>
                                <TableHead className="w-20 text-right">
                                    Hours
                                </TableHead>
                                <TableHead className="w-28">State</TableHead>
                                <TableHead className="w-32 text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {month.entries.map((entry) => {
                                const state = stateOf(entry);

                                return (
                                    <TableRow key={entry.id}>
                                        <TableCell>
                                            {entry.can_approve && (
                                                <input
                                                    type="checkbox"
                                                    className="size-4 accent-primary"
                                                    checked={selected.includes(
                                                        entry.id,
                                                    )}
                                                    onChange={() =>
                                                        onToggle(entry.id)
                                                    }
                                                    aria-label={`Select ${entry.description}`}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground tabular-nums">
                                            {formatDate(entry.worked_on)}
                                        </TableCell>
                                        <TableCell>
                                            <p className="font-medium">
                                                {entry.description}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {entry.project.name}
                                                {entry.task !== null &&
                                                    ` · ${entry.task.title}`}
                                                {entry.worker !== null &&
                                                    ` · ${entry.worker}`}
                                            </p>
                                            <div className="mt-1 flex flex-wrap gap-1">
                                                {!entry.is_billable && (
                                                    <Badge variant="outline">
                                                        Non-billable
                                                    </Badge>
                                                )}
                                                {entry.is_deferred && (
                                                    <Badge variant="outline">
                                                        Deferred
                                                    </Badge>
                                                )}
                                                {entry.is_visible_to_client && (
                                                    <Badge variant="outline">
                                                        Client-visible
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatHours(entry.minutes)}
                                        </TableCell>
                                        <TableCell>
                                            {entry.invoice !== null ? (
                                                <a
                                                    href={`/workspaces/${workspaceId}/invoices/${entry.invoice.id}`}
                                                    className="inline-block"
                                                >
                                                    <Badge
                                                        variant={state.variant}
                                                        className={
                                                            state.className
                                                        }
                                                    >
                                                        {state.label}
                                                        {entry.invoice
                                                            .number !== null &&
                                                            ` · ${entry.invoice.number}`}
                                                    </Badge>
                                                </a>
                                            ) : (
                                                <Badge
                                                    variant={state.variant}
                                                    className={state.className}
                                                >
                                                    {state.label}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                {entry.can_approve && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-xs"
                                                        aria-label="Approve"
                                                        disabled={approving}
                                                        onClick={() =>
                                                            onApprove(entry)
                                                        }
                                                    >
                                                        <CheckIcon />
                                                    </Button>
                                                )}
                                                {entry.can_edit && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-xs"
                                                            aria-label="Edit"
                                                            onClick={() =>
                                                                onEdit(entry)
                                                            }
                                                        >
                                                            <PencilIcon />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-xs"
                                                            aria-label="Delete"
                                                            onClick={() =>
                                                                onDelete(entry)
                                                            }
                                                        >
                                                            <Trash2Icon />
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}
