import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useExpenseMutation } from '@/hooks/use-expense-mutation';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatScheduleAmount } from '@/lib/expense-schedule-amount';
import { SHELL_CONTAINER } from '@/lib/layout';

type Option = { value: string; label: string };
type Schedule = {
    id: string;
    description: string;
    amount: number;
    amount_exact: string;
    version: string;
    currency: string;
    project_id: string;
    starts_on: string;
    cadence: string;
    active: boolean;
    status_label: string;
    next_on: string;
    pending: boolean;
    update_url: string;
    generate_url: string | null;
};
type Props = {
    pagination: { next: string | null; previous: string | null };
    company: { id: string; name: string; version: string };
    today: string;
    currency: string;
    urls: { store: string };
    projects: Option[];
    cadences: Option[];
    schedules: Schedule[];
};

function ScheduleForm({
    page,
    schedule,
    onDone,
    onLockedChange,
}: {
    page: Props;
    schedule?: Schedule;
    onDone: () => void;
    onLockedChange: (locked: boolean) => void;
}) {
    const form = useForm({
        description: schedule?.description ?? '',
        amount: schedule?.amount_exact ?? '',
        currency: schedule?.currency ?? page.currency,
        project_id: schedule?.project_id ?? '',
        active: schedule?.active ?? true,
        starts_on: page.today,
        cadence: 'monthly',
    });
    const [baseline] = useState(() => ({
        method: schedule ? ('PATCH' as const) : ('POST' as const),
        url: schedule?.update_url ?? page.urls.store,
        version: schedule?.version ?? page.company.version,
        companyId: page.company.id,
    }));
    const mutation = useExpenseMutation(
        ['schedules', 'company', 'pagination'],
        onLockedChange,
    );
    const field = (
        name: 'description' | 'amount' | 'currency',
        label: string,
    ) => (
        <div className="min-w-0">
            <Label htmlFor={`${schedule?.id ?? 'new'}-${name}`}>{label}</Label>
            <Input
                id={`${schedule?.id ?? 'new'}-${name}`}
                type="text"
                inputMode={name === 'amount' ? 'numeric' : undefined}
                pattern={name === 'amount' ? '[0-9]+' : undefined}
                value={form.data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                required
            />
        </div>
    );

    return (
        <form
            className="grid min-w-0 grid-cols-1 gap-4 rounded border p-4"
            onSubmit={async (event) => {
                event.preventDefault();

                if (mutation.busy) {
                    return;
                }

                const data = form.data;
                const facts = {
                    description: data.description,
                    amount: data.amount,
                    currency: data.currency,
                    project_id: data.project_id || null,
                };
                await mutation.submit(
                    baseline.method,
                    baseline.url,
                    baseline.method === 'PATCH'
                        ? {
                              ...facts,
                              active: data.active,
                              expected_version: baseline.version,
                          }
                        : {
                              ...facts,
                              company_id: baseline.companyId,
                              expected_version: baseline.version,
                              starts_on: data.starts_on,
                              cadence: data.cadence,
                          },
                    onDone,
                );
            }}
        >
            <fieldset disabled={mutation.locked} className="contents">
                {field('description', 'Description')}
                <div className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                    {field('amount', 'Amount in minor units')}
                    {field('currency', 'Currency')}
                </div>
                <label className="grid min-w-0 grid-cols-1 gap-1">
                    Project
                    <select
                        className="w-full min-w-0 rounded border bg-background p-2"
                        value={form.data.project_id}
                        onChange={(event) =>
                            form.setData('project_id', event.target.value)
                        }
                    >
                        <option value="">Company expense</option>
                        {page.projects.map((project) => (
                            <option key={project.value} value={project.value}>
                                {project.label}
                            </option>
                        ))}
                    </select>
                </label>
                {schedule ? (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.active}
                            onChange={(event) =>
                                form.setData('active', event.target.checked)
                            }
                        />
                        Active
                    </label>
                ) : (
                    <div className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className="grid min-w-0 grid-cols-1 gap-1">
                            First occurrence
                            <Input
                                type="date"
                                required
                                value={form.data.starts_on}
                                onChange={(event) =>
                                    form.setData(
                                        'starts_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className="grid min-w-0 grid-cols-1 gap-1">
                            Repeats
                            <select
                                className="w-full min-w-0 rounded border bg-background p-2"
                                value={form.data.cadence}
                                onChange={(event) =>
                                    form.setData('cadence', event.target.value)
                                }
                            >
                                {page.cadences.map((cadence) => (
                                    <option
                                        key={cadence.value}
                                        value={cadence.value}
                                    >
                                        {cadence.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                )}
                <p className="text-sm text-muted-foreground">
                    Changes apply only to occurrences generated afterward,
                    including catch-up drafts. The original calendar and
                    existing expenses stay fixed.
                </p>
            </fieldset>
            {mutation.error && (
                <p role="alert" className="wrap-anywhere text-destructive">
                    {mutation.error}
                </p>
            )}
            {Object.entries(form.errors).map(([key, error]) => (
                <p
                    role="alert"
                    key={key}
                    className="wrap-anywhere text-destructive"
                >
                    {error}
                </p>
            ))}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={mutation.busy}>
                    {mutation.saved
                        ? 'Refresh saved result'
                        : mutation.locked
                          ? 'Check original request'
                          : 'Save schedule'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={mutation.locked}
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function ScheduleGeneration({
    schedule,
    pageLocked,
    onLockedChange,
}: {
    schedule: Schedule;
    pageLocked: boolean;
    onLockedChange: (locked: boolean) => void;
}) {
    const mutation = useExpenseMutation(
        ['schedules', 'company', 'pagination'],
        onLockedChange,
    );

    if (schedule.generate_url === null && !mutation.locked) {
        return null;
    }

    return (
        <div className="min-w-0 space-y-2">
            <Button
                disabled={mutation.busy || (pageLocked && !mutation.locked)}
                onClick={() => {
                    if (schedule.generate_url !== null || mutation.locked) {
                        void mutation.submit(
                            'POST',
                            schedule.generate_url ?? '',
                            {
                                expected_version: schedule.version,
                                confirm: true,
                            },
                        );
                    }
                }}
            >
                {mutation.saved
                    ? 'Refresh saved result'
                    : mutation.locked
                      ? 'Check original generation'
                      : 'Generate due drafts'}
            </Button>
            {mutation.error && (
                <p role="alert" className="wrap-anywhere text-destructive">
                    {mutation.error}
                </p>
            )}
        </div>
    );
}

export default function ExpenseSchedules(page: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const [formLocked, setFormLocked] = useState(false);
    const [generationLocks, setGenerationLocks] = useState<
        Record<string, boolean>
    >({});
    const locked = formLocked || Object.values(generationLocks).some(Boolean);

    return (
        <WorkspaceShell activeModule="expenses">
            <Head title="Recurring expenses" />
            <main className={`${SHELL_CONTAINER} space-y-6 py-6`}>
                <h1 className="text-2xl font-semibold wrap-anywhere">
                    Recurring expenses for {page.company.name}
                </h1>
                <p>
                    Generation is on demand. Select Generate due drafts to
                    create up to 24 occurrences through today in the workspace
                    timezone. Every occurrence needs separate approval before
                    billing.
                </p>
                <p className="text-sm text-muted-foreground">
                    Short months use their last day; later months return to the
                    original day. Pausing retains the backlog for catch-up after
                    resuming.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Button disabled={locked} onClick={() => setEditing('new')}>
                        New schedule
                    </Button>
                </div>
                {editing === 'new' && (
                    <ScheduleForm
                        page={page}
                        onLockedChange={setFormLocked}
                        onDone={() => setEditing(null)}
                    />
                )}
                <div className="grid min-w-0 grid-cols-1 gap-4">
                    {page.schedules.map((schedule) => (
                        <section
                            key={schedule.id}
                            className="min-w-0 space-y-3 rounded border p-4"
                        >
                            <h2 className="font-semibold wrap-anywhere">
                                {schedule.description}
                            </h2>
                            <p>
                                {formatScheduleAmount(
                                    schedule.amount_exact,
                                    schedule.currency,
                                )}{' '}
                                ·{' '}
                                {
                                    page.cadences.find(
                                        (cadence) =>
                                            cadence.value === schedule.cadence,
                                    )?.label
                                }{' '}
                                · {schedule.status_label}
                            </p>
                            <p>Next occurrence: {schedule.next_on}</p>
                            {schedule.pending && (
                                <p>
                                    Due occurrences remain. Generate the next
                                    batch of drafts, then review and approve
                                    them in Expenses.
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={locked}
                                    onClick={() => setEditing(schedule.id)}
                                >
                                    Edit schedule
                                </Button>
                                <ScheduleGeneration
                                    schedule={schedule}
                                    pageLocked={locked}
                                    onLockedChange={(pending) =>
                                        setGenerationLocks((current) => ({
                                            ...current,
                                            [schedule.id]: pending,
                                        }))
                                    }
                                />
                            </div>
                            {editing === schedule.id && (
                                <ScheduleForm
                                    key={schedule.id}
                                    page={page}
                                    schedule={schedule}
                                    onLockedChange={setFormLocked}
                                    onDone={() => setEditing(null)}
                                />
                            )}
                        </section>
                    ))}
                </div>
                <nav
                    aria-label="Schedule pages"
                    className="flex flex-wrap gap-4"
                >
                    {page.pagination.previous && (
                        <a
                            aria-disabled={locked}
                            onClick={(event) => {
                                if (locked) {
                                    event.preventDefault();
                                }
                            }}
                            href={page.pagination.previous}
                        >
                            Previous schedules
                        </a>
                    )}
                    {page.pagination.next && (
                        <a
                            aria-disabled={locked}
                            onClick={(event) => {
                                if (locked) {
                                    event.preventDefault();
                                }
                            }}
                            href={page.pagination.next}
                        >
                            More schedules
                        </a>
                    )}
                </nav>
            </main>
        </WorkspaceShell>
    );
}
