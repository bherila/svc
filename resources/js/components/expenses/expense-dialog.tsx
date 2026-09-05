import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { todayIn } from '@/lib/time';
import type { ClientExpenseRow, ExpenseProject } from '@/types/expenses';

/** Sentinel for "no project"; project ids are uuids, so it collides with none. */
const NO_PROJECT = '__no_project__';

type Draft = {
    spent_on: string;
    /** What the operator typed, in major units. Converted on submit. */
    amount: string;
    currency: string;
    description: string;
    project_id: string;
};

/**
 * Read what a person typed as a whole number of minor units.
 *
 * The column holds minor units and the domain refuses anything that is not a
 * positive integer of them, so the conversion happens once, here, at the edge
 * where a person's "12.50" becomes 1250. Doing it in the controller would mean
 * accepting both shapes over HTTP and guessing which arrived.
 *
 * ## A comma is refused, never stripped
 *
 * Stripping every comma read "12,50" - a decimal comma across most of Europe -
 * as 1250, and posted 125000 minor units: a hundredfold overstatement of a
 * real expense, valid on arrival and billable later. It also accepted "1,2,3".
 *
 * Guessing the intent from the shape is not better, because "1,250" is genuinely
 * ambiguous between one thousand two hundred fifty and one and a quarter. So a
 * comma is only removed where it is unambiguously a thousands separator -
 * groups of exactly three - and anything else is refused for the person to
 * retype. A refusal costs one correction; the alternative costs a client an
 * invoice a hundred times too large.
 *
 * Returns null rather than a guess when the text says nothing usable, so the
 * caller can leave the field alone instead of posting a zero.
 */
export function parseAmount(input: string): number | null {
    const text = input.trim();

    // Plain, or grouped in threes. Anything else carrying a comma is ambiguous.
    const grouped = /^\d{1,3}(,\d{3})+(\.\d{1,2})?$/.test(text);

    if (text.includes(',') && !grouped) {
        return null;
    }

    const bare = grouped ? text.replace(/,/g, '') : text;

    if (!/^\d+(\.\d{1,2})?$/.test(bare)) {
        return null;
    }

    const minor = Math.round(Number(bare) * 100);

    return minor > 0 ? minor : null;
}

function draftFrom(
    expense: ClientExpenseRow | null,
    workspace: { timezone: string; default_currency: string },
): Draft {
    if (expense === null) {
        return {
            // The workspace's calendar, not the browser's and not UTC's.
            // `toISOString()` formats in UTC, so an operator west of it gets
            // tomorrow's date all evening - silently, on the field that decides
            // which period the expense lands in.
            spent_on: todayIn(workspace.timezone),
            amount: '',
            currency: workspace.default_currency,
            description: '',
            project_id: NO_PROJECT,
        };
    }

    return {
        spent_on: expense.spent_on,
        amount: (expense.amount / 100).toFixed(2),
        currency: expense.currency,
        description: expense.description,
        project_id: expense.project?.id ?? NO_PROJECT,
    };
}

/**
 * Record a new expense, or rewrite a draft one.
 *
 * One dialog for both, because the server takes one shape for both: an edit is
 * checked by the same constructor a new expense is, so a form that submitted a
 * patch of changed fields would be the only place the two shapes differed.
 *
 * The project select is offered only where the client has projects. Attribution
 * is optional — an expense belongs to the company — so its absence is a real
 * answer rather than an unfilled field, and the clearing option says so.
 */
export function ExpenseDialog({
    open,
    onOpenChange,
    storeUrl,
    workspace,
    projects,
    expense,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeUrl: string;
    workspace: { timezone: string; default_currency: string };
    projects: ExpenseProject[];
    expense: ClientExpenseRow | null;
}) {
    // Initial state only. Reopening on a different row has to show that row,
    // and reopening on "record" has to show an empty form rather than the last
    // thing edited - the caller remounts this on the expense's id to get that,
    // rather than resetting from an effect, which costs a second render of a
    // form the operator is already typing into.
    const [draft, setDraft] = useState<Draft>(() =>
        draftFrom(expense, workspace),
    );
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const submit = () => {
        const amount = parseAmount(draft.amount);

        if (amount === null) {
            setError('Enter an amount greater than zero, like 12.50.');

            return;
        }

        if (draft.description.trim() === '') {
            setError('Describe what the expense was for.');

            return;
        }

        const payload = {
            spent_on: draft.spent_on,
            amount,
            currency: draft.currency,
            description: draft.description,
            project_id:
                draft.project_id === NO_PROJECT ? null : draft.project_id,
        };

        setSaving(true);

        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errors: Record<string, string>) =>
                setError(
                    Object.values(errors)[0] ??
                        'That expense could not be saved.',
                ),
            onFinish: () => setSaving(false),
        };

        if (expense === null) {
            router.post(storeUrl, payload, options);
        } else {
            router.patch(expense.urls.update, payload, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {expense === null ? 'Record expense' : 'Edit expense'}
                    </DialogTitle>
                    <DialogDescription>
                        Reimbursable costs reach the client&rsquo;s invoice at
                        cost once a manager approves them.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-4">
                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="expense-spent-on">Date</Label>
                        <Input
                            id="expense-spent-on"
                            type="date"
                            value={draft.spent_on}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    spent_on: event.target.value,
                                })
                            }
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-[2fr_1fr] sm:gap-3">
                        <div className="grid grid-cols-1 gap-2">
                            <Label htmlFor="expense-amount">Amount</Label>
                            <Input
                                id="expense-amount"
                                inputMode="decimal"
                                placeholder="12.50"
                                value={draft.amount}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        amount: event.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-2">
                            <Label htmlFor="expense-currency">Currency</Label>
                            <Input
                                id="expense-currency"
                                maxLength={3}
                                value={draft.currency}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        currency:
                                            event.target.value.toUpperCase(),
                                    })
                                }
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-2">
                        <Label htmlFor="expense-description">Description</Label>
                        <Textarea
                            id="expense-description"
                            value={draft.description}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    description: event.target.value,
                                })
                            }
                        />
                    </div>

                    {projects.length > 0 && (
                        <div className="grid grid-cols-1 gap-2">
                            <Label htmlFor="expense-project">Project</Label>
                            <Select
                                // Base UI renders the raw value in the trigger
                                // unless the root is told the labels, so this
                                // would otherwise show a project's uuid.
                                items={[
                                    {
                                        value: NO_PROJECT,
                                        label: 'No project',
                                    },
                                    ...projects.map((project) => ({
                                        value: project.id,
                                        label: project.name,
                                    })),
                                ]}
                                value={draft.project_id}
                                onValueChange={(value) =>
                                    setDraft({
                                        ...draft,
                                        project_id: String(value),
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="expense-project"
                                    // The shared trigger is `w-fit`, so a long
                                    // unbroken project name sizes it to the
                                    // name and pushes the dialog past a narrow
                                    // viewport. Bounded, and the value
                                    // truncates inside it.
                                    className="w-full [&>span]:truncate"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_PROJECT}>
                                        No project
                                    </SelectItem>
                                    {projects.map((project) => (
                                        <SelectItem
                                            key={project.id}
                                            value={project.id}
                                        >
                                            {project.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {error !== null && (
                        <p
                            role="alert"
                            className="text-sm wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <DialogClose
                        render={
                            <Button variant="outline" type="button">
                                Cancel
                            </Button>
                        }
                    />
                    <Button onClick={submit} disabled={saving}>
                        {expense === null ? 'Record' : 'Save'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
