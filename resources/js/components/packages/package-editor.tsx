import { PlusIcon, Trash2Icon } from 'lucide-react';
import { useId, useMemo } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { OptionSelect } from '@/components/ui/option-select';
import type { SelectOption } from '@/components/ui/option-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    describeDuration,
    formatDuration,
    parseDuration,
} from '@/lib/duration';
import { statusLabel } from '@/lib/labels';
import { toMinorUnits } from '@/lib/money';
import { cadenceLabel, majorText } from '@/lib/packages';
import type {
    PackageComponentTerms,
    PackageOptions,
    PackageTerms,
    ServicePackage,
} from '@/types/packages';

/**
 * The package editor's form state and its translation to the API's body.
 *
 * Money and hours are typed as people write them ("45", "45.00", "5h 30m") and
 * converted here, once, to the integer minor units and whole minutes the API
 * takes - the server never sees a string it has to interpret.
 */

type ComponentRow = {
    kind: string;
    description: string;
    cadence: string;
    amount: string;
    quantity: string;
    is_taxable: boolean;
};

type QuestionRow = {
    /** Kept when editing so earlier answers stay attached; new rows have none. */
    key: string | null;
    label: string;
    kind: string;
    required: boolean;
};

export type EditorState = {
    name: string;
    link_name: string;
    summary: string;
    body_markdown: string;
    agreement_text: string;
    is_listed: boolean;
    portal_addable: boolean;
    currency: string;
    billing_cadence: string;
    hourly_rate: string;
    retainer_amount: string;
    retainer_hours: string;
    rollover_months: string;
    first_cycle_proration: string | null;
    charge_setup_fee_at_signup: boolean;
    charge_first_period: boolean;
    require_autopay: boolean;
    autopay_limit: string;
    pay_before_activation: boolean;
    payment_lapse_days: string;
    activation_mode: string;
    invoice_issuing: string;
    notice_days: string;
    minimum_term_months: string;
    cancellation_mode: string;
    components: ComponentRow[];
    questions: QuestionRow[];
};

const text = (value: number | null | undefined) =>
    value === null || value === undefined ? '' : String(value);

export function initialState(
    pkg: ServicePackage | null,
    defaults: Partial<PackageTerms> | null,
): EditorState {
    // The draft if there is one; otherwise the published terms (saving then
    // starts a draft from them); otherwise the defaults of a new package.
    const terms: Partial<PackageTerms> =
        pkg?.draft?.terms ?? pkg?.published?.terms ?? defaults ?? {};

    return {
        name: terms.name ?? '',
        link_name: pkg?.link_name ?? '',
        summary: terms.summary ?? '',
        body_markdown: terms.body_markdown ?? '',
        agreement_text: terms.agreement_text ?? '',
        is_listed: pkg?.is_listed ?? true,
        portal_addable: pkg?.portal_addable ?? false,
        currency: terms.currency ?? 'USD',
        billing_cadence: terms.billing_cadence ?? 'monthly',
        hourly_rate: majorText(terms.hourly_rate_amount),
        retainer_amount: majorText(terms.retainer_amount),
        retainer_hours:
            terms.retainer_minutes === null ||
            terms.retainer_minutes === undefined
                ? ''
                : formatDuration(terms.retainer_minutes, 'decimal'),
        rollover_months: text(terms.rollover_months),
        first_cycle_proration: terms.first_cycle_proration ?? null,
        charge_setup_fee_at_signup: terms.charge_setup_fee_at_signup ?? false,
        charge_first_period: terms.charge_first_period ?? false,
        require_autopay: terms.require_autopay ?? false,
        autopay_limit: majorText(terms.autopay_limit_amount),
        pay_before_activation: terms.pay_before_activation ?? false,
        payment_lapse_days: text(terms.payment_lapse_days ?? 14),
        activation_mode: terms.activation_mode ?? 'review',
        invoice_issuing: terms.invoice_issuing ?? 'draft',
        notice_days: text(terms.notice_days ?? 30),
        minimum_term_months: text(terms.minimum_term_months),
        cancellation_mode: terms.cancellation_mode ?? 'request',
        components: (terms.components ?? []).map(
            (component: PackageComponentTerms) => ({
                kind: component.kind,
                description: component.description,
                cadence: component.cadence,
                amount: majorText(component.amount),
                quantity: String(Number.parseFloat(component.quantity)),
                is_taxable: component.is_taxable,
            }),
        ),
        questions: (terms.intake_questions ?? []).map((question) => ({
            key: question.key,
            label: question.label,
            kind: question.kind,
            required: question.required,
        })),
    };
}

