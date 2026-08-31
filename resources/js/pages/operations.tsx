import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ActivityTimeline } from '@/components/activity-timeline';
import type { CompanyActivity } from '@/components/activity-timeline';
import { AppearanceSelector } from '@/components/appearance-selector';
import { todayIn } from '@/lib/time';

type TimeEntry = {
    id: string;
    worked_on: string;
    minutes: number;
    description: string;
    status: string;
    is_billable: boolean;
    is_deferred: boolean;
};

type Attachment = {
    id: string;
    name: string;
    media_type: string;
    bytes: number;
    download_url: string;
};

type Project = {
    id: string;
    name: string;
    time_entries: TimeEntry[];
};

type Proposal = {
    id: string;
    title: string;
    status: string;
    total_amount: number;
    currency: string;
    attachments: Attachment[];
};

type Agreement = {
    id: string;
    title: string;
    status: string;
    billing_cadence: string;
    attachments: Attachment[];
};

type BillingSchedule = {
    id: string;
    agreement_id: string;
    cadence: string;
    next_run_on: string;
    is_active: boolean;
};

type Invoice = {
    id: string;
    invoice_number: string;
    status: string;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    currency: string;
    due_date: string | null;
    attachments: Attachment[];
};

type Client = {
    id: string;
    name: string;
    projects: Project[];
    proposals: Proposal[];
    agreements: Agreement[];
    billing_schedules: BillingSchedule[];
    invoices: Invoice[];
    activities: CompanyActivity[];
};

type Workspace = {
    id: string;
    name: string;
    clients: Client[];
    /** The workspace's calendar; date defaults are read on it, not UTC's. */
    timezone: string;
};

const inputClass =
    'w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-cyan-600';
const buttonClass =
    'rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50';
const secondaryButtonClass =
    'rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-50';

function toMinorUnits(value: string): number | null {
    if (value.trim() === '') {
        return null;
    }

    const amount = Number(value);

    return Number.isFinite(amount) ? Math.round(amount * 100) : null;
}

function money(amount: number, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(amount / 100);
}

function AttachmentPanel({
    workspaceId,
    recordType,
    recordId,
    attachments,
}: {
    workspaceId: string;
    recordType: 'proposal' | 'agreement' | 'invoice';
    recordId: string;
    attachments: Attachment[];
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            `/workspaces/${workspaceId}/attachments/${recordType}/${recordId}`,
            { forceFormData: true, onSuccess: () => form.reset() },
        );
    };

    return (
        <div className="mt-3 border-t border-slate-200 pt-3">
            <div className="flex flex-wrap gap-2 text-xs text-slate-600">
                {attachments.map((attachment) => (
                    <a
                        key={attachment.id}
                        href={attachment.download_url}
                        className="font-medium text-cyan-700"
                    >
                        {attachment.name}
                    </a>
                ))}
            </div>
            <form onSubmit={submit} className="mt-2 flex flex-wrap gap-2">
                <input
                    type="file"
                    required
                    className="max-w-64 text-xs"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
                <button
                    className={secondaryButtonClass}
                    disabled={form.processing || form.data.file === null}
                >
                    Attach document
                </button>
            </form>
        </div>
    );
}

