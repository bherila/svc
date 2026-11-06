import { Link, router, usePage } from '@inertiajs/react';
import { CheckIcon, MessageSquareWarningIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useDurationStyle } from '@/hooks/use-duration-style';
import { formatShortDay } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import { statusLabel } from '@/lib/labels';
import { formatMoney } from '@/lib/money';
import type {
    PortalApprovalExpense,
    PortalApprovalGroup,
    PortalApprovalHour,
    PortalApprovalResult,
    PortalToApprove,
} from '@/types/portal';

type Target = { kind: 'time' | 'expense'; id: string };

type Body = Record<string, string | string[]>;

/** What the last decision did, in words. */
export function approvalMessage(result: PortalApprovalResult): string {
    if (result.kind === 'disputed') {
        return 'Your note was sent to your provider.';
    }

    const parts: string[] = [];

    if (result.time > 0) {
        parts.push(
            `${result.time} ${result.time === 1 ? 'entry of time' : 'entries of time'}`,
        );
    }

    if (result.expenses > 0) {
        parts.push(
            `${result.expenses} ${result.expenses === 1 ? 'expense' : 'expenses'}`,
        );
    }

    const approved =
        parts.length === 0
            ? 'Nothing was waiting to be approved.'
            : `Approved ${parts.join(' and ')}.`;

    return result.skipped_own > 0
        ? `${approved} ${result.skipped_own} of your own ${result.skipped_own === 1 ? 'was' : 'were'} left for someone else to approve.`
        : approved;
}

/**
 * "To approve": the hours and expenses this contact has yet to decide on,
 * grouped by vendor and calendar month (docs/ux-overhaul.md section 7c).
 *
 * Hours print as time and never as money; an expense prints its amount, which
 * is what is being approved. Every control posts to a URL the server sent, and
 * the server decides again, so the screen offers nothing the server would
 * refuse beyond the rows it has just been told are stale. Approving is a
 * button that commits in place, so each is outlined: the page's one filled
 * button is Pay.
 */
