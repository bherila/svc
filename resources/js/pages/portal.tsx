import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

type ProposalItem = {
    id: string;
    description: string;
    quantity: string;
    unit_amount: number;
    cadence: string;
};

type Attachment = {
    id: string;
    name: string;
    media_type: string;
    bytes: number;
    download_url: string;
};

type Proposal = {
    id: string;
    title: string;
    summary: string | null;
    terms: string | null;
    currency: string;
    valid_until: string | null;
    status: string;
    sent_at: string | null;
    accepted_at: string | null;
    items: ProposalItem[];
    attachments: Attachment[];
};

type Agreement = {
    id: string;
    title: string;
    status: string;
    starts_on: string | null;
    ends_on: string | null;
    agreement_text: string | null;
    currency: string;
    hourly_rate_amount: number | null;
    retainer_amount: number | null;
    retainer_minutes: number | null;
    billing_cadence: string;
    rollover_policy: string | null;
    signed_at: string | null;
    signer_name: string | null;
    signer_title: string | null;
    attachments: Attachment[];
};

type Invoice = {
    id: string;
    invoice_number: string;
    issue_date: string | null;
    due_date: string | null;
    service_period_start: string | null;
    service_period_end: string | null;
    currency: string;
    subtotal_amount: number;
    tax_amount: number;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    status: string;
    pdf_url: string;
    attachments: Attachment[];
};

type PortalCompany = {
    id: string;
    name: string;
    proposals: Proposal[];
    agreements: Agreement[];
    invoices: Invoice[];
    projects: Array<{
        id: string;
        name: string;
        description: string | null;
        status: string;
        tasks: Array<{
            id: string;
            title: string;
            description: string | null;
            status: string;
        }>;
        time_entries: Array<{
            id: string;
            worked_on: string;
            minutes: number;
            description: string | null;
        }>;
    }>;
};

const displayDate = (value: string | null) =>
    value ? value.slice(0, 10) : '—';

const formatDuration = (minutes: number) => {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
};

const displayMoney = (amount: number | null, currency: string) =>
    amount === null
        ? '—'
        : new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency,
          }).format(amount / 100);

function AttachmentLinks({ attachments }: { attachments: Attachment[] }) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <div className="mt-5 border-t border-white/10 pt-4">
            <p className="text-xs font-semibold tracking-wide text-slate-400 uppercase">
                Documents
            </p>
            <div className="mt-2 flex flex-wrap gap-3 text-sm">
                {attachments.map((attachment) => (
                    <a
                        key={attachment.id}
                        href={attachment.download_url}
                        className="font-semibold text-cyan-300"
                    >
                        {attachment.name}
                    </a>
                ))}
            </div>
        </div>
    );
}