export type BuiltBody = {
    body: Record<string, unknown>;
    /** Field-level problems in what was typed, keyed like the server's. */
    errors: Record<string, string>;
};

/** What a person typed, as the API's body. Nothing is sent when it has errors. */
export function buildBody(state: EditorState): BuiltBody {
    const errors: Record<string, string> = {};

    const money = (value: string, key: string): number | null => {
        if (value.trim() === '') {
            return null;
        }

        const minor = toMinorUnits(value);

        if (minor === null || minor < 0) {
            errors[key] = 'Enter an amount such as 45.00.';

            return null;
        }

        return minor;
    };
    const whole = (value: string, key: string): number | null => {
        if (value.trim() === '') {
            return null;
        }

        const number = Number(value);

        if (!Number.isInteger(number) || number < 0) {
            errors[key] = 'Enter a whole number.';

            return null;
        }

        return number;
    };

    let retainerMinutes: number | null = null;

    if (state.retainer_hours.trim() !== '') {
        retainerMinutes = parseDuration(state.retainer_hours);

        if (retainerMinutes === null) {
            errors.retainer_minutes = 'Enter hours such as 5, 5h or 5h 30m.';
        }
    }

    const components = state.components.map((row, index) => {
        const quantity =
            row.quantity.trim() === '' ? 1 : Number(row.quantity.trim());

        if (!Number.isFinite(quantity) || quantity <= 0) {
            errors[`components.${index}.quantity`] =
                'Enter a quantity above zero.';
        }

        return {
            kind: row.kind,
            description: row.description,
            // A setup fee is charged once whatever the row says.
            cadence: row.kind === 'setup_fee' ? 'one_time' : row.cadence,
            amount: money(row.amount, `components.${index}.amount`) ?? 0,
            quantity,
            is_taxable: row.is_taxable,
        };
    });

    const body: Record<string, unknown> = {
        link_name: state.link_name.trim(),
        is_listed: state.is_listed,
        portal_addable: state.portal_addable,
        name: state.name,
        summary: state.summary.trim() === '' ? null : state.summary,
        body_markdown:
            state.body_markdown.trim() === '' ? null : state.body_markdown,
        agreement_text:
            state.agreement_text.trim() === '' ? null : state.agreement_text,
        currency: state.currency.trim().toUpperCase(),
        billing_cadence: state.billing_cadence,
        hourly_rate_amount: money(state.hourly_rate, 'hourly_rate_amount'),
        retainer_amount: money(state.retainer_amount, 'retainer_amount'),
        retainer_minutes: retainerMinutes,
        rollover_months: whole(state.rollover_months, 'rollover_months'),
        first_cycle_proration: state.first_cycle_proration,
        charge_setup_fee_at_signup: state.charge_setup_fee_at_signup,
        charge_first_period: state.charge_first_period,
        require_autopay: state.require_autopay,
        autopay_limit_amount: state.require_autopay
            ? money(state.autopay_limit, 'autopay_limit_amount')
            : null,
        pay_before_activation: state.pay_before_activation,
        payment_lapse_days:
            whole(state.payment_lapse_days, 'payment_lapse_days') ?? 14,
        activation_mode: state.activation_mode,
        invoice_issuing: state.invoice_issuing,
        notice_days: whole(state.notice_days, 'notice_days') ?? 0,
        minimum_term_months: whole(
            state.minimum_term_months,
            'minimum_term_months',
        ),
        cancellation_mode: state.cancellation_mode,
        components,
        intake_questions: state.questions.map((question) => ({
            ...(question.key === null ? {} : { key: question.key }),
            label: question.label,
            kind: question.kind,
            required: question.required,
        })),
    };

    return { body, errors };
}

