import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { todayIn } from '@/lib/time';
import { cn } from '@/lib/utils';

const buttonClass =
    'rounded-xl border border-border px-3 py-2 text-sm font-semibold disabled:opacity-50';

/** The cadences a schedule can recur on, with the words a reader sees. */
const SCHEDULE_CADENCES = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'semi_annual', label: 'Semi-annual' },
    { value: 'annual', label: 'Annual' },
] as const;

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

    const locked = mutation.busy || mutation.retrying;

    return (
        <form
            onSubmit={submit}
            aria-label="Draft proposal"
            className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,10rem)_auto] lg:items-end"
        >
            <FormField label="Proposal title">
                {(id) => (
                    <Input
                        id={id}
                        value={title}
                        onChange={(event) => setTitle(event.target.value)}
                        required
                        disabled={locked}
                    />
                )}
            </FormField>
            <FormField label="Line description">
                {(id) => (
                    <Input
                        id={id}
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                        required
                        disabled={locked}
                    />
                )}
            </FormField>
            <FormField label="Price (USD)">
                {(id) => (
                    <Input
                        id={id}
                        type="number"
                        min="0"
                        step="0.01"
                        value={price}
                        onChange={(event) => setPrice(event.target.value)}
                        required
                        disabled={locked}
                    />
                )}
            </FormField>
            <button
                className={cn(buttonClass, 'justify-self-start')}
                disabled={mutation.busy}
            >
                {mutation.retrying ? 'Retry draft proposal' : 'Draft proposal'}
            </button>
            {mutation.error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere sm:col-span-2 lg:col-span-4"
                >
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
    agreement: {
        id: string;
        title?: string;
        version: string;
        billing_cadence: string;
        currency: string;
    };
    timezone: string;
}) {
    const [cadence, setCadence] = useState<string>(
        SCHEDULE_CADENCES.some(
            (option) => option.value === agreement.billing_cadence,
        )
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
                currency: agreement.currency,
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
            aria-label={
                agreement.title === undefined
                    ? 'Add billing schedule'
                    : `Add billing schedule for ${agreement.title}`
            }
            className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,9rem)_minmax(0,10rem)_minmax(0,1fr)_minmax(0,9rem)_auto] lg:items-end"
        >
            <FormField label="Cadence">
                {(id) => (
                    <Select
                        items={SCHEDULE_CADENCES}
                        value={cadence}
                        onValueChange={(next) => {
                            if (typeof next === 'string') {
                                setCadence(next);
                            }
                        }}
                        disabled={disabled}
                    >
                        <SelectTrigger id={id} className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {SCHEDULE_CADENCES.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </FormField>
            <FormField label="Next run">
                {(id) => (
                    <Input
                        id={id}
                        type="date"
                        value={nextRun}
                        onChange={(event) => setNextRun(event.target.value)}
                        required
                        disabled={disabled}
                    />
                )}
            </FormField>
            <FormField label="Line description">
                {(id) => (
                    <Input
                        id={id}
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                        required
                        disabled={disabled}
                    />
                )}
            </FormField>
            <FormField label={`Amount (${agreement.currency})`}>
                {(id) => (
                    <Input
                        id={id}
                        type="number"
                        min="0"
                        step="0.01"
                        value={amount}
                        onChange={(event) => setAmount(event.target.value)}
                        required
                        disabled={disabled}
                    />
                )}
            </FormField>
            <button
                className={cn(buttonClass, 'justify-self-start')}
                disabled={mutation.busy}
            >
                {mutation.retrying ? 'Retry schedule' : 'Add schedule'}
            </button>
            {mutation.error && (
                <p
                    role="alert"
                    className="text-sm wrap-anywhere sm:col-span-2 lg:col-span-5"
                >
                    {mutation.error}
                </p>
            )}
        </form>
    );
}
