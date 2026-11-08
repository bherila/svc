import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { autopayMoney, limitField, parseLimit } from '@/lib/autopay';
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';

/** The client's autopay as `companies.autopay.get` presents it, plus the finished URLs the page sends changes to. */
export type ClientAutopayState = ApiSchema<'CompanyAutopay'> & {
    update_href: string;
    portal_href: string;
    stripe_connected: boolean;
    /** The workspace's payments settings, where the Stripe connection is fixed. */
    payments_href: string;
    attestation_min: number;
    attestation_max: number;
};

/** One invoice's autopay as `invoices.autopay.get` presents it, plus the skip URL (null unless it may be skipped). */
export type InvoiceAutopayState = ApiSchema<'InvoiceAutopay'> & {
    skip_href: string | null;
    /** Where an attempt held for review is closed out; null unless it can be. */
    resolve_href: string | null;
    settings_href: string;
    payments_href: string;
};

/**
 * Stripe refused the workspace's connection while autopay was charging: the
 * invoices wait, nothing is shown to the client, and the fix is in the
 * workspace payments settings.
 */
function ConnectionProblem({
    problem,
    href,
}: {
    problem: { since: string; message: string };
    href: string;
}) {
    return (
        <p
            role="alert"
            className="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 wrap-anywhere"
        >
            <span className="font-medium text-destructive">
                {problem.message}
            </span>{' '}
            Stripe refused the connection on {formatTimestamp(problem.since)}.{' '}
            <a href={href} className="underline underline-offset-4">
                Open payments settings
            </a>
        </p>
    );
}

const SUGGESTED_LIMIT = 100_000;

function who(role: string): string {
    return role === 'system' ? 'SVC' : role;
}

function Stat({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid min-w-0 grid-cols-1 gap-1 rounded-lg border border-border p-4">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-semibold wrap-anywhere">{children}</dd>
        </div>
    );
}

/**
 * Autopay on the client's billing settings: the state, the consent record,
 * the invoices it will charge next, and turning it on, off or changing its
 * limit. Turning it on records the owner or admin's attestation of how the
 * client agreed outside SVC; the server emails the client's billing contacts
 * and refuses what it will not honour.
 */
