import { router } from '@inertiajs/react';
import { PencilIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { AgreementTerms } from '@/types/agreement';

/**
 * Correcting an agreement, in the two sizes the job actually comes in.
 *
 * The small one is a name. Nine of the imported agreements arrived titled
 * "Legacy Agreement", and fixing that should not mean opening a form with
 * seventeen fields on it and pressing Save on sixteen values nobody looked at.
 * So the pencil beside the title sends a title and nothing else, and the
 * request treats every field as optional — what is not sent is not written.
 *
 * The large one is the terms themselves, and it edits the *stored* columns
 * rather than the derived figures the read view shows. `retainer_minutes` is
 * what one month grants; `period_retainer_minutes` is a whole-cycle override
 * that six of the nine source agreements set. The summary collapses them into
 * one per-period number, which is the right thing to read and an impossible
 * thing to write back — two different pairs of columns produce it.
 *
 * Every money and hours field converts here, once. The columns hold minor units
 * and whole minutes; an operator types dollars and hours. Blank means null —
 * the term is unstated — and null is not zero anywhere in the billing engine:
 * an unstated rate makes the rate lookup refuse, while a zero prices the work
 * at nothing and says so on the client's invoice.
 */

const CADENCES = [
    { value: 'one_time', label: 'One time' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'semi_annual', label: 'Semi-annual' },
    { value: 'annual', label: 'Annual' },
] as const;

const UNSET = 'unset';

const PRORATIONS = [
    { value: UNSET, label: 'Not stated — prorates the opening month' },
    { value: 'prorate_hours', label: 'Prorate hours' },
    { value: 'full_period', label: 'Full period' },
    { value: 'align_next_cycle', label: 'Align to the next cycle' },
] as const;

const INTERIM = [
    { value: UNSET, label: 'Not stated — not billed mid-cycle' },
    { value: 'yes', label: 'Billed mid-cycle' },
    { value: 'no', label: 'Not billed mid-cycle' },
] as const;

/** Minor units → the decimal an operator types. Blank for an unstated term. */
function money(amount: number | null): string {
    return amount === null ? '' : (amount / 100).toFixed(2);
}

/** Whole minutes → decimal hours. Blank for an unstated term. */
function hours(minutes: number | null): string {
    return minutes === null ? '' : String(minutes / 60);
}

/**
 * A typed decimal back into the column's unit.
 *
 * Blank is null, and null is the term being unstated rather than zero. Anything
 * that is neither is refused rather than coerced: `Number('')` is 0, and a
 * field that turns a typo into a zero on a rate is how work gets billed at
 * nothing.
 */
function scaled(value: string, factor: number): number | null | 'invalid' {
    const text = value.trim();

    if (text === '') {
        return null;
    }

    const parsed = Number.parseFloat(text);

    return Number.isFinite(parsed) && parsed >= 0
        ? Math.round(parsed * factor)
        : 'invalid';
}

function whole(value: string): number | null | 'invalid' {
    return scaled(value, 1);
}

/** The pencil: rename and nothing else. */
export function AgreementTitle({
    agreement,
    updateHref,
}: {
    agreement: AgreementTerms;
    updateHref: string | null;
}) {
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(agreement.title);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (updateHref === null || !editing) {
        return (
            <>
                <h1 className="text-2xl font-semibold wrap-anywhere">
                    {agreement.title}
                </h1>
                {updateHref !== null && (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Rename this agreement"
                        onClick={() => {
                            setTitle(agreement.title);
                            setError(null);
                            setEditing(true);
                        }}
                    >
                        <PencilIcon className="size-4" aria-hidden="true" />
                    </Button>
                )}
            </>
        );
    }

    const save = () => {
        if (busy) {
            return;
        }

        setBusy(true);
        router.patch(
            updateHref,
            { title },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setError(null);
                    setEditing(false);
                },
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'That name could not be saved.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <form
            className="grid w-full grid-cols-1 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
        >
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    // Focused on open: the control the reader just clicked is
                    // the one they mean to type in, and without this renaming
                    // takes a click and then a tab.
                    autoFocus
                    aria-label="Agreement name"
                    className="max-w-md"
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setEditing(false);
                        }
                    }}
                />
                <Button type="submit" size="sm" disabled={busy}>
                    Save
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setEditing(false)}
                >
                    Cancel
                </Button>
            </div>
            {error !== null && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </form>
    );
}