export function ToApprove({
    data,
    result,
    title = 'To approve',
    caption = null,
    errorKey = 'approval',
    allHref = null,
    empty = 'Nothing is waiting. Hours and expenses your provider has billed you for appear here until you approve or dispute them, or pay the invoice they are on.',
}: {
    data: PortalToApprove;
    result: PortalApprovalResult | null;
    /** The card's heading; the cross-workspace page names the provider here. */
    title?: string;
    /** One line under the heading, such as whose client this is. */
    caption?: string | null;
    /** Which validation error is this card's, when several cards share a page. */
    errorKey?: string;
    /** Where everything waiting for this person is, when it is more than this card. */
    allHref?: string | null;
    /** What an empty card says. */
    empty?: string;
}) {
    const style = useDurationStyle();
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const [busy, setBusy] = useState(false);
    const [disputing, setDisputing] = useState<Target | null>(null);
    const [note, setNote] = useState('');

    const post = (url: string, body: Body) => {
        setBusy(true);
        router.post(url, body, {
            preserveScroll: true,
            onSuccess: () => {
                setDisputing(null);
                setNote('');
            },
            onFinish: () => setBusy(false),
        });
    };

    const approve = (body: Body) => post(data.approve_href, body);

    const startDispute = (target: Target) => {
        setDisputing(target);
        setNote('');
    };

    const sendDispute = () => {
        if (disputing === null) {
            return;
        }

        post(data.dispute_href, { ...disputing, note });
    };

    return (
        <Card data-slot="to-approve">
            <CardHeader>
                <CardTitle className="wrap-anywhere">{title}</CardTitle>
                {caption !== null && (
                    <CardDescription className="wrap-anywhere">
                        {caption}
                    </CardDescription>
                )}
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4">
                {result !== null && (
                    <p
                        role="status"
                        className="text-sm wrap-anywhere text-muted-foreground"
                    >
                        {approvalMessage(result)}
                    </p>
                )}
                {errors?.[errorKey] !== undefined && (
                    <p
                        role="alert"
                        className="text-sm font-medium wrap-anywhere text-destructive"
                    >
                        {errors[errorKey]}
                    </p>
                )}
                {data.groups.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{empty}</p>
                ) : (
                    data.groups.map((group) => (
                        <Group
                            key={group.key}
                            group={group}
                            style={style}
                            busy={busy}
                            disputing={disputing}
                            note={note}
                            onNote={setNote}
                            onApprove={approve}
                            onStartDispute={startDispute}
                            onCancelDispute={() => setDisputing(null)}
                            onSendDispute={sendDispute}
                        />
                    ))
                )}
                {data.truncated && (
                    <p className="text-xs text-muted-foreground">
                        These are the oldest rows. Approve or dispute them to
                        see the rest.
                    </p>
                )}
                {allHref !== null && (
                    <p className="text-sm">
                        <Link
                            href={allHref}
                            className="underline underline-offset-4"
                        >
                            Everything waiting for you
                        </Link>
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function Group({
    group,
    style,
    busy,
    disputing,
    note,
    onNote,
    onApprove,
    onStartDispute,
    onCancelDispute,
    onSendDispute,
}: {
    group: PortalApprovalGroup;
    style: DurationStyle;
    busy: boolean;
    disputing: Target | null;
    note: string;
    onNote: (value: string) => void;
    onApprove: (body: Body) => void;
    onStartDispute: (target: Target) => void;
    onCancelDispute: () => void;
    onSendDispute: () => void;
}) {
    const pending = group.hours.pending_count + group.expenses.pending_count;
    const rows = group.hours.entries.length + group.expenses.items.length;
    const heading = `${group.vendor} · ${group.period_label}`;
    const summary = [
        group.hours.pending_count > 0
            ? `${formatDuration(group.hours.minutes, style)} of time`
            : null,
        group.expenses.pending_count > 0
            ? group.expenses.amount === null || group.expenses.currency === null
                ? `${group.expenses.pending_count} expenses`
                : `${formatMoney(group.expenses.amount, group.expenses.currency)} of expenses`
            : null,
    ].filter((part): part is string => part !== null);

    return (
        <section
            aria-label={heading}
            className="grid grid-cols-1 gap-2 border-t border-border pt-4 first:border-t-0 first:pt-0"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h3 className="min-w-0 flex-1 basis-60 text-sm font-semibold wrap-anywhere">
                    {heading}
                </h3>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11 md:min-h-8"
                    disabled={busy || pending === 0}
                    aria-label={`Approve ${group.period_label} from ${group.vendor}`}
                    onClick={() => onApprove({ period: group.period })}
                >
                    <CheckIcon aria-hidden="true" />
                    Approve {group.period_label}
                </Button>
            </div>
            <p className="text-sm wrap-anywhere text-muted-foreground">
                {summary.length > 0
                    ? summary.join(' · ')
                    : 'Everything here is disputed.'}
            </p>
            {rows > 0 && (
                <details className="group/rows">
                    <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm underline underline-offset-4 md:min-h-8">
                        Show the {rows === 1 ? 'row' : `${rows} rows`}
                    </summary>
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {group.hours.entries.map((entry) => (
                            <Row
                                key={entry.id}
                                kind="time"
                                entry={entry}
                                style={style}
                                busy={busy}
                                disputing={disputing}
                                note={note}
                                onNote={onNote}
                                onApprove={onApprove}
                                onStartDispute={onStartDispute}
                                onCancelDispute={onCancelDispute}
                                onSendDispute={onSendDispute}
                            />
                        ))}
                        {group.expenses.items.map((expense) => (
                            <Row
                                key={expense.id}
                                kind="expense"
                                entry={expense}
                                style={style}
                                busy={busy}
                                disputing={disputing}
                                note={note}
                                onNote={onNote}
                                onApprove={onApprove}
                                onStartDispute={onStartDispute}
                                onCancelDispute={onCancelDispute}
                                onSendDispute={onSendDispute}
                            />
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}

function Row({
    kind,
    entry,
    style,
    busy,
    disputing,
    note,
    onNote,
    onApprove,
    onStartDispute,
    onCancelDispute,
    onSendDispute,
}: {
    kind: 'time' | 'expense';
    entry: PortalApprovalHour | PortalApprovalExpense;
    style: DurationStyle;
    busy: boolean;
    disputing: Target | null;
    note: string;
    onNote: (value: string) => void;
    onApprove: (body: Body) => void;
    onStartDispute: (target: Target) => void;
    onCancelDispute: () => void;
    onSendDispute: () => void;
}) {
    const isTime = kind === 'time';
    const date = isTime
        ? (entry as PortalApprovalHour).worked_on
        : (entry as PortalApprovalExpense).spent_on;
    const label = entry.description ?? 'Time';
    const open = disputing?.kind === kind && disputing.id === entry.id;
    const field = `dispute-note-${entry.id}`;

    return (
        <li className="grid grid-cols-1 gap-2 py-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                <span className="w-24 shrink-0 text-muted-foreground tabular-nums">
                    {formatShortDay(date)}
                </span>
                <span className="min-w-0 flex-1 basis-48 wrap-anywhere">
                    {entry.description ?? '—'}
                    <span className="block text-xs text-muted-foreground">
                        {isTime ? 'Time' : 'Expense'}
                        {entry.project !== null && ` · ${entry.project}`}
                        {entry.invoice !== null && (
                            <>
                                {' · '}
                                <a
                                    href={entry.invoice.href}
                                    className="underline underline-offset-4"
                                >
                                    Invoice {entry.invoice.number}
                                </a>
                            </>
                        )}
                    </span>
                </span>
                <span className="shrink-0 tabular-nums">
                    {isTime
                        ? formatDuration(
                              (entry as PortalApprovalHour).minutes,
                              style,
                          )
                        : formatMoney(
                              (entry as PortalApprovalExpense).amount,
                              (entry as PortalApprovalExpense).currency,
                          )}
                </span>
            </div>
            {entry.state === 'disputed' ? (
                <p className="text-xs wrap-anywhere text-muted-foreground">
                    <span className="font-medium">
                        {statusLabel(entry.state)}.
                    </span>{' '}
                    {entry.dispute_note !== null
                        ? `Your note: ${entry.dispute_note}`
                        : 'Your provider will correct it if needed.'}
                </p>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="min-h-11 md:min-h-7"
                        disabled={busy}
                        aria-label={`Approve ${label}`}
                        onClick={() =>
                            onApprove(
                                isTime
                                    ? { time_ids: [entry.id] }
                                    : { expense_ids: [entry.id] },
                            )
                        }
                    >
                        <CheckIcon aria-hidden="true" />
                        Approve
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="min-h-11 md:min-h-7"
                        disabled={busy}
                        aria-label={`Dispute ${label}`}
                        aria-expanded={open}
                        onClick={() => onStartDispute({ kind, id: entry.id })}
                    >
                        <MessageSquareWarningIcon aria-hidden="true" />
                        Dispute…
                    </Button>
                </div>
            )}
            {open && (
                <div className="grid grid-cols-1 gap-2">
                    <Label htmlFor={field}>
                        Note for your provider (optional)
                    </Label>
                    <Textarea
                        id={field}
                        value={note}
                        maxLength={1000}
                        onChange={(event) => onNote(event.target.value)}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="min-h-11 md:min-h-7"
                            disabled={busy}
                            onClick={onSendDispute}
                        >
                            <MessageSquareWarningIcon aria-hidden="true" />
                            Send dispute
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="min-h-11 md:min-h-7"
                            onClick={onCancelDispute}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}