function ProposalAcceptanceForm({
    companyId,
    proposal,
}: {
    companyId: string;
    proposal: Proposal;
}) {
    const form = useForm({ signer_name: '', signer_title: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/portal/${companyId}/proposals/${proposal.id}/accept`);
    };

    return (
        <form onSubmit={submit} className="mt-6 border-t border-white/10 pt-5">
            <h3 className="font-semibold text-cyan-200">Accept proposal</h3>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <label className="text-sm text-slate-300">
                    Signer name
                    <input
                        required
                        value={form.data.signer_name}
                        onChange={(event) =>
                            form.setData('signer_name', event.target.value)
                        }
                        className="mt-1 w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-white"
                    />
                    {form.errors.signer_name && (
                        <span className="mt-1 block text-xs text-rose-300">
                            {form.errors.signer_name}
                        </span>
                    )}
                </label>
                <label className="text-sm text-slate-300">
                    Signer title
                    <input
                        value={form.data.signer_title}
                        onChange={(event) =>
                            form.setData('signer_title', event.target.value)
                        }
                        className="mt-1 w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-white"
                    />
                </label>
            </div>
            <button
                type="submit"
                disabled={form.processing}
                className="mt-4 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50"
            >
                {form.processing ? 'Submitting…' : 'Accept proposal'}
            </button>
        </form>
    );
}

export default function Portal({ company }: { company: PortalCompany }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title={`${company.name} portal`} />
            <main className="min-h-screen bg-slate-950 text-white">
                <div className="mx-auto max-w-5xl px-6 py-8">
                    <header className="flex items-center justify-between border-b border-white/10 pb-6">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.2em] text-cyan-300 uppercase">
                                SVC client portal
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold">
                                {company.name}
                            </h1>
                        </div>
                        <div className="text-right text-sm text-slate-300">
                            <p>{auth.user?.name}</p>
                            <Link
                                href="/"
                                className="font-semibold text-cyan-300"
                            >
                                SVC home
                            </Link>
                        </div>
                    </header>

                    <section className="mt-10">
                        <h2 className="text-2xl font-semibold">Proposals</h2>
                        <div className="mt-5 grid gap-5 md:grid-cols-2">
                            {company.proposals.map((proposal) => (
                                <article
                                    key={proposal.id}
                                    className="rounded-3xl border border-white/10 bg-white/5 p-6"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <h3 className="text-xl font-semibold">
                                            {proposal.title}
                                        </h3>
                                        <span className="rounded-full bg-cyan-300/10 px-3 py-1 text-xs text-cyan-200">
                                            {proposal.status}
                                        </span>
                                    </div>
                                    {proposal.summary && (
                                        <p className="mt-3 text-sm leading-6 text-slate-300">
                                            {proposal.summary}
                                        </p>
                                    )}
                                    {proposal.terms && (
                                        <p className="mt-3 text-sm leading-6 whitespace-pre-line text-slate-400">
                                            {proposal.terms}
                                        </p>
                                    )}
                                    <ul className="mt-5 space-y-2 text-sm text-slate-300">
                                        {proposal.items.map((item) => (
                                            <li
                                                key={item.id}
                                                className="flex justify-between gap-3 rounded-xl bg-slate-900/80 px-4 py-3"
                                            >
                                                <span>{item.description}</span>
                                                <span className="text-slate-400">
                                                    {item.quantity} ×{' '}
                                                    {displayMoney(
                                                        item.unit_amount,
                                                        proposal.currency,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="mt-4 text-xs text-slate-400">
                                        Valid until{' '}
                                        {displayDate(proposal.valid_until)}
                                    </p>
                                    <AttachmentLinks
                                        attachments={proposal.attachments}
                                    />
                                    {proposal.status === 'sent' ? (
                                        <ProposalAcceptanceForm
                                            companyId={company.id}
                                            proposal={proposal}
                                        />
                                    ) : (
                                        <p className="mt-5 border-t border-white/10 pt-5 text-sm text-emerald-300">
                                            Accepted on{' '}
                                            {displayDate(proposal.accepted_at)}
                                        </p>
                                    )}
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="mt-10">
                        <h2 className="text-2xl font-semibold">Agreements</h2>
                        <div className="mt-5 grid gap-5 md:grid-cols-2">
                            {company.agreements.map((agreement) => (
                                <article
                                    key={agreement.id}
                                    className="rounded-3xl border border-white/10 bg-white/5 p-6"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <h3 className="text-xl font-semibold">
                                            {agreement.title}
                                        </h3>
                                        <span className="rounded-full bg-cyan-300/10 px-3 py-1 text-xs text-cyan-200">
                                            {agreement.status}
                                        </span>
                                    </div>
                                    {agreement.agreement_text && (
                                        <p className="mt-3 text-sm leading-6 whitespace-pre-line text-slate-300">
                                            {agreement.agreement_text}
                                        </p>
                                    )}
                                    <dl className="mt-5 grid grid-cols-2 gap-3 text-sm text-slate-300">
                                        <div>
                                            <dt className="text-slate-500">
                                                Starts
                                            </dt>
                                            <dd>
                                                {displayDate(
                                                    agreement.starts_on,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-slate-500">
                                                Ends
                                            </dt>
                                            <dd>
                                                {displayDate(agreement.ends_on)}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-slate-500">
                                                Cadence
                                            </dt>
                                            <dd>{agreement.billing_cadence}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-slate-500">
                                                Retainer
                                            </dt>
                                            <dd>
                                                {displayMoney(
                                                    agreement.retainer_amount,
                                                    agreement.currency,
                                                )}
                                            </dd>
                                        </div>
                                    </dl>
                                    <AttachmentLinks
                                        attachments={agreement.attachments}
                                    />
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="mt-10">
                        <h2 className="text-2xl font-semibold">Invoices</h2>
                        <div className="mt-5 grid gap-5 md:grid-cols-2">
                            {company.invoices.map((invoice) => (
                                <article
                                    key={invoice.id}
                                    className="rounded-3xl border border-white/10 bg-white/5 p-6"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <h3 className="text-xl font-semibold">
                                            <Link
                                                href={`/portal/${company.id}/invoices/${invoice.id}`}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {invoice.invoice_number}
                                            </Link>
                                        </h3>
                                        <span className="rounded-full bg-cyan-300/10 px-3 py-1 text-xs text-cyan-200">
                                            {invoice.status}
                                        </span>
                                    </div>
                                    <dl className="mt-5 grid grid-cols-2 gap-3 text-sm text-slate-300">
                                        <div>
                                            <dt className="text-slate-500">
                                                Issued
                                            </dt>
                                            <dd>
                                                {displayDate(
                                                    invoice.issue_date,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-slate-500">
                                                Due
                                            </dt>
                                            <dd>
                                                {displayDate(invoice.due_date)}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-slate-500">
                                                Total
                                            </dt>
                                            <dd>
                                                {displayMoney(
                                                    invoice.total_amount,
                                                    invoice.currency,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-slate-500">
                                                Balance
                                            </dt>
                                            <dd>
                                                {displayMoney(
                                                    invoice.balance_amount,
                                                    invoice.currency,
                                                )}
                                            </dd>
                                        </div>
                                    </dl>
                                    <a
                                        href={invoice.pdf_url}
                                        className="mt-5 inline-block font-semibold text-cyan-300"
                                    >
                                        Download authenticated PDF
                                    </a>
                                    <AttachmentLinks
                                        attachments={invoice.attachments}
                                    />
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="mt-10 grid gap-5 md:grid-cols-2">
                        {company.projects.map((project) => (
                            <article
                                key={project.id}
                                className="rounded-3xl border border-white/10 bg-white/5 p-6"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <h2 className="text-xl font-semibold">
                                        {project.name}
                                    </h2>
                                    <span className="rounded-full bg-cyan-300/10 px-3 py-1 text-xs text-cyan-200">
                                        {project.status}
                                    </span>
                                </div>
                                {project.description && (
                                    <p className="mt-3 text-sm leading-6 text-slate-300">
                                        {project.description}
                                    </p>
                                )}
                                <ul className="mt-5 space-y-3">
                                    {project.tasks.map((task) => (
                                        <li
                                            key={task.id}
                                            className="rounded-xl bg-slate-900/80 p-4"
                                        >
                                            <div className="flex justify-between gap-3">
                                                <span className="font-medium">
                                                    {task.title}
                                                </span>
                                                <span className="text-xs text-slate-400">
                                                    {task.status.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                            </div>
                                            {task.description && (
                                                <p className="mt-2 text-sm text-slate-400">
                                                    {task.description}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {project.time_entries.length > 0 && (
                                    <div className="mt-6 border-t border-white/10 pt-5">
                                        <h3 className="text-sm font-semibold text-slate-300">
                                            Time on this project
                                        </h3>
                                        <ul className="mt-3 space-y-2">
                                            {project.time_entries.map(
                                                (entry) => (
                                                    <li
                                                        key={entry.id}
                                                        className="flex justify-between gap-4 text-sm"
                                                    >
                                                        <span className="text-slate-300">
                                                            {entry.description ??
                                                                'Work performed'}
                                                        </span>
                                                        <span className="shrink-0 text-slate-400 tabular-nums">
                                                            {displayDate(
                                                                entry.worked_on,
                                                            )}{' '}
                                                            ·{' '}
                                                            {formatDuration(
                                                                entry.minutes,
                                                            )}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}
                            </article>
                        ))}
                    </section>
                </div>
            </main>
        </>
    );
}
