import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ActivityTimeline } from '@/components/activity-timeline';
import type { CompanyActivity } from '@/components/activity-timeline';
import {
    EngagementAction,
    ProposalDraftForm,
    ScheduleDraftForm,
} from '@/components/agreements/engagement-actions';
import { AttachmentPageLinks } from '@/components/files/attachment-page-links';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import {
    predatesInvoiceIssue,
    predatesInvoiceIssueWarning,
} from '@/lib/payments';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { todayIn } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { AttachmentPanel as SharedFilePanel } from '@/types/files';

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
    time_url: string;
};

type Proposal = {
    id: string;
    version: string;
    send_href: string | null;
    title: string;
    status: string;
    total_amount: number;
    currency: string;
    attachments: Attachment[];
    attachment_panel?: SharedFilePanel & { legacy_upload_href: string | null };
};

type Agreement = {
    id: string;
    version: string;
    activate_href: string | null;
    create_schedule_href: string | null;
    currency: string;
    title: string;
    status: string;
    billing_cadence: string;
    attachments: Attachment[];
    attachment_panel?: SharedFilePanel & { legacy_upload_href: string | null };
};

type BillingSchedule = {
    id: string;
    version: string;
    generate_href: string | null;
    agreement_id: string;
    cadence: string;
    next_run_on: string;
    is_active: boolean;
};

type Invoice = {
    id: string;
    version: string;
    issue_href: string | null;
    payment_href: string | null;
    pdf_href: string;
    invoice_number: string;
    status: string;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    currency: string;
    issue_date: string | null;
    due_date: string | null;
    attachments: Attachment[];
    attachment_panel?: SharedFilePanel & { legacy_upload_href: string | null };
};

type Client = {
    id: string;
    version: string;
    create_proposal_href: string | null;
    name: string;
    invoice_create_href: string | null;
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
    files_next_href?: string | null;
    files_previous_href?: string | null;
    /** The workspace's calendar; date defaults are read on it, not UTC's. */
    timezone: string;
};

const inputClass =
    'w-full rounded-xl border border-border bg-card px-3 py-2 text-sm text-card-foreground outline-none focus:border-cyan-600 focus-visible:ring-2 focus-visible:ring-cyan-600/50';
const buttonClass =
    'rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50';
const secondaryButtonClass =
    'rounded-xl border border-border bg-card px-3 py-2 text-sm font-semibold text-card-foreground hover:bg-muted disabled:opacity-50';

export function AgreementActivationButton({
    href,
    version,
}: {
    href: string;
    version: string;
}) {
    return (
        <EngagementAction
            href={href}
            version={version}
            label="Activate"
            description="Activate this agreement using its current terms. This enables its billing workflow."
        />
    );
}

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

function OperationFiles({
    panel,
    attachments,
}: {
    panel?: SharedFilePanel & { legacy_upload_href: string | null };
    attachments: Attachment[];
}) {
    return (
        <div className="mt-3 min-w-0 border-t border-slate-200 pt-3">
            <AttachmentSharingPanel
                panel={
                    panel ?? {
                        files: attachments.map((file) => ({
                            id: file.id,
                            filename: file.name,
                            media_type: file.media_type,
                            bytes: file.bytes,
                            uploaded_at: null,
                            download_href: file.download_url,
                            delete_href: null,
                            sharing: null,
                        })),
                        upload: null,
                        next_href: null,
                    }
                }
                legacyForms={
                    panel
                        ? { upload_href: panel.legacy_upload_href }
                        : undefined
                }
            />
        </div>
    );
}

function TimeEntryForm({ project }: { project: Project }) {
    return (
        <Link href={project.time_url} className={buttonClass}>
            Log time
        </Link>
    );
}

export function InvoiceForm({
    href,
    clientId,
}: {
    href: string;
    clientId: string;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const attempt = useRef<{ fingerprint: string; key: string } | null>(null);
    const form = useForm({
        invoice_number: '',
        due_date: '',
        currency: 'USD',
        description: '',
        quantity: '1',
        unit_price: '',
    });

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        const amount = toMinorUnits(form.data.unit_price);

        if (amount === null) {
            setError('Enter a valid unit price.');

            return;
        }

        const body = {
            company_id: clientId,
            invoice_number: form.data.invoice_number,
            due_date: form.data.due_date || null,
            currency: form.data.currency,
            is_visible_to_client: false,
            manual_lines: [
                {
                    type: 'service',
                    description: form.data.description,
                    quantity: form.data.quantity,
                    unit_amount: amount,
                    tax_amount: 0,
                },
            ],
        } satisfies ApiSchema<'InvoiceCreateRequest'>;
        const fingerprint = JSON.stringify(body);

        if (attempt.current?.fingerprint !== fingerprint) {
            attempt.current = { fingerprint, key: newIdempotencyKey() };
        }

        setBusy(true);
        const result = await apiRequest(
            'POST',
            href,
            body,
            attempt.current.key,
        );

        if (!result.ok) {
            setError(result.message);
            setBusy(false);

            return;
        }

        attempt.current = null;
        setError(null);
        form.reset();
        void refreshPageProps({ onFinish: () => setBusy(false) });
    };

    return (
        <form
            aria-label="Draft invoice"
            onSubmit={submit}
            className="mt-4 grid grid-cols-1 gap-2 md:grid-cols-6"
        >
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
            {error !== null && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere text-red-700 md:col-span-6"
                >
                    {error}
                </p>
            )}
            <button className={buttonClass} disabled={busy}>
                Draft invoice
            </button>
        </form>
    );
}