const ACTIVATION_LABELS: Record<string, string> = {
    automatic: 'Start automatically once accepted (and paid, if required)',
    review: 'I review first, then activate it myself',
};
const INVOICE_LABELS: Record<string, string> = {
    automatic: 'Issue and charge automatically',
    draft: 'Create drafts for me to issue',
};
const CANCELLATION_LABELS: Record<string, string> = {
    none: 'Clients cannot cancel in the portal',
    request: 'Client requests, I approve',
    self_serve: 'Client cancels in the portal',
};
const KIND_LABELS: Record<string, string> = {
    recurring: 'Recurring charge',
    setup_fee: 'Setup fee',
};
const QUESTION_LABELS: Record<string, string> = {
    short_text: 'Short text',
    paragraph: 'Paragraph',
};

function choices(
    values: string[],
    labels: Record<string, string>,
    fallback: (value: string) => string = statusLabel,
): SelectOption[] {
    return values.map((value) => ({
        value,
        label: labels[value] ?? fallback(value),
    }));
}

function Toggle({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const hintId = useId();

    return (
        <label className="flex min-h-8 items-start gap-3 text-sm pointer-coarse:min-h-11">
            <Switch
                checked={checked}
                onCheckedChange={onChange}
                aria-describedby={hint === undefined ? undefined : hintId}
                className="mt-0.5"
            />
            <span className="grid min-w-0 grid-cols-1 gap-0.5">
                <span>{label}</span>
                {hint !== undefined && (
                    <span
                        id={hintId}
                        className="text-xs wrap-anywhere text-muted-foreground"
                    >
                        {hint}
                    </span>
                )}
            </span>
        </label>
    );
}

/** The editor's cards. State lives in the page; this only draws and reports edits. */
export function PackageEditorFields({
    state,
    onChange,
    options,
    errors,
}: {
    state: EditorState;
    onChange: (next: EditorState) => void;
    options: PackageOptions;
    errors: Record<string, string>;
}) {
    const set = <K extends keyof EditorState>(key: K, value: EditorState[K]) =>
        onChange({ ...state, [key]: value });

    const cadenceOptions = useMemo(
        () => choices(options.billing_cadences, {}, cadenceLabel),
        [options.billing_cadences],
    );
    const kindOptions = choices(options.component_kinds, KIND_LABELS);
    const questionOptions = choices(options.question_kinds, QUESTION_LABELS);

    const usage =
        state.hourly_rate.trim() !== '' ||
        (toMinorUnits(state.retainer_amount) ?? 0) > 0 ||
        (parseDuration(state.retainer_hours) ?? 0) > 0;
    const autoIssueBlocked = usage || state.billing_cadence === 'one_time';
    const invoiceOptions = choices(options.invoice_issuing, INVOICE_LABELS).map(
        (option) => ({
            ...option,
            disabled: option.value === 'automatic' && autoIssueBlocked,
        }),
    );

    const updateComponent = (index: number, patch: Partial<ComponentRow>) =>
        set(
            'components',
            state.components.map((row, at) =>
                at === index ? { ...row, ...patch } : row,
            ),
        );
    const updateQuestion = (index: number, patch: Partial<QuestionRow>) =>
        set(
            'questions',
            state.questions.map((row, at) =>
                at === index ? { ...row, ...patch } : row,
            ),
        );

    return (
        <div className="grid grid-cols-1 gap-6">
            <Card>
                <CardHeader>
                    <CardTitle>Public page</CardTitle>
                    <CardDescription>
                        What a visitor reads before asking for this service.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-4">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label="Name" error={errors.name}>
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    value={state.name}
                                    onChange={(event) =>
                                        set('name', event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Link name"
                            hint="Lower-case words and numbers joined by hyphens. It ends the package's own address."
                            error={errors.link_name}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    value={state.link_name}
                                    onChange={(event) =>
                                        set('link_name', event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                    </div>
                    <FormField
                        label="Short description"
                        hint="One line, shown in the catalog."
                        error={errors.summary}
                    >
                        {(id, aria) => (
                            <Input
                                id={id}
                                {...aria}
                                value={state.summary}
                                onChange={(event) =>
                                    set('summary', event.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="What's included"
                        hint="One point per line, starting with a dash."
                        error={errors.body_markdown}
                    >
                        {(id, aria) => (
                            <Textarea
                                id={id}
                                {...aria}
                                rows={5}
                                value={state.body_markdown}
                                onChange={(event) =>
                                    set('body_markdown', event.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <div className="grid grid-cols-1 gap-2">
                        <Toggle
                            label="Listed on the catalog page"
                            hint="Unlisted packages are reachable only by their own link."
                            checked={state.is_listed}
                            onChange={(value) => set('is_listed', value)}
                        />
                        <Toggle
                            label="Existing clients can add it from their portal"
                            checked={state.portal_addable}
                            onChange={(value) => set('portal_addable', value)}
                        />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>What the client pays</CardTitle>
                    <CardDescription>
                        Amounts are in the package currency.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-4">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label="Currency" error={errors.currency}>
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    maxLength={3}
                                    className="max-w-32 uppercase"
                                    value={state.currency}
                                    onChange={(event) =>
                                        set('currency', event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Billing cadence"
                            error={errors.billing_cadence}
                        >
                            {(id) => (
                                <OptionSelect
                                    id={id}
                                    value={state.billing_cadence}
                                    options={cadenceOptions}
                                    onValueChange={(value) =>
                                        set('billing_cadence', value)
                                    }
                                />
                            )}
                        </FormField>
                    </div>

                    <div className="grid grid-cols-1 gap-3">
                        <h3 className="text-sm font-medium">Components</h3>
                        {state.components.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No components yet. Add the recurring charge and
                                any setup fee, or use a retainer or hourly rate
                                below.
                            </p>
                        )}
                        {state.components.map((row, index) => (
                            <fieldset
                                key={index}
                                className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border border-border p-3"
                            >
                                <legend className="px-1 text-xs text-muted-foreground">
                                    Component {index + 1}
                                </legend>
                                <FormField
                                    label="Description"
                                    error={
                                        errors[
                                            `components.${index}.description`
                                        ]
                                    }
                                >
                                    {(id, aria) => (
                                        <Input
                                            id={id}
                                            {...aria}
                                            aria-label={`Component ${index + 1} description`}
                                            value={row.description}
                                            onChange={(event) =>
                                                updateComponent(index, {
                                                    description:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    )}
                                </FormField>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <FormField label="Kind">
                                        {(id) => (
                                            <OptionSelect
                                                id={id}
                                                aria-label={`Component ${index + 1} kind`}
                                                value={row.kind}
                                                options={kindOptions}
                                                onValueChange={(value) =>
                                                    updateComponent(index, {
                                                        kind: value,
                                                    })
                                                }
                                            />
                                        )}
                                    </FormField>
                                    {row.kind !== 'setup_fee' && (
                                        <FormField
                                            label="Cadence"
                                            error={
                                                errors[
                                                    `components.${index}.cadence`
                                                ]
                                            }
                                        >
                                            {(id) => (
                                                <OptionSelect
                                                    id={id}
                                                    aria-label={`Component ${index + 1} cadence`}
                                                    value={row.cadence}
                                                    options={cadenceOptions}
                                                    onValueChange={(value) =>
                                                        updateComponent(index, {
                                                            cadence: value,
                                                        })
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    )}
                                    <FormField
                                        label="Amount"
                                        error={
                                            errors[`components.${index}.amount`]
                                        }
                                    >
                                        {(id, aria) => (
                                            <Input
                                                id={id}
                                                {...aria}
                                                aria-label={`Component ${index + 1} amount`}
                                                inputMode="decimal"
                                                value={row.amount}
                                                onChange={(event) =>
                                                    updateComponent(index, {
                                                        amount: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        label="Quantity"
                                        error={
                                            errors[
                                                `components.${index}.quantity`
                                            ]
                                        }
                                    >
                                        {(id, aria) => (
                                            <Input
                                                id={id}
                                                {...aria}
                                                aria-label={`Component ${index + 1} quantity`}
                                                inputMode="decimal"
                                                value={row.quantity}
                                                onChange={(event) =>
                                                    updateComponent(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </FormField>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Toggle
                                        label="Taxable"
                                        checked={row.is_taxable}
                                        onChange={(value) =>
                                            updateComponent(index, {
                                                is_taxable: value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11 md:min-h-0"
                                        aria-label={`Remove component ${index + 1}`}
                                        onClick={() =>
                                            set(
                                                'components',
                                                state.components.filter(
                                                    (_, at) => at !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2Icon aria-hidden />
                                        Remove
                                    </Button>
                                </div>
                            </fieldset>
                        ))}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 md:min-h-0"
                                onClick={() =>
                                    set('components', [
                                        ...state.components,
                                        {
                                            kind: 'recurring',
                                            description: '',
                                            cadence: state.billing_cadence,
                                            amount: '',
                                            quantity: '1',
                                            is_taxable: false,
                                        },
                                    ])
                                }
                            >
                                <PlusIcon aria-hidden />
                                Add component
                            </Button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField
                            label="Hourly rate"
                            hint="For extra hours. Leave empty if none are sold."
                            error={errors.hourly_rate_amount}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    inputMode="decimal"
                                    value={state.hourly_rate}
                                    onChange={(event) =>
                                        set('hourly_rate', event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Retainer amount"
                            error={errors.retainer_amount}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    inputMode="decimal"
                                    value={state.retainer_amount}
                                    onChange={(event) =>
                                        set(
                                            'retainer_amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Retainer hours"
                            hint={
                                describeDuration(
                                    state.retainer_hours,
                                    'decimal',
                                ) ??
                                'Hours included each cycle, such as 5 or 5h 30m.'
                            }
                            error={errors.retainer_minutes}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    value={state.retainer_hours}
                                    onChange={(event) =>
                                        set(
                                            'retainer_hours',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Rollover months"
                            hint="How long unused hours carry over."
                            error={errors.rollover_months}
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    inputMode="numeric"
                                    className="max-w-32"
                                    value={state.rollover_months}
                                    onChange={(event) =>
                                        set(
                                            'rollover_months',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Questions on the request form</CardTitle>
                    <CardDescription>
                        What you need to know before you can say yes.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-3">
                    {state.questions.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No questions yet.
                        </p>
                    )}
                    {state.questions.map((row, index) => (
                        <fieldset
                            key={index}
                            className="grid min-w-0 grid-cols-1 gap-3 rounded-lg border border-border p-3"
                        >
                            <legend className="px-1 text-xs text-muted-foreground">
                                Question {index + 1}
                            </legend>
                            <FormField
                                label="Question"
                                error={
                                    errors[`intake_questions.${index}.label`]
                                }
                            >
                                {(id, aria) => (
                                    <Input
                                        id={id}
                                        {...aria}
                                        aria-label={`Question ${index + 1} text`}
                                        value={row.label}
                                        onChange={(event) =>
                                            updateQuestion(index, {
                                                label: event.target.value,
                                            })
                                        }
                                    />
                                )}
                            </FormField>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <FormField label="Answer">
                                    {(id) => (
                                        <OptionSelect
                                            id={id}
                                            aria-label={`Question ${index + 1} answer`}
                                            value={row.kind}
                                            options={questionOptions}
                                            onValueChange={(value) =>
                                                updateQuestion(index, {
                                                    kind: value,
                                                })
                                            }
                                        />
                                    )}
                                </FormField>
                                <div className="flex flex-wrap items-end justify-between gap-2">
                                    <Toggle
                                        label="Required"
                                        checked={row.required}
                                        onChange={(value) =>
                                            updateQuestion(index, {
                                                required: value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11 md:min-h-0"
                                        aria-label={`Remove question ${index + 1}`}
                                        onClick={() =>
                                            set(
                                                'questions',
                                                state.questions.filter(
                                                    (_, at) => at !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2Icon aria-hidden />
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        </fieldset>
                    ))}
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 md:min-h-0"
                            onClick={() =>
                                set('questions', [
                                    ...state.questions,
                                    {
                                        key: null,
                                        label: '',
                                        kind: 'short_text',
                                        required: false,
                                    },
                                ])
                            }
                        >
                            <PlusIcon aria-hidden />
                            Add question
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Agreement text</CardTitle>
                    <CardDescription>
                        Copied onto each client&rsquo;s agreement at acceptance,
                        with the package version.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <FormField
                        label="Agreement text"
                        error={errors.agreement_text}
                    >
                        {(id, aria) => (
                            <Textarea
                                id={id}
                                {...aria}
                                rows={6}
                                value={state.agreement_text}
                                onChange={(event) =>
                                    set('agreement_text', event.target.value)
                                }
                            />
                        )}
                    </FormField>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>At sign-up</CardTitle>
                    <CardDescription>
                        Anything charged now needs a Stripe connection to
                        publish.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-4">
                    <Toggle
                        label="Charge the setup fee by card"
                        checked={state.charge_setup_fee_at_signup}
                        onChange={(value) =>
                            set('charge_setup_fee_at_signup', value)
                        }
                    />
                    <Toggle
                        label="Charge the first period in advance"
                        checked={state.charge_first_period}
                        onChange={(value) => set('charge_first_period', value)}
                    />
                    <Toggle
                        label="Require a saved card with autopay"
                        checked={state.require_autopay}
                        onChange={(value) => set('require_autopay', value)}
                    />
                    {state.require_autopay && (
                        <FormField
                            label="Autopay limit per invoice"
                            hint="The client authorizes exactly this limit."
                            error={errors.autopay_limit_amount}
                            className="md:max-w-xs"
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    inputMode="decimal"
                                    value={state.autopay_limit}
                                    onChange={(event) =>
                                        set('autopay_limit', event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                    )}
                    <Toggle
                        label="Start service only once paid"
                        checked={state.pay_before_activation}
                        onChange={(value) =>
                            set('pay_before_activation', value)
                        }
                    />
                    {state.pay_before_activation && (
                        <FormField
                            label="Unpaid sign-ups lapse after (days)"
                            error={errors.payment_lapse_days}
                            className="md:max-w-xs"
                        >
                            {(id, aria) => (
                                <Input
                                    id={id}
                                    {...aria}
                                    inputMode="numeric"
                                    value={state.payment_lapse_days}
                                    onChange={(event) =>
                                        set(
                                            'payment_lapse_days',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    )}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField
                            label="When the client accepts"
                            error={errors.activation_mode}
                        >
                            {(id) => (
                                <OptionSelect
                                    id={id}
                                    value={state.activation_mode}
                                    options={choices(
                                        options.activation_modes,
                                        ACTIVATION_LABELS,
                                    )}
                                    onValueChange={(value) =>
                                        set('activation_mode', value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Recurring invoices"
                            hint={
                                autoIssueBlocked
                                    ? 'Automatic issuing is for fixed-price recurring packages. This one bills hours or is one-time.'
                                    : 'Autopay charges each invoice on its due date once issued.'
                            }
                            error={errors.invoice_issuing}
                        >
                            {(id) => (
                                <OptionSelect
                                    id={id}
                                    value={state.invoice_issuing}
                                    options={invoiceOptions}
                                    onValueChange={(value) =>
                                        set('invoice_issuing', value)
                                    }
                                />
                            )}
                        </FormField>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Cancellation</CardTitle>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FormField label="Notice (days)" error={errors.notice_days}>
                        {(id, aria) => (
                            <Input
                                id={id}
                                {...aria}
                                inputMode="numeric"
                                className="max-w-32"
                                value={state.notice_days}
                                onChange={(event) =>
                                    set('notice_days', event.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Minimum term (months)"
                        hint="Leave empty for none."
                        error={errors.minimum_term_months}
                    >
                        {(id, aria) => (
                            <Input
                                id={id}
                                {...aria}
                                inputMode="numeric"
                                className="max-w-32"
                                value={state.minimum_term_months}
                                onChange={(event) =>
                                    set(
                                        'minimum_term_months',
                                        event.target.value,
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Who ends a service"
                        error={errors.cancellation_mode}
                        className="md:col-span-2"
                    >
                        {(id) => (
                            <OptionSelect
                                id={id}
                                value={state.cancellation_mode}
                                options={choices(
                                    options.cancellation_modes,
                                    CANCELLATION_LABELS,
                                )}
                                onValueChange={(value) =>
                                    set('cancellation_mode', value)
                                }
                            />
                        )}
                    </FormField>
                </CardContent>
            </Card>
        </div>
    );
}
