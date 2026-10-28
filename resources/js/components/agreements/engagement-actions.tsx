import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { todayIn } from '@/lib/time';

const inputClass =
    'min-w-0 w-full rounded-xl border border-border bg-card px-3 py-2 text-sm';
const buttonClass =
    'rounded-xl border border-border px-3 py-2 text-sm font-semibold disabled:opacity-50';

/** Retain the same receipt and payload after an uncertain response. */
function useEngagementMutation(href: string) {
    const pending = useRef<{ body: object; key: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const submit = async (body: object, onSuccess?: () => void) => {
        if (busy) {
            return;
        }

        pending.current ??= { body, key: newIdempotencyKey() };
        setBusy(true);
        const result = await apiRequest(
            'POST',
            href,
            pending.current.body,
            pending.current.key,
        );

        if (result.ok) {
            pending.current = null;
            setRetrying(false);
            setError(null);
            onSuccess?.();
            await refreshPageProps();
        } else {
            setError(result.message);
            const uncertain = result.status === 0 || result.status >= 500;
            setRetrying(uncertain);

            if (!uncertain) {
                pending.current = null;
            }

            if (result.status === 409) {
                await refreshPageProps();
            }
        }

        setBusy(false);
    };

    return { submit, busy, retrying, error };
}

export function EngagementAction({
    href,
    version,
    label,
    description,
}: {
    href: string;
    version: string;
    label: string;
    description: string;
}) {
    const [open, setOpen] = useState(false);
    const mutation = useEngagementMutation(href);

    return (
        <>
            <button
                type="button"
                className={buttonClass}
                onClick={() => setOpen(true)}
            >
                {label}
            </button>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!mutation.busy) {
                        setOpen(next);
                    }
                }}
            >
                <DialogContent className="grid-cols-1 wrap-anywhere">
                    <DialogTitle>{label}?</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                    {mutation.error && <p role="alert">{mutation.error}</p>}
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={mutation.busy}
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={mutation.busy}
                            onClick={() =>
                                void mutation.submit(
                                    {
                                        expected_version: version,
                                        confirm: true,
                                    },
                                    () => setOpen(false),
                                )
                            }
                        >
                            {mutation.retrying
                                ? 'Retry confirmed action'
                                : label}
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function ProposalDraftForm({
    href,
    companyId,
    version,
}: {
    href: string;
    companyId: string;
    version: string;
}) {
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [price, setPrice] = useState('');
    const mutation = useEngagementMutation(href);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        void mutation.submit(
            {
                company_id: companyId,
                expected_version: version,
                title,
                currency: 'USD',
                items: [
                    {
                        description,
                        quantity: '1',
                        unit_amount: Math.round(Number(price) * 100),
                        cadence: 'one_time',
                        sort_order: 0,
                    },
                ],
            },
            () => {
                setTitle('');
                setDescription('');
                setPrice('');
            },
        );
    };

    return (
        <form
            onSubmit={submit}
            className="mt-4 grid grid-cols-1 gap-2 md:grid-cols-4"
        >
            <input
                className={inputClass}
                aria-label="Proposal title"
                placeholder="Proposal title"
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                required
                disabled={mutation.busy || mutation.retrying}
            />
            <input
                className={inputClass}
                aria-label="Proposal line description"
                placeholder="Line description"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                required
                disabled={mutation.busy || mutation.retrying}
            />
            <input
                className={inputClass}
                aria-label="Proposal price"
                placeholder="Price"
                type="number"
                min="0"
                step="0.01"
                value={price}
                onChange={(event) => setPrice(event.target.value)}
                required
                disabled={mutation.busy || mutation.retrying}
            />
            <button className={buttonClass} disabled={mutation.busy}>
                {mutation.retrying ? 'Retry draft proposal' : 'Draft proposal'}
            </button>
            {mutation.error && (
                <p role="alert" className="md:col-span-4">
                    {mutation.error}
                </p>
            )}
        </form>
    );
}

export function ScheduleDraftForm({
    href,
    companyId,
    agreement,
    timezone,
}: {
    href: string;
    companyId: string;
    agreement: { id: string; version: string; billing_cadence: string };
    timezone: string;
}) {
    const cadences = ['monthly', 'quarterly', 'semi_annual', 'annual'];
    const [cadence, setCadence] = useState(
        cadences.includes(agreement.billing_cadence)
            ? agreement.billing_cadence
            : 'monthly',
    );
    const [nextRun, setNextRun] = useState(todayIn(timezone));
    const [description, setDescription] = useState('');
    const [amount, setAmount] = useState('');
    const mutation = useEngagementMutation(href);
    const disabled = mutation.busy || mutation.retrying;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        void mutation.submit(
            {
                company_id: companyId,
                client_agreement: agreement.id,
                expected_version: agreement.version,
                cadence,
                next_run_on: nextRun,
                due_days: 30,
                currency: 'USD',
                is_active: true,
                line_template: [
                    {
                        type: 'service',
                        description,
                        quantity: '1',
                        unit_amount: Math.round(Number(amount) * 100),
                        tax_amount: 0,
                        sort_order: 0,
                    },
                ],
            },
            () => {
                setDescription('');
                setAmount('');
            },
        );
    };

    return (
        <form
            onSubmit={submit}
            className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-5"
        >
            <select
                className={inputClass}
                aria-label="Schedule cadence"
                value={cadence}
                onChange={(event) => setCadence(event.target.value)}
                disabled={disabled}
            >
                {cadences.map((value) => (
                    <option key={value} value={value}>
                        {statusLabel(value)}
                    </option>
                ))}
            </select>
            <input
                className={inputClass}
                aria-label="Next schedule run"
                type="date"
                value={nextRun}
                onChange={(event) => setNextRun(event.target.value)}
                required
                disabled={disabled}
            />
            <input
                className={inputClass}
                aria-label="Recurring line description"
                placeholder="Recurring line"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                required
                disabled={disabled}
            />
            <input
                className={inputClass}
                aria-label="Recurring amount"
                placeholder="Amount"
                type="number"
                min="0"
                step="0.01"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                required
                disabled={disabled}
            />
            <button className={buttonClass} disabled={mutation.busy}>
                {mutation.retrying ? 'Retry schedule' : 'Add schedule'}
            </button>
            {mutation.error && (
                <p role="alert" className="md:col-span-5">
                    {mutation.error}
                </p>
            )}
        </form>
    );
}