export function ClientAutopayCard({
    autopay,
}: {
    autopay: ClientAutopayState;
}) {
    const [dialog, setDialog] = useState(false);
    const [confirmingOff, setConfirmingOff] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const next = autopay.upcoming.find((row) => row.state === 'will_charge');
    const canTurnOn = autopay.stripe_connected && autopay.method !== null;

    const turnOff = async () => {
        setBusy(true);
        const result = await apiRequest('PUT', autopay.update_href, {
            expected_version: autopay.version,
            enabled: false,
            confirm: true,
        });
        setConfirmingOff(false);

        if (result.ok) {
            setNotice('Autopay is off.');
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setNotice(result.message);
        setBusy(false);
    };

    return (
        <>
            <Card aria-labelledby="autopay-settings-heading">
                <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                    <div className="grid min-w-0 grid-cols-1 gap-1">
                        <CardTitle
                            id="autopay-settings-heading"
                            role="heading"
                            aria-level={2}
                        >
                            Autopay
                        </CardTitle>
                        <p className="max-w-prose text-sm text-muted-foreground">
                            The client turns autopay on from their portal, or an
                            owner or admin turns it on here for a client who
                            agreed outside SVC. Every change is recorded below.
                        </p>
                    </div>
                    <Badge variant={autopay.enabled ? 'secondary' : 'outline'}>
                        {autopay.enabled ? 'On' : 'Off'}
                    </Badge>
                </CardHeader>
                <CardContent className="grid grid-cols-1 gap-5 text-sm">
                    {notice !== null && (
                        <p
                            role="status"
                            className="rounded-lg border border-border px-3 py-2 wrap-anywhere"
                        >
                            {notice}
                        </p>
                    )}

                    {autopay.connection_problem !== null && (
                        <ConnectionProblem
                            problem={autopay.connection_problem}
                            href={autopay.payments_href}
                        />
                    )}

                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Stat label="Method">
                            {autopay.method === null
                                ? 'No default method'
                                : autopay.method.label}
                        </Stat>
                        <Stat label="Limit per invoice">
                            {autopay.limit_amount === null
                                ? 'Off'
                                : autopayMoney(
                                      autopay.limit_amount,
                                      autopay.currency,
                                  )}
                        </Stat>
                        <Stat label="Next charge">
                            {!autopay.enabled || next === undefined
                                ? 'None scheduled'
                                : `${autopayMoney(next.amount, next.currency)} on ${formatDay(next.due_on)}`}
                        </Stat>
                    </dl>

                    <div className="grid grid-cols-1 gap-2">
                        <h3 className="font-semibold">Consent record</h3>
                        {autopay.consents.length === 0 ? (
                            <p className="text-muted-foreground">
                                Autopay has never been turned on for this
                                client.
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>When</TableHead>
                                            <TableHead>Who</TableHead>
                                            <TableHead>Change</TableHead>
                                            <TableHead className="min-w-64">
                                                Wording agreed
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {autopay.consents.map((consent) => (
                                            <TableRow
                                                key={consent.id}
                                                className="align-top"
                                            >
                                                <TableCell>
                                                    {formatTimestamp(
                                                        consent.occurred_at,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {consent.actor_name ??
                                                        'SVC'}{' '}
                                                    ({who(consent.actor_role)})
                                                </TableCell>
                                                <TableCell>
                                                    {statusLabel(
                                                        consent.action,
                                                    )}
                                                    {consent.limit_amount !==
                                                        null &&
                                                    consent.currency !== null
                                                        ? `, up to ${autopayMoney(consent.limit_amount, consent.currency)}`
                                                        : ''}
                                                </TableCell>
                                                <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                    {consent.wording !== null
                                                        ? `“${consent.wording}”`
                                                        : consent.attestation !==
                                                            null
                                                          ? `Attested: “${consent.attestation}”${consent.notice_sent ? ' The client was emailed a notice with a link to turn it off.' : ' The notice to the client could not be sent.'}`
                                                          : (consent.reason ??
                                                            'None')}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {autopay.enabled ? (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-destructive"
                                    disabled={busy}
                                    onClick={() => setConfirmingOff(true)}
                                >
                                    Turn off autopay
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => setDialog(true)}
                                >
                                    Change limit
                                </Button>
                            </>
                        ) : (
                            <Button
                                variant="outline"
                                type="button"
                                disabled={busy || !canTurnOn}
                                onClick={() => setDialog(true)}
                            >
                                Turn on autopay
                            </Button>
                        )}
                        <span className="min-w-0 text-xs wrap-anywhere text-muted-foreground">
                            {!autopay.enabled && !autopay.stripe_connected
                                ? 'Connect Stripe under Payments before turning autopay on.'
                                : !autopay.enabled && autopay.method === null
                                  ? 'The client needs a default saved payment method first.'
                                  : autopay.enabled
                                    ? 'Either side can turn autopay off at any time.'
                                    : 'Turning it on asks for the limit and how the client agreed.'}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card aria-labelledby="autopay-upcoming-heading">
                <CardHeader>
                    <CardTitle
                        id="autopay-upcoming-heading"
                        role="heading"
                        aria-level={2}
                    >
                        Upcoming autopay charges
                    </CardTitle>
                </CardHeader>
                <CardContent className="text-sm">
                    {autopay.upcoming.length === 0 ? (
                        <p className="text-muted-foreground">
                            {autopay.enabled
                                ? 'No issued invoice is waiting on a due date.'
                                : 'Autopay is off, so nothing will be charged.'}
                        </p>
                    ) : (
                        <ul className="grid grid-cols-1">
                            {autopay.upcoming.map((row) => (
                                <li
                                    key={row.invoice_id}
                                    className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-border py-3 last:border-b-0"
                                >
                                    <span className="font-medium wrap-anywhere">
                                        {row.invoice_number}
                                    </span>
                                    <span>{formatDay(row.due_on)}</span>
                                    <span
                                        className={
                                            row.state === 'will_charge'
                                                ? 'font-medium'
                                                : 'font-medium text-amber-700 dark:text-amber-400'
                                        }
                                    >
                                        {autopayMoney(row.amount, row.currency)}
                                        {row.state === 'will_charge'
                                            ? ''
                                            : ` · ${['over_limit', 'no_default_method'].includes(row.state) ? `${row.state_label.replace('Not autopaid: ', '').toLowerCase()}, not charged` : row.state_label.toLowerCase()}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {dialog && (
                <ProviderConsentDialog
                    autopay={autopay}
                    onClose={() => setDialog(false)}
                    onDone={(message) => {
                        setDialog(false);
                        setNotice(message);
                    }}
                />
            )}

            <AlertDialog
                open={confirmingOff}
                onOpenChange={(open: boolean) => {
                    if (!busy) {
                        setConfirmingOff(open);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Turn off autopay?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Nothing more will be charged automatically. Every
                            invoice becomes the client’s to pay by hand, and
                            this is recorded.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Keep autopay on
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event: React.MouseEvent) => {
                                event.preventDefault();
                                void turnOff();
                            }}
                        >
                            Turn off autopay
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function ProviderConsentDialog({
    autopay,
    onClose,
    onDone,
}: {
    autopay: ClientAutopayState;
    onClose: () => void;
    onDone: (message: string) => void;
}) {
    const [limitText, setLimitText] = useState(
        limitField(autopay.limit_amount ?? SUGGESTED_LIMIT),
    );
    const [attestation, setAttestation] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // One key per logical attempt, kept across retries until one succeeds, so
    // a lost answer replays rather than emailing the client twice.
    const [key] = useState(newIdempotencyKey);
    const limit = parseLimit(limitText);
    const words = attestation.trim().length;
    const valid =
        limit !== null &&
        words >= autopay.attestation_min &&
        words <= autopay.attestation_max;

    const submit = async () => {
        if (!valid || limit === null || busy) {
            return;
        }

        setBusy(true);
        setError(null);
        const result = await apiRequest(
            'PUT',
            autopay.update_href,
            {
                expected_version: autopay.version,
                enabled: true,
                limit_amount: limit,
                attestation: attestation.trim(),
                confirm: true,
            },
            key,
        );

        if (result.ok) {
            onDone(
                autopay.enabled
                    ? 'The autopay limit was changed.'
                    : 'Autopay is on. The client’s billing contacts were emailed a notice with a link to turn it off.',
            );
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setError(result.message);
        setBusy(false);
    };

    return (
        <Dialog
            open
            onOpenChange={(open: boolean) => {
                if (!open && !busy) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90dvh] grid-cols-1 overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {autopay.enabled
                            ? 'Change the autopay limit'
                            : 'Turn on autopay'}
                    </DialogTitle>
                    <DialogDescription>
                        SVC will charge{' '}
                        {autopay.method?.label ?? 'the default method'} for each
                        invoice on its due date, once, up to the limit.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid grid-cols-1 gap-5 text-sm">
                    <div className="grid grid-cols-1 gap-1.5">
                        <Label htmlFor="provider-autopay-limit">
                            Charge up to, per invoice
                        </Label>
                        <div className="flex items-center gap-2">
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                {autopay.currency.toUpperCase() === 'USD'
                                    ? '$'
                                    : autopay.currency.toUpperCase()}
                            </span>
                            <Input
                                id="provider-autopay-limit"
                                inputMode="decimal"
                                value={limitText}
                                onChange={(event) =>
                                    setLimitText(event.target.value)
                                }
                                aria-invalid={
                                    limitText !== '' && limit === null
                                }
                                className="max-w-48"
                            />
                        </div>
                        <span className="text-xs text-muted-foreground">
                            An invoice over this is not charged and stays the
                            client’s to pay by hand.
                        </span>
                    </div>
                    <div className="grid grid-cols-1 gap-1.5">
                        <Label htmlFor="provider-autopay-attestation">
                            How did the client agree to autopay?
                        </Label>
                        <Textarea
                            id="provider-autopay-attestation"
                            rows={4}
                            maxLength={autopay.attestation_max}
                            value={attestation}
                            onChange={(event) =>
                                setAttestation(event.target.value)
                            }
                            placeholder="For example: agreed in the signed services agreement of Aug 28, clause 7."
                        />
                        <span className="text-xs text-muted-foreground">
                            This is recorded with your name. The client’s
                            billing contacts are emailed a notice with a link to
                            turn autopay off.
                        </span>
                    </div>
                    {error !== null && (
                        <p
                            role="alert"
                            className="wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={busy || !valid}
                        onClick={() => void submit()}
                    >
                        {autopay.enabled ? 'Save new limit' : 'Turn on autopay'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function resultWords(
    attempt: NonNullable<InvoiceAutopayState['attempt']>,
    state: string,
): ReactNode {
    if (state === 'failed') {
        return (
            <>
                <span className="font-medium text-destructive">Failed</span>
                {attempt.failure_reason === null
                    ? ''
                    : ` · ${attempt.failure_reason}`}
                . Not retried; payment reminders resume.
            </>
        );
    }

    if (state === 'resolved_not_charged') {
        return (
            <>
                <span className="font-medium">Left for manual collection</span>{' '}
                · Nothing was charged. Collect it by hand; autopay will not try
                again.
            </>
        );
    }

    if (state === 'needs_review') {
        return (
            <>
                <span className="font-medium text-destructive">
                    Needs review
                </span>{' '}
                · The charge was claimed but Stripe’s answer was not read. Check
                Stripe, then record what you found. Do not collect by hand
                before then.
            </>
        );
    }

    return <span className="font-medium">{statusLabel(state)}</span>;
}

/**
 * Autopay on one invoice, for the operator: where it stands, its one attempt,
 * and skipping it. The skip button exists only when the server sends a URL.
 */
export function InvoiceAutopayCard({
    autopay,
}: {
    autopay: InvoiceAutopayState;
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [closing, setClosing] = useState(false);
    const [outcome, setOutcome] = useState<'charged' | 'not_charged'>(
        'not_charged',
    );
    const [intentId, setIntentId] = useState('');
    const [note, setNote] = useState('');
    const [closeError, setCloseError] = useState<string | null>(null);
    const attempt = autopay.attempt;

    if (autopay.state === 'not_applicable') {
        return null;
    }

    const intentValid = /^pi_[A-Za-z0-9_]{1,252}$/.test(intentId.trim());
    const canRecord =
        outcome === 'charged'
            ? intentValid
            : note.trim().length >= 3 && note.trim().length <= 255;

    const closeOut = async () => {
        if (autopay.resolve_href === null || !canRecord) {
            return;
        }

        setBusy(true);
        setCloseError(null);
        const result = await apiRequest('POST', autopay.resolve_href, {
            expected_version: autopay.invoice_version,
            outcome,
            ...(outcome === 'charged'
                ? { payment_intent_id: intentId.trim() }
                : {}),
            ...(note.trim() === '' ? {} : { note: note.trim() }),
            confirm: true,
        });

        if (result.ok) {
            setClosing(false);
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setCloseError(result.message);
        setBusy(false);
    };

    const skip = async () => {
        if (autopay.skip_href === null) {
            return;
        }

        setBusy(true);
        setError(null);
        const result = await apiRequest('POST', autopay.skip_href, {
            expected_version: autopay.invoice_version,
            confirm: true,
        });
        setConfirming(false);

        if (result.ok) {
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setError(result.message);
        setBusy(false);
    };

    return (
        <Card aria-labelledby="invoice-autopay-heading">
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle
                    id="invoice-autopay-heading"
                    role="heading"
                    aria-level={2}
                >
                    Autopay
                </CardTitle>
                <Badge
                    variant={
                        autopay.state === 'failed' ||
                        autopay.state === 'needs_review'
                            ? 'destructive'
                            : 'outline'
                    }
                >
                    {autopay.state === 'will_charge' &&
                    autopay.will_charge_on !== null
                        ? `Will autopay ${formatDay(autopay.will_charge_on)}`
                        : autopay.state_label}
                </Badge>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {autopay.connection_problem !== null && (
                    <ConnectionProblem
                        problem={autopay.connection_problem}
                        href={autopay.payments_href}
                    />
                )}
                <dl className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <div className="flex min-w-0 justify-between gap-3">
                        <dt className="text-muted-foreground">Method</dt>
                        <dd className="wrap-anywhere">
                            {autopay.method_label ??
                                attempt?.payment_method_label ??
                                '—'}
                        </dd>
                    </div>
                    <div className="flex min-w-0 justify-between gap-3">
                        <dt className="text-muted-foreground">Limit</dt>
                        <dd>
                            {autopay.limit_amount === null
                                ? '—'
                                : autopayMoney(
                                      autopay.limit_amount,
                                      autopay.currency,
                                  )}
                        </dd>
                    </div>
                    <div className="flex min-w-0 justify-between gap-3">
                        <dt className="text-muted-foreground">Balance due</dt>
                        <dd className="font-semibold">
                            {autopayMoney(autopay.amount, autopay.currency)}
                        </dd>
                    </div>
                </dl>

                {attempt !== null && attempt.state === 'skipped' ? (
                    <p className="wrap-anywhere text-muted-foreground">
                        Skipped by{' '}
                        {attempt.skipped_by_name ?? 'a workspace admin'} on{' '}
                        {formatTimestamp(attempt.skipped_at)}. This invoice is
                        never charged automatically.
                    </p>
                ) : attempt !== null ? (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Attempt</TableHead>
                                    <TableHead>Amount</TableHead>
                                    <TableHead className="min-w-48">
                                        Result
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow className="align-top">
                                    <TableCell>
                                        {formatTimestamp(attempt.claimed_at)}
                                    </TableCell>
                                    <TableCell>Autopay</TableCell>
                                    <TableCell>
                                        {attempt.amount === null
                                            ? '—'
                                            : autopayMoney(
                                                  attempt.amount,
                                                  attempt.currency ??
                                                      autopay.currency,
                                              )}
                                    </TableCell>
                                    <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                        {resultWords(attempt, autopay.state)}
                                        {(attempt.resolved_by_name !== null ||
                                            attempt.resolution_note !==
                                                null) && (
                                            <span className="mt-1 block text-muted-foreground">
                                                Closed out
                                                {attempt.resolved_by_name ===
                                                null
                                                    ? ''
                                                    : ` by ${attempt.resolved_by_name}`}{' '}
                                                on{' '}
                                                {formatTimestamp(
                                                    attempt.resolved_at,
                                                )}{' '}
                                                after checking Stripe
                                                {attempt.resolution_note ===
                                                null
                                                    ? '.'
                                                    : `: “${attempt.resolution_note}”`}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                ) : null}

                {error !== null && (
                    <p role="alert" className="wrap-anywhere text-destructive">
                        {error}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    {autopay.skip_href !== null && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setConfirming(true)}
                        >
                            Skip autopay for this invoice
                        </Button>
                    )}
                    {autopay.resolve_href !== null && (
                        <Button
                            type="button"
                            variant="default"
                            disabled={busy}
                            onClick={() => {
                                setCloseError(null);
                                setClosing(true);
                            }}
                        >
                            Close out this attempt
                        </Button>
                    )}
                    <a
                        href={autopay.settings_href}
                        className="text-sm underline underline-offset-4"
                    >
                        View consent record
                    </a>
                </div>
            </CardContent>

            <Dialog
                open={closing}
                onOpenChange={(open: boolean) => {
                    if (!busy) {
                        setClosing(open);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Close out this autopay attempt
                        </DialogTitle>
                        <DialogDescription>
                            Stripe gave no readable answer, so nobody knows if
                            the client was charged. Look the payment up in the
                            Stripe dashboard first, then record what you found.
                            This is recorded with your name and never sends a
                            charge.
                        </DialogDescription>
                    </DialogHeader>
                    <fieldset className="grid grid-cols-1 gap-2">
                        <legend className="mb-1 font-medium">
                            What did Stripe show?
                        </legend>
                        <label className="flex items-start gap-2">
                            <input
                                type="radio"
                                name="autopay-outcome"
                                className="mt-1"
                                checked={outcome === 'charged'}
                                onChange={() => setOutcome('charged')}
                            />
                            <span>The client was charged</span>
                        </label>
                        <label className="flex items-start gap-2">
                            <input
                                type="radio"
                                name="autopay-outcome"
                                className="mt-1"
                                checked={outcome === 'not_charged'}
                                onChange={() => setOutcome('not_charged')}
                            />
                            <span>
                                Nothing was charged. The invoice is left for
                                manual collection and autopay will not try it a
                                second time.
                            </span>
                        </label>
                    </fieldset>
                    {outcome === 'charged' && (
                        <div className="grid grid-cols-1 gap-1.5">
                            <Label htmlFor="autopay-intent-id">
                                PaymentIntent id
                            </Label>
                            <Input
                                id="autopay-intent-id"
                                value={intentId}
                                onChange={(event) =>
                                    setIntentId(event.target.value)
                                }
                                placeholder="pi_..."
                                autoComplete="off"
                                spellCheck={false}
                                aria-invalid={
                                    intentId.trim() !== '' && !intentValid
                                }
                            />
                            <span className="text-xs text-muted-foreground">
                                SVC reads it from Stripe and records it only if
                                it is this invoice’s payment for{' '}
                                {attempt?.amount == null
                                    ? 'the attempted amount'
                                    : autopayMoney(
                                          attempt.amount,
                                          attempt.currency ?? autopay.currency,
                                      )}
                                .
                            </span>
                        </div>
                    )}
                    <div className="grid grid-cols-1 gap-1.5">
                        <Label htmlFor="autopay-resolution-note">
                            {outcome === 'charged'
                                ? 'Note (optional)'
                                : 'What did you check?'}
                        </Label>
                        <Textarea
                            id="autopay-resolution-note"
                            rows={3}
                            maxLength={255}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            placeholder="For example: no payment for this invoice in the Stripe dashboard on Dec 2."
                        />
                    </div>
                    {closeError !== null && (
                        <p
                            role="alert"
                            className="wrap-anywhere text-destructive"
                        >
                            {closeError}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setClosing(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={busy || !canRecord}
                            onClick={() => void closeOut()}
                        >
                            {outcome === 'charged'
                                ? 'Record as charged'
                                : 'Record as not charged'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={confirming}
                onOpenChange={(open: boolean) => {
                    if (!busy) {
                        setConfirming(open);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Skip autopay for this invoice?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            It will not be charged automatically, and this is
                            recorded with your name. The client can still pay it
                            by hand.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={busy}
                            onClick={(event: React.MouseEvent) => {
                                event.preventDefault();
                                void skip();
                            }}
                        >
                            Skip autopay
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}