function TimeEntryForm({
    workspaceId,
    project,
    timezone,
}: {
    workspaceId: string;
    project: Project;
    timezone: string;
}) {
    const form = useForm({
        // Not `new Date().toISOString()`: that is UTC's day, and the write
        // validators bound the date by the workspace's own window - so for
        // the hours the two calendars disagree, the untouched default was
        // refused.
        worked_on: todayIn(timezone),
        minutes: '30',
        description: '',
        is_billable: true,
        is_deferred: false,
        billing_rate: '',
        currency: 'USD',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            minutes: Number(data.minutes),
            billing_rate_amount: toMinorUnits(data.billing_rate),
        }));
        form.post(
            `/workspaces/${workspaceId}/projects/${project.id}/time-entries`,
            { onSuccess: () => form.reset('description') },
        );
    };

    return (
        <form onSubmit={submit} className="mt-3 grid gap-2 md:grid-cols-6">
            <input
                className={inputClass}
                type="date"
                value={form.data.worked_on}
                onChange={(event) =>
                    form.setData('worked_on', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                type="number"
                min="1"
                value={form.data.minutes}
                onChange={(event) =>
                    form.setData('minutes', event.target.value)
                }
                aria-label="Minutes"
                required
            />
            <input
                className={`${inputClass} md:col-span-2`}
                placeholder="Work performed"
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                type="number"
                step="0.01"
                min="0"
                placeholder="Hourly rate"
                value={form.data.billing_rate}
                onChange={(event) =>
                    form.setData('billing_rate', event.target.value)
                }
            />
            <button className={buttonClass} disabled={form.processing}>
                Log time
            </button>
            <label className="flex items-center gap-2 text-sm text-slate-600">
                <input
                    type="checkbox"
                    checked={form.data.is_billable}
                    onChange={(event) =>
                        form.setData('is_billable', event.target.checked)
                    }
                />
                Billable
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-600">
                <input
                    type="checkbox"
                    checked={form.data.is_deferred}
                    onChange={(event) =>
                        form.setData('is_deferred', event.target.checked)
                    }
                />
                Deferred
            </label>
        </form>
    );
}

function ProposalForm({
    workspaceId,
    clientId,
}: {
    workspaceId: string;
    clientId: string;
}) {
    const form = useForm({
        title: '',
        summary: '',
        terms: '',
        valid_until: '',
        currency: 'USD',
        is_visible_to_client: false,
        item_description: '',
        item_quantity: '1',
        item_price: '',
        cadence: 'one_time',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            title: data.title,
            summary: data.summary,
            terms: data.terms,
            valid_until: data.valid_until || null,
            currency: data.currency,
            is_visible_to_client: data.is_visible_to_client,
            items: [
                {
                    description: data.item_description,
                    quantity: data.item_quantity,
                    unit_amount: toMinorUnits(data.item_price),
                    cadence: data.cadence,
                    sort_order: 0,
                },
            ],
        }));
        form.post(`/workspaces/${workspaceId}/clients/${clientId}/proposals`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 grid gap-2 md:grid-cols-6">
            <input
                className={`${inputClass} md:col-span-2`}
                placeholder="Proposal title"
                value={form.data.title}
                onChange={(event) => form.setData('title', event.target.value)}
                required
            />
            <input
                className={`${inputClass} md:col-span-2`}
                placeholder="Line description"
                value={form.data.item_description}
                onChange={(event) =>
                    form.setData('item_description', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                type="number"
                min="0"
                step="0.01"
                placeholder="Price"
                value={form.data.item_price}
                onChange={(event) =>
                    form.setData('item_price', event.target.value)
                }
                required
            />
            <button className={buttonClass} disabled={form.processing}>
                Draft proposal
            </button>
        </form>
    );
}

function InvoiceForm({
    workspaceId,
    clientId,
}: {
    workspaceId: string;
    clientId: string;
}) {
    const form = useForm({
        invoice_number: '',
        due_date: '',
        currency: 'USD',
        description: '',
        quantity: '1',
        unit_price: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            invoice_number: data.invoice_number,
            due_date: data.due_date || null,
            currency: data.currency,
            lines: [
                {
                    type: 'service',
                    description: data.description,
                    quantity: data.quantity,
                    unit_amount: toMinorUnits(data.unit_price),
                    tax_amount: 0,
                    sort_order: 0,
                },
            ],
        }));
        form.post(`/workspaces/${workspaceId}/clients/${clientId}/invoices`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 grid gap-2 md:grid-cols-6">
            <input
                className={inputClass}
                placeholder="Invoice number"
                value={form.data.invoice_number}
                onChange={(event) =>
                    form.setData('invoice_number', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                type="date"
                value={form.data.due_date}
                onChange={(event) =>
                    form.setData('due_date', event.target.value)
                }
            />
            <input
                className={`${inputClass} md:col-span-2`}
                placeholder="Line description"
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                type="number"
                min="0"
                step="0.01"
                placeholder="Unit price"
                value={form.data.unit_price}
                onChange={(event) =>
                    form.setData('unit_price', event.target.value)
                }
                required
            />
            <button className={buttonClass} disabled={form.processing}>
                Draft invoice
            </button>
        </form>
    );
}

function PaymentForm({
    workspaceId,
    invoice,
    timezone,
}: {
    workspaceId: string;
    invoice: Invoice;
    timezone: string;
}) {
    const form = useForm({
        amount: (invoice.balance_amount / 100).toFixed(2),
        // Same calendar as the time form. Nothing validates this one, so
        // a payment entered in the evening west of UTC simply lands in the
        // next month's revenue without complaint.
        received_on: todayIn(timezone),
        method: 'bank_transfer',
        reference: '',
        notes: '',
        currency: invoice.currency,
        status: 'succeeded',
        idempotency_key: crypto.randomUUID(),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            amount: toMinorUnits(data.amount),
        }));
        form.post(
            `/workspaces/${workspaceId}/invoices/${invoice.id}/payments`,
            {
                onSuccess: () => {
                    form.setData('idempotency_key', crypto.randomUUID());
                    form.reset('reference', 'notes');
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap gap-2">
            <input
                className={`${inputClass} max-w-32`}
                type="number"
                min="0.01"
                step="0.01"
                value={form.data.amount}
                onChange={(event) => form.setData('amount', event.target.value)}
                aria-label="Payment amount"
                required
            />
            <input
                className={`${inputClass} max-w-52`}
                placeholder="Payment reference"
                value={form.data.reference}
                onChange={(event) =>
                    form.setData('reference', event.target.value)
                }
            />
            <button className={secondaryButtonClass} disabled={form.processing}>
                Record payment
            </button>
        </form>
    );
}

function BillingScheduleForm({
    workspaceId,
    clientId,
    agreement,
    timezone,
}: {
    workspaceId: string;
    clientId: string;
    agreement: Agreement;
    timezone: string;
}) {
    const supportedCadences = ['monthly', 'quarterly', 'semi_annual', 'annual'];
    const form = useForm({
        client_agreement: agreement.id,
        cadence: supportedCadences.includes(agreement.billing_cadence)
            ? agreement.billing_cadence
            : 'monthly',
        next_run_on: todayIn(timezone),
        due_days: '30',
        currency: 'USD',
        description: '',
        amount: '',
        is_active: true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            client_agreement: data.client_agreement,
            cadence: data.cadence,
            next_run_on: data.next_run_on,
            due_days: Number(data.due_days),
            currency: data.currency,
            is_active: data.is_active,
            line_template: [
                {
                    type: 'service',
                    description: data.description,
                    quantity: '1',
                    unit_amount: toMinorUnits(data.amount),
                    tax_amount: 0,
                    sort_order: 0,
                },
            ],
        }));
        form.post(
            `/workspaces/${workspaceId}/clients/${clientId}/billing-schedules`,
            { onSuccess: () => form.reset('description', 'amount') },
        );
    };

    return (
        <form onSubmit={submit} className="mt-3 grid gap-2 md:grid-cols-5">
            <select
                className={inputClass}
                value={form.data.cadence}
                onChange={(event) =>
                    form.setData('cadence', event.target.value)
                }
            >
                {supportedCadences.map((cadence) => (
                    <option key={cadence} value={cadence}>
                        {cadence.replace('_', ' ')}
                    </option>
                ))}
            </select>
            <input
                className={inputClass}
                type="date"
                value={form.data.next_run_on}
                onChange={(event) =>
                    form.setData('next_run_on', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                placeholder="Recurring line"
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                required
            />
            <input
                className={inputClass}
                type="number"
                min="0"
                step="0.01"
                placeholder="Amount"
                value={form.data.amount}
                onChange={(event) => form.setData('amount', event.target.value)}
                required
            />
            <button className={secondaryButtonClass} disabled={form.processing}>
                Add schedule
            </button>
        </form>
    );
}

export default function Operations({ workspace }: { workspace: Workspace }) {
    return (
        <>
            <Head title={`${workspace.name} operations`} />
            <main
                className="min-h-screen bg-slate-100 text-slate-950"
                data-appearance-bridge
            >
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                        <div>
                            <Link href="/app" className="text-sm text-cyan-700">
                                ← Workspaces
                            </Link>
                            <h1 className="text-2xl font-semibold">
                                {workspace.name}
                            </h1>
                        </div>
                        <div className="flex items-center gap-4">
                            <AppearanceSelector />
                            <Link
                                href={`/workspaces/${workspace.id}/clients`}
                                className="text-sm font-semibold text-cyan-700"
                            >
                                Clients
                            </Link>
                            <Link
                                href={`/workspaces/${workspace.id}/time`}
                                className="text-sm font-semibold text-cyan-700"
                            >
                                Time sheet
                            </Link>
                            <span className="text-sm font-semibold tracking-[0.2em]">
                                SVC
                            </span>
                        </div>
                    </div>
                </header>

                <div className="mx-auto max-w-7xl space-y-8 px-6 py-10">
                    {workspace.clients.map((client) => (
                        <section
                            key={client.id}
                            className="rounded-3xl bg-white p-6 shadow-sm sm:p-8"
                        >
                            <h2 className="text-2xl font-semibold">
                                {client.name}
                            </h2>

                            <div className="mt-6 space-y-5">
                                <div>
                                    <h3 className="font-semibold">Time</h3>
                                    {client.projects.map((project) => (
                                        <div
                                            key={project.id}
                                            className="mt-3 rounded-2xl border border-slate-200 p-4"
                                        >
                                            <h4 className="font-medium">
                                                {project.name}
                                            </h4>
                                            <TimeEntryForm
                                                workspaceId={workspace.id}
                                                project={project}
                                                timezone={workspace.timezone}
                                            />
                                            <ul className="mt-3 space-y-1 text-sm text-slate-600">
                                                {project.time_entries.map(
                                                    (entry) => (
                                                        <li key={entry.id}>
                                                            {entry.worked_on} ·{' '}
                                                            {entry.minutes} min
                                                            ·{' '}
                                                            {entry.description}
                                                            {entry.is_deferred
                                                                ? ' · deferred'
                                                                : ''}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    ))}
                                </div>

                                <div className="border-t border-slate-200 pt-5">
                                    <h3 className="font-semibold">Proposals</h3>
                                    <ProposalForm
                                        workspaceId={workspace.id}
                                        clientId={client.id}
                                    />
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {client.proposals.map((proposal) => (
                                            <div
                                                key={proposal.id}
                                                className="rounded-xl bg-slate-50 px-4 py-3 text-sm"
                                            >
                                                <span className="font-medium">
                                                    {proposal.title}
                                                </span>{' '}
                                                ·{' '}
                                                {money(
                                                    proposal.total_amount,
                                                    proposal.currency,
                                                )}{' '}
                                                · {proposal.status}
                                                {proposal.status ===
                                                    'draft' && (
                                                    <button
                                                        className="ml-3 font-semibold text-cyan-700"
                                                        onClick={() =>
                                                            router.post(
                                                                `/workspaces/${workspace.id}/proposals/${proposal.id}/send`,
                                                            )
                                                        }
                                                    >
                                                        Send
                                                    </button>
                                                )}
                                                <AttachmentPanel
                                                    workspaceId={workspace.id}
                                                    recordType="proposal"
                                                    recordId={proposal.id}
                                                    attachments={
                                                        proposal.attachments
                                                    }
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="border-t border-slate-200 pt-5">
                                    <h3 className="font-semibold">
                                        Agreements
                                    </h3>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {client.agreements.map((agreement) => (
                                            <div
                                                key={agreement.id}
                                                className="rounded-xl bg-slate-50 px-4 py-3 text-sm"
                                            >
                                                <span className="font-medium">
                                                    {agreement.title}
                                                </span>{' '}
                                                · {agreement.billing_cadence} ·{' '}
                                                {agreement.status}
                                                {agreement.status ===
                                                    'draft' && (
                                                    <button
                                                        className="ml-3 font-semibold text-cyan-700"
                                                        onClick={() =>
                                                            router.post(
                                                                `/workspaces/${workspace.id}/agreements/${agreement.id}/activate`,
                                                            )
                                                        }
                                                    >
                                                        Activate
                                                    </button>
                                                )}
                                                <AttachmentPanel
                                                    workspaceId={workspace.id}
                                                    recordType="agreement"
                                                    recordId={agreement.id}
                                                    attachments={
                                                        agreement.attachments
                                                    }
                                                />
                                                {agreement.status ===
                                                    'active' && (
                                                    <BillingScheduleForm
                                                        workspaceId={
                                                            workspace.id
                                                        }
                                                        clientId={client.id}
                                                        timezone={
                                                            workspace.timezone
                                                        }
                                                        agreement={agreement}
                                                    />
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {client.billing_schedules.map(
                                            (schedule) => (
                                                <div
                                                    key={schedule.id}
                                                    className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                                >
                                                    {schedule.cadence.replace(
                                                        '_',
                                                        ' ',
                                                    )}{' '}
                                                    · next{' '}
                                                    {schedule.next_run_on}
                                                    {schedule.is_active && (
                                                        <button
                                                            className="ml-3 font-semibold text-cyan-700"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/workspaces/${workspace.id}/billing-schedules/${schedule.id}/generate`,
                                                                )
                                                            }
                                                        >
                                                            Generate due
                                                        </button>
                                                    )}
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>

                                <div className="border-t border-slate-200 pt-5">
                                    <h3 className="font-semibold">Invoices</h3>
                                    <InvoiceForm
                                        workspaceId={workspace.id}
                                        clientId={client.id}
                                    />
                                    <div className="mt-4 grid gap-3 lg:grid-cols-2">
                                        {client.invoices.map((invoice) => (
                                            <article
                                                key={invoice.id}
                                                className="rounded-2xl border border-slate-200 p-4"
                                            >
                                                <div className="flex justify-between gap-3">
                                                    <div>
                                                        <h4 className="font-semibold">
                                                            {
                                                                invoice.invoice_number
                                                            }
                                                        </h4>
                                                        <p className="text-sm text-slate-600">
                                                            {money(
                                                                invoice.balance_amount,
                                                                invoice.currency,
                                                            )}{' '}
                                                            due ·{' '}
                                                            {invoice.status}
                                                        </p>
                                                    </div>
                                                    <div className="flex gap-3 text-sm font-semibold text-cyan-700">
                                                        <a
                                                            href={`/workspaces/${workspace.id}/invoices/${invoice.id}/pdf`}
                                                        >
                                                            PDF
                                                        </a>
                                                        {invoice.status ===
                                                            'draft' && (
                                                            <button
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/workspaces/${workspace.id}/invoices/${invoice.id}/issue`,
                                                                    )
                                                                }
                                                            >
                                                                Issue
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>
                                                {invoice.balance_amount > 0 &&
                                                    invoice.status !==
                                                        'draft' &&
                                                    invoice.status !==
                                                        'void' && (
                                                        <PaymentForm
                                                            workspaceId={
                                                                workspace.id
                                                            }
                                                            invoice={invoice}
                                                            timezone={
                                                                workspace.timezone
                                                            }
                                                        />
                                                    )}
                                                <AttachmentPanel
                                                    workspaceId={workspace.id}
                                                    recordType="invoice"
                                                    recordId={invoice.id}
                                                    attachments={
                                                        invoice.attachments
                                                    }
                                                />
                                            </article>
                                        ))}
                                    </div>
                                </div>

                                <div className="border-t border-slate-200 pt-5">
                                    <h3 className="font-semibold">Activity</h3>
                                    <p className="mt-1 text-sm text-slate-500">
                                        The latest 100 recorded events for this
                                        client.
                                    </p>
                                    <ActivityTimeline
                                        activities={client.activities}
                                    />
                                </div>
                            </div>
                        </section>
                    ))}
                </div>
            </main>
        </>
    );
}
