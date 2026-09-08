import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatScheduleAmount } from '@/lib/expense-schedule-amount';
import { SHELL_CONTAINER } from '@/lib/layout';

type Option = { value: string; label: string };
type Schedule = {
    id: string;
    description: string;
    amount: string;
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
    company: { name: string };
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
}: {
    page: Props;
    schedule?: Schedule;
    onDone: () => void;
}) {
    const form = useForm({
        description: schedule?.description ?? '',
        amount: schedule?.amount ?? '',
        currency: schedule?.currency ?? page.currency,
        project_id: schedule?.project_id ?? '',
        active: schedule?.active ?? true,
        starts_on: page.today,
        cadence: 'monthly',
    });
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
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) =>
                    schedule
                        ? {
                              description: data.description,
                              amount: data.amount,
                              currency: data.currency,
                              project_id: data.project_id || null,
                              active: data.active,
                          }
                        : {
                              description: data.description,
                              amount: data.amount,
                              currency: data.currency,
                              project_id: data.project_id || null,
                              starts_on: data.starts_on,
                              cadence: data.cadence,
                          },
                );
                const options = { preserveScroll: true, onSuccess: onDone };

                if (schedule) {
                    form.patch(schedule.update_url, options);
                } else {
                    form.post(page.urls.store, options);
                }
            }}
        >
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
                                form.setData('starts_on', event.target.value)
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
                Changes apply only to occurrences generated afterward, including
                catch-up drafts. The original calendar and existing expenses
                stay fixed.
            </p>
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
                <Button type="submit" disabled={form.processing}>
                    Save schedule
                </Button>
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function ExpenseSchedules(page: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

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
                    <Button onClick={() => setEditing('new')}>
                        New schedule
                    </Button>
                </div>
                {failure && (
                    <p role="alert" className="wrap-anywhere text-destructive">
                        {failure}
                    </p>
                )}
                {editing === 'new' && (
                    <ScheduleForm page={page} onDone={() => setEditing(null)} />
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
                                    schedule.amount,
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
                                    onClick={() => setEditing(schedule.id)}
                                >
                                    Edit schedule
                                </Button>
                                {schedule.generate_url && (
                                    <Button
                                        onClick={() => {
                                            setFailure(null);

                                            if (!schedule.generate_url) {
                                                return;
                                            }

                                            router.post(
                                                schedule.generate_url,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onError: (errors) =>
                                                        setFailure(
                                                            Object.values(
                                                                errors,
                                                            )[0] ??
                                                                'Generation failed.',
                                                        ),
                                                },
                                            );
                                        }}
                                    >
                                        Generate due drafts
                                    </Button>
                                )}
                            </div>
                            {editing === schedule.id && (
                                <ScheduleForm
                                    key={schedule.id}
                                    page={page}
                                    schedule={schedule}
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
                        <a href={page.pagination.previous}>
                            Previous schedules
                        </a>
                    )}
                    {page.pagination.next && (
                        <a href={page.pagination.next}>More schedules</a>
                    )}
                </nav>
            </main>
        </WorkspaceShell>
    );
}
