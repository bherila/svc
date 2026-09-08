import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatShortDay } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { formatHours, todayIn } from '@/lib/time';
import type { TimeEntry } from '@/types/time-sheet';

/** A quote from server-priced rows; the write rechecks availability under lock. */
export function InvoiceSelectionDialog({
    entries,
    url,
    timezone,
    onClose,
    onSuccess,
}: {
    entries: TimeEntry[];
    url: string;
    timezone: string;
    onClose: () => void;
    onSuccess: () => void;
}) {
    const [number, setNumber] = useState('');
    const [issueDate, setIssueDate] = useState(() => todayIn(timezone));
    const [dueDate, setDueDate] = useState('');
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const currency = entries[0]?.invoice_terms?.currency;
    const valid =
        currency !== undefined &&
        entries.every((entry) => entry.invoice_terms?.currency === currency);
    const total = entries.reduce(
        (sum, entry) => sum + (entry.invoice_terms?.total_amount ?? 0),
        0,
    );

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !saving) {
                    onClose();
                }
            }}
        >
            <DialogContent
                className="max-h-[90dvh] grid-cols-1 overflow-y-auto sm:max-w-2xl"
                showCloseButton={!saving}
            >
                <DialogHeader>
                    <DialogTitle>Draft invoice from selected time</DialogTitle>
                    <DialogDescription>
                        Review the selected work and its recorded rates.
                        Creating a draft allocates these entries; it does not
                        issue or send the invoice.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid min-w-0 grid-cols-1 gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (saving || !valid || number.trim() === '') {
                            return;
                        }

                        setSaving(true);
                        setError(null);
                        router.post(
                            url,
                            {
                                invoice_number: number.trim(),
                                currency,
                                issue_date: issueDate || null,
                                due_date: dueDate || null,
                                notes: notes || null,
                                time_entry_ids: entries.map(
                                    (entry) => entry.id,
                                ),
                            },
                            {
                                preserveScroll: true,
                                onSuccess,
                                onError: (errors) =>
                                    setError(
                                        Object.values(errors)[0] ??
                                            'The invoice could not be drafted.',
                                    ),
                                onFinish: () => setSaving(false),
                            },
                        );
                    }}
                >
                    <ul
                        aria-label="Selected invoice lines"
                        className="grid min-w-0 grid-cols-1 divide-y rounded-lg border px-3"
                    >
                        {entries.map((entry) => (
                            <li
                                key={entry.id}
                                className="grid min-w-0 grid-cols-1 gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium wrap-anywhere">
                                        {entry.description}
                                    </p>
                                    <p className="text-xs wrap-anywhere text-muted-foreground">
                                        {entry.project.name} ·{' '}
                                        {formatShortDay(entry.worked_on)} ·{' '}
                                        {formatHours(entry.minutes)}
                                    </p>
                                </div>
                                {entry.invoice_terms !== null && (
                                    <div className="text-sm tabular-nums sm:text-right">
                                        <p>
                                            {formatMoney(
                                                entry.invoice_terms
                                                    .total_amount,
                                                entry.invoice_terms.currency,
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatMoney(
                                                entry.invoice_terms.unit_amount,
                                                entry.invoice_terms.currency,
                                            )}{' '}
                                            / hour
                                        </p>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    {valid && (
                        <p className="text-right font-medium tabular-nums">
                            Draft total: {formatMoney(total, currency)}
                        </p>
                    )}
                    {!valid && (
                        <p role="alert" className="text-sm text-destructive">
                            Select entries in one currency for each invoice.
                        </p>
                    )}
                    <div className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid min-w-0 grid-cols-1 gap-2 sm:col-span-2">
                            <Label htmlFor="selected-invoice-number">
                                Invoice number
                            </Label>
                            <Input
                                id="selected-invoice-number"
                                value={number}
                                onChange={(event) =>
                                    setNumber(event.target.value)
                                }
                                required
                                maxLength={80}
                                disabled={saving}
                            />
                        </div>
                        <div className="grid min-w-0 grid-cols-1 gap-2">
                            <Label htmlFor="selected-invoice-issue">
                                Invoice date
                            </Label>
                            <Input
                                id="selected-invoice-issue"
                                type="date"
                                value={issueDate}
                                onChange={(event) =>
                                    setIssueDate(event.target.value)
                                }
                                disabled={saving}
                            />
                        </div>
                        <div className="grid min-w-0 grid-cols-1 gap-2">
                            <Label htmlFor="selected-invoice-due">
                                Due date (optional)
                            </Label>
                            <Input
                                id="selected-invoice-due"
                                type="date"
                                value={dueDate}
                                onChange={(event) =>
                                    setDueDate(event.target.value)
                                }
                                disabled={saving}
                            />
                        </div>
                    </div>
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <Label htmlFor="selected-invoice-notes">
                            Notes (optional)
                        </Label>
                        <Textarea
                            id="selected-invoice-notes"
                            value={notes}
                            onChange={(event) => setNotes(event.target.value)}
                            maxLength={10000}
                            disabled={saving}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Availability and rates are checked again when the draft
                        is created.
                    </p>
                    {error !== null && (
                        <p
                            role="alert"
                            className="text-sm wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving || !valid}>
                            {saving
                                ? 'Creating draft…'
                                : 'Create draft invoice'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