/**
 * Recording a payment against one invoice, including the day it arrived.
 *
 * Exported for its own test: the date this writes is what the finance window
 * reconciles by, and it was initialised, posted and never rendered - so every
 * payment recorded here was dated the day it was typed in, silently.
 */
export function PaymentForm({
    invoice,
    timezone,
}: {
    invoice: Invoice;
    timezone: string;
}) {
    const [busy, setBusy] = useState(false);
    const [apiError, setApiError] = useState<string | null>(null);
    const form = useForm({
        amount: (invoice.balance_amount / 100).toFixed(2),
        // Same calendar as the time form, and now rendered as well as sent:
        // the field was initialised here and posted and had no control, so a
        // cheque that cleared last Tuesday could only be recorded as having
        // arrived today. The service bounds it against this same calendar.
        received_on: todayIn(timezone),
        method: 'bank_transfer',
        reference: '',
        notes: '',
        currency: invoice.currency,
        status: 'succeeded',
        idempotency_key: newIdempotencyKey(),
    });

    // A service refusal arrives under `billing` rather than under a field, so
    // it is not one of this form's own keys; the bag itself carries whatever
    // the server put in it.
    const errors: Record<string, string | undefined> = form.errors;
    const refusal = apiError ?? errors.billing ?? errors.received_on;

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (busy || invoice.payment_href === null) {
            return;
        }

        setBusy(true);
        const result = await apiRequest(
            'POST',
            invoice.payment_href,
            {
                invoice_id: invoice.id,
                expected_version: invoice.version,
                confirm: true,
                amount: toMinorUnits(form.data.amount),
                currency: form.data.currency,
                method: form.data.method,
                reference: form.data.reference,
                notes: form.data.notes,
                received_on: form.data.received_on,
                status: form.data.status,
            },
            form.data.idempotency_key,
        );

        if (result.ok) {
            setApiError(null);
            form.setData('idempotency_key', newIdempotencyKey());
            form.reset('reference', 'notes');
            // Back to today, and read from the clock rather than
            // `form.reset('received_on')`. Inertia keeps this form
            // mounted across the redirect, so a date typed to record a
            // backdated payment stayed in the field and the *next*
            // payment recorded here silently carried it - the same
            // wrong-period defect this field exists to fix, arrived at
            // from the other side and quieter, because a stale date
            // reads exactly like the default it replaced. `reset()`
            // would restore the value captured when the page loaded,
            // which is yesterday's on a screen left open overnight.
            form.setData('received_on', todayIn(timezone));
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setApiError(result.message);
        setBusy(false);
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
                className={`${inputClass} max-w-44`}
                type="date"
                // Today on the workspace's calendar. No floor here: how far
                // back this workspace records payments is the service's
                // policy, and it names the earliest acceptable date when it
                // refuses one.
                max={todayIn(timezone)}
                value={form.data.received_on}
                onChange={(event) =>
                    form.setData('received_on', event.target.value)
                }
                aria-label="Payment received on"
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
            <button className={secondaryButtonClass} disabled={busy}>
                Record payment
            </button>
            {predatesInvoiceIssue(
                form.data.received_on,
                invoice.issue_date,
            ) && (
                <p
                    role="status"
                    className="w-full text-sm wrap-anywhere text-amber-700 dark:text-amber-500"
                >
                    {predatesInvoiceIssueWarning(invoice.issue_date ?? '')}
                </p>
            )}
            {/*
             * The service's refusals arrive as a `billing` validation error,
             * and this form used to drop them: a date outside the workspace's
             * window failed silently and the operator saw the form sit there.
             */}
            {refusal !== undefined && (
                <p
                    role="alert"
                    className="w-full text-sm wrap-anywhere text-red-700"
                >
                    {refusal}
                </p>
            )}
        </form>
    );
}