/** One labelled control, sized to what goes in it. */
function Field({
    id,
    label,
    hint,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-1 gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint !== undefined && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

/** The whole terms form, for the corrections a rename cannot make. */
export function AgreementTermsForm({
    agreement,
    updateHref,
    onDone,
}: {
    agreement: AgreementTerms;
    updateHref: string;
    onDone: () => void;
}) {
    const [form, setForm] = useState({
        starts_on: agreement.starts_on,
        ends_on: agreement.ends_on ?? '',
        billing_cadence: agreement.billing_cadence ?? 'monthly',
        currency: agreement.currency ?? 'USD',
        hourly_rate: money(agreement.hourly_rate_amount),
        retainer_hours: hours(agreement.retainer_minutes),
        retainer_fee: money(agreement.retainer_amount),
        period_retainer_hours: hours(agreement.period_retainer_minutes),
        period_retainer_fee: money(agreement.period_retainer_amount),
        catch_up_hours: hours(agreement.catch_up_threshold_minutes),
        rollover_months:
            agreement.rollover_months === null
                ? ''
                : String(agreement.rollover_months),
        rollover_policy: agreement.rollover_policy ?? '',
        first_cycle_proration: agreement.first_cycle_proration ?? UNSET,
        bill_overage_interim:
            agreement.bill_overage_interim === null
                ? UNSET
                : agreement.bill_overage_interim
                  ? 'yes'
                  : 'no',
        agreement_text: agreement.agreement_text ?? '',
        is_visible_to_client: agreement.is_visible_to_client === true,
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const set = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((current) => ({ ...current, [key]: value }));

    const submit = () => {
        if (busy) {
            return;
        }

        const numbers = {
            hourly_rate_amount: scaled(form.hourly_rate, 100),
            retainer_minutes: scaled(form.retainer_hours, 60),
            retainer_amount: scaled(form.retainer_fee, 100),
            period_retainer_minutes: scaled(form.period_retainer_hours, 60),
            period_retainer_amount: scaled(form.period_retainer_fee, 100),
            catch_up_threshold_minutes: scaled(form.catch_up_hours, 60),
            rollover_months: whole(form.rollover_months),
        };

        const bad = Object.entries(numbers).find(
            ([, value]) => value === 'invalid',
        );

        if (bad !== undefined) {
            setError(
                'One of the numbers is not a number. Leave a term blank to say it is unstated.',
            );

            return;
        }

        setBusy(true);
        router.patch(
            updateHref,
            {
                ...(numbers as Record<string, number | null>),
                starts_on: form.starts_on,
                ends_on: form.ends_on === '' ? null : form.ends_on,
                billing_cadence: form.billing_cadence,
                currency: form.currency.toUpperCase(),
                rollover_policy:
                    form.rollover_policy.trim() === ''
                        ? null
                        : form.rollover_policy.trim(),
                first_cycle_proration:
                    form.first_cycle_proration === UNSET
                        ? null
                        : form.first_cycle_proration,
                bill_overage_interim:
                    form.bill_overage_interim === UNSET
                        ? null
                        : form.bill_overage_interim === 'yes',
                agreement_text:
                    form.agreement_text === '' ? null : form.agreement_text,
                is_visible_to_client: form.is_visible_to_client,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setError(null);
                    onDone();
                },
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Those terms could not be saved.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <form
            className="grid grid-cols-1 gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Field id="starts-on" label="Starts on">
                    <Input
                        id="starts-on"
                        type="date"
                        value={form.starts_on}
                        onChange={(event) =>
                            set('starts_on', event.target.value)
                        }
                    />
                </Field>
                <Field id="ends-on" label="Ends on" hint="Blank leaves it open">
                    <Input
                        id="ends-on"
                        type="date"
                        value={form.ends_on}
                        onChange={(event) => set('ends_on', event.target.value)}
                    />
                </Field>
                <Field id="cadence" label="Billing cadence">
                    <Select
                        value={form.billing_cadence}
                        onValueChange={(next) => {
                            if (typeof next === 'string') {
                                set('billing_cadence', next);
                            }
                        }}
                    >
                        <SelectTrigger id="cadence" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CADENCES.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field id="currency" label="Currency">
                    <Input
                        id="currency"
                        maxLength={3}
                        className="uppercase"
                        value={form.currency}
                        onChange={(event) =>
                            set('currency', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="hourly-rate"
                    label="Hourly rate"
                    hint="Blank means unpriced — the rate lookup refuses rather than billing at nothing"
                >
                    <Input
                        id="hourly-rate"
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={form.hourly_rate}
                        onChange={(event) =>
                            set('hourly_rate', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="rollover-months"
                    label="Rollover months"
                    hint="Blank means nothing carries forward"
                >
                    <Input
                        id="rollover-months"
                        type="number"
                        min="0"
                        step="1"
                        value={form.rollover_months}
                        onChange={(event) =>
                            set('rollover_months', event.target.value)
                        }
                    />
                </Field>

                <Field
                    id="retainer-hours"
                    label="Retainer hours per month"
                    hint="What one calendar month grants"
                >
                    <Input
                        id="retainer-hours"
                        type="number"
                        min="0"
                        step="0.25"
                        inputMode="decimal"
                        value={form.retainer_hours}
                        onChange={(event) =>
                            set('retainer_hours', event.target.value)
                        }
                    />
                </Field>
                <Field id="retainer-fee" label="Retainer fee per month">
                    <Input
                        id="retainer-fee"
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={form.retainer_fee}
                        onChange={(event) =>
                            set('retainer_fee', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="catch-up-hours"
                    label="Catch-up threshold"
                    hint="Blank defaults to one hour, capped at the retainer"
                >
                    <Input
                        id="catch-up-hours"
                        type="number"
                        min="0"
                        step="0.25"
                        inputMode="decimal"
                        value={form.catch_up_hours}
                        onChange={(event) =>
                            set('catch_up_hours', event.target.value)
                        }
                    />
                </Field>

                <Field
                    id="period-retainer-hours"
                    label="Retainer hours per cycle"
                    hint="Overrides the monthly figure for the whole billing cycle"
                >
                    <Input
                        id="period-retainer-hours"
                        type="number"
                        min="0"
                        step="0.25"
                        inputMode="decimal"
                        value={form.period_retainer_hours}
                        onChange={(event) =>
                            set('period_retainer_hours', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="period-retainer-fee"
                    label="Retainer fee per cycle"
                    hint="Overrides the monthly fee for the whole billing cycle"
                >
                    <Input
                        id="period-retainer-fee"
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={form.period_retainer_fee}
                        onChange={(event) =>
                            set('period_retainer_fee', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="rollover-policy"
                    label="Rollover policy"
                    hint="A note on this record — the billing engine reads rollover months, not this"
                >
                    <Input
                        id="rollover-policy"
                        value={form.rollover_policy}
                        onChange={(event) =>
                            set('rollover_policy', event.target.value)
                        }
                    />
                </Field>

                <Field id="first-cycle" label="First cycle">
                    <Select
                        value={form.first_cycle_proration}
                        onValueChange={(next) => {
                            if (typeof next === 'string') {
                                set('first_cycle_proration', next);
                            }
                        }}
                    >
                        <SelectTrigger id="first-cycle" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PRORATIONS.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field id="interim-overage" label="Interim overage">
                    <Select
                        value={form.bill_overage_interim}
                        onValueChange={(next) => {
                            if (typeof next === 'string') {
                                set('bill_overage_interim', next);
                            }
                        }}
                    >
                        <SelectTrigger id="interim-overage" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {INTERIM.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <div className="flex items-center gap-3 self-end pb-2">
                    <Switch
                        id="visible-to-client"
                        checked={form.is_visible_to_client}
                        onCheckedChange={(checked: boolean) =>
                            set('is_visible_to_client', checked)
                        }
                    />
                    <Label htmlFor="visible-to-client">Visible to client</Label>
                </div>
            </div>

            <Field
                id="agreement-text"
                label="Agreement text"
                hint="What was agreed, in words. The client reads this when the agreement is visible to them."
            >
                <Textarea
                    id="agreement-text"
                    rows={8}
                    value={form.agreement_text}
                    onChange={(event) =>
                        set('agreement_text', event.target.value)
                    }
                />
            </Field>

            {error !== null && (
                <p
                    role="alert"
                    className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                    {error}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={busy}>
                    Save terms
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/** Bytes as something a person reads, at the precision that tells them enough. */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`;
}

/**
 * Adding a file to the record.
 *
 * Multipart, so `forceFormData` — Inertia sends JSON otherwise and the file
 * arrives as an empty object. The input is reset after each upload because a
 * file input that still names the file you just uploaded reads as one that has
 * not finished.
 */
export function AgreementFileUpload({ uploadHref }: { uploadHref: string }) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="grid grid-cols-1 gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    ref={input}
                    type="file"
                    aria-label="File to attach"
                    className="max-w-sm"
                    onChange={() => setError(null)}
                />
                <Button
                    type="button"
                    size="sm"
                    disabled={busy}
                    onClick={() => {
                        const file = input.current?.files?.[0];

                        if (file === undefined) {
                            setError('Choose a file first.');

                            return;
                        }

                        setBusy(true);
                        router.post(
                            uploadHref,
                            { file },
                            {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => {
                                    setError(null);

                                    if (input.current !== null) {
                                        input.current.value = '';
                                    }
                                },
                                onError: (errors) =>
                                    setError(
                                        Object.values(errors)[0] ??
                                            'That file could not be uploaded.',
                                    ),
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    Upload
                </Button>
            </div>
            {error !== null && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