export function InvoiceIssueButton({ invoice }: { invoice: Invoice }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [key] = useState(newIdempotencyKey);
    const issue = async () => {
        if (busy || invoice.issue_href === null) {
            return;
        }

        setBusy(true);
        const result = await apiRequest(
            'POST',
            invoice.issue_href,
            { expected_version: invoice.version, confirm: true },
            key,
        );

        if (result.ok) {
            setError(null);
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setError(result.message);
        setBusy(false);
    };

    return (
        <div>
            <button disabled={busy} onClick={() => void issue()}>
                Issue
            </button>
            {error !== null && <p role="alert">{error}</p>}
        </div>
    );
}

export default function Operations({ workspace }: { workspace: Workspace }) {
    return (
        <>
            <Head title={`${workspace.name} operations`} />
            <WorkspaceShell>
                <main
                    className={cn(
                        SHELL_CONTAINER,
                        'space-y-8 py-10 wrap-anywhere',
                    )}
                >
                    <h1 className="text-2xl font-semibold">{workspace.name}</h1>
                    <div id="files-pagination">
                        <AttachmentPageLinks
                            nextHref={workspace.files_next_href}
                            previousHref={workspace.files_previous_href}
                        />
                    </div>
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
                                            <TimeEntryForm project={project} />
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
                                    {client.create_proposal_href !== null && (
                                        <ProposalDraftForm
                                            href={client.create_proposal_href}
                                            companyId={client.id}
                                            version={client.version}
                                        />
                                    )}
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
                                                · {statusLabel(proposal.status)}
                                                {proposal.send_href !==
                                                    null && (
                                                    <EngagementAction
                                                        href={
                                                            proposal.send_href
                                                        }
                                                        version={
                                                            proposal.version
                                                        }
                                                        label="Send"
                                                        description="Make this proposal available for client review and acceptance."
                                                    />
                                                )}
                                                <OperationFiles
                                                    panel={
                                                        proposal.attachment_panel
                                                    }
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
                                                ·{' '}
                                                {statusLabel(
                                                    agreement.billing_cadence,
                                                )}{' '}
                                                ·{' '}
                                                {statusLabel(agreement.status)}
                                                {agreement.activate_href !==
                                                    null && (
                                                    <AgreementActivationButton
                                                        href={
                                                            agreement.activate_href
                                                        }
                                                        version={
                                                            agreement.version
                                                        }
                                                    />
                                                )}
                                                <OperationFiles
                                                    panel={
                                                        agreement.attachment_panel
                                                    }
                                                    attachments={
                                                        agreement.attachments
                                                    }
                                                />
                                                {agreement.create_schedule_href !==
                                                    null && (
                                                    <ScheduleDraftForm
                                                        href={
                                                            agreement.create_schedule_href
                                                        }
                                                        companyId={client.id}
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
                                                    {schedule.generate_href !==
                                                        null && (
                                                        <EngagementAction
                                                            href={
                                                                schedule.generate_href
                                                            }
                                                            version={
                                                                schedule.version
                                                            }
                                                            label="Generate due"
                                                            description="Generate invoices for this billing schedule through today. Review its next run and terms before confirming."
                                                        />
                                                    )}
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>

                                <div className="border-t border-slate-200 pt-5">
                                    <h3 className="font-semibold">Invoices</h3>
                                    {client.invoice_create_href !== null && (
                                        <InvoiceForm
                                            href={client.invoice_create_href}
                                            clientId={client.id}
                                        />
                                    )}
                                    <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                        {client.invoices.map((invoice) => (
                                            <article
                                                key={invoice.id}
                                                className="rounded-2xl border border-slate-200 p-4"
                                            >
                                                <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
                                                    <div className="min-w-0 flex-1 wrap-anywhere">
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
                                                            {statusLabel(
                                                                invoice.status,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <div className="flex shrink-0 flex-wrap gap-3 text-sm font-semibold text-cyan-700">
                                                        <a
                                                            href={
                                                                invoice.pdf_href
                                                            }
                                                        >
                                                            PDF
                                                        </a>
                                                        {invoice.issue_href !==
                                                            null && (
                                                            <InvoiceIssueButton
                                                                invoice={
                                                                    invoice
                                                                }
                                                            />
                                                        )}
                                                    </div>
                                                </div>
                                                {invoice.payment_href !==
                                                    null && (
                                                    <PaymentForm
                                                        invoice={invoice}
                                                        timezone={
                                                            workspace.timezone
                                                        }
                                                    />
                                                )}
                                                <OperationFiles
                                                    panel={
                                                        invoice.attachment_panel
                                                    }
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
                </main>
            </WorkspaceShell>
        </>
    );
}
