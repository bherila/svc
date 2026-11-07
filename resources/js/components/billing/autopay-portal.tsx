import { router } from '@inertiajs/react';
import { CircleAlertIcon, CircleCheckIcon, ClockIcon } from 'lucide-react';
import { useState } from 'react';
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
    authorizationWording,
    autopayMoney,
    limitField,
    parseLimit,
} from '@/lib/autopay';
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';

/** One entry of the consent record, as the portal receives it. */
export type PortalConsent = {
    id: string;
    action: string;
    actor_name: string | null;
    actor_role: string;
    limit_amount: number | null;
    currency: string | null;
    reason: string | null;
    occurred_at: string | null;
};

export type PortalUpcoming = {
    invoice_id: string;
    invoice_number: string;
    due_on: string | null;
    amount: number;
    currency: string;
    state: string;
};

/** The client's autopay, with the finished URLs and wording parts the server sends. */
export type PortalAutopay = {
    enabled: boolean;
    enabled_at: string | null;
    limit_amount: number | null;
    currency: string;
    method: { label: string } | null;
    upcoming: PortalUpcoming[];
    consents: PortalConsent[];
    enable_href: string | null;
    disable_href: string | null;
    wording: {
        provider: string;
        company: string;
        method: string;
        currency: string;
    } | null;
    suggested_limit: number;
    largest_invoice: number | null;
};

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-wrap justify-between gap-x-4 gap-y-1 border-b border-border px-4 py-3 last:border-b-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 font-medium wrap-anywhere">{children}</dd>
        </div>
    );
}

function lastTurnedOn(consents: PortalConsent[]): PortalConsent | null {
    return consents.find((consent) => consent.action === 'enabled') ?? null;
}

/**
 * The portal's Autopay card: what it does, what it is set to, and the one
 * place a client contact consents to it. Nothing here decides whether the
 * viewer may - the server sends the URLs, or null - and the wording the client
 * ticks is composed here from the server's parts and re-checked by the server.
 */
export function PortalAutopayCard({
    providerName,
    autopay,
    available,
    result,
}: {
    providerName: string;
    autopay: PortalAutopay;
    available: boolean;
    result?: string | null;
}) {
    const [dialog, setDialog] = useState<'on' | 'change' | null>(null);
    const [confirmingOff, setConfirmingOff] = useState(false);
    const [busy, setBusy] = useState(false);
    const next = autopay.upcoming.find((row) => row.state === 'will_charge');
    const turnedOn = lastTurnedOn(autopay.consents);

    const turnOff = () => {
        if (autopay.disable_href === null) {
            return;
        }

        setBusy(true);
        router.delete(autopay.disable_href, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirmingOff(false);
            },
        });
    };

    return (
        <Card aria-labelledby="autopay-heading">
            <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid min-w-0 grid-cols-1 gap-1">
                    <CardTitle
                        id="autopay-heading"
                        role="heading"
                        aria-level={2}
                        className="text-lg"
                    >
                        Autopay
                    </CardTitle>
                    <p className="max-w-prose text-sm wrap-anywhere text-muted-foreground">
                        {providerName} charges your default payment method on
                        each invoice’s due date. You still get every invoice
                        first.
                    </p>
                </div>
                <Badge variant={autopay.enabled ? 'secondary' : 'outline'}>
                    {autopay.enabled ? (
                        <CircleCheckIcon aria-hidden="true" />
                    ) : null}
                    {autopay.enabled ? 'On' : 'Off'}
                </Badge>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {result === 'on' || result === 'off' ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border px-3 py-2"
                    >
                        {result === 'on'
                            ? 'Autopay is on.'
                            : 'Autopay is off. Invoices are yours to pay by hand.'}
                    </p>
                ) : null}

                {!available ? (
                    <p className="text-muted-foreground">
                        {providerName} has not set up card payments yet, so
                        autopay is not available.
                    </p>
                ) : autopay.enabled ? (
                    <>
                        <dl className="grid grid-cols-1 rounded-lg border border-border">
                            <Row label="Charges">
                                {autopay.method === null
                                    ? 'No default method'
                                    : `${autopay.method.label} (default)`}
                            </Row>
                            <Row label="Up to, per invoice">
                                {autopay.limit_amount === null
                                    ? '—'
                                    : autopay.currency === ''
                                      ? String(autopay.limit_amount)
                                      : autopayMoney(
                                            autopay.limit_amount,
                                            autopay.currency,
                                        )}
                            </Row>
                            <Row label="Next charge">
                                {next === undefined
                                    ? 'Nothing due yet'
                                    : `${autopayMoney(next.amount, next.currency)} on ${formatDay(next.due_on)} · ${next.invoice_number}`}
                            </Row>
                            {turnedOn !== null && (
                                <Row label="Turned on">
                                    {formatTimestamp(turnedOn.occurred_at)}
                                    {turnedOn.actor_name === null
                                        ? ''
                                        : ` by ${turnedOn.actor_name}`}
                                </Row>
                            )}
                        </dl>
                        {autopay.disable_href !== null ? (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setDialog('change')}
                                >
                                    Change limit
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-destructive"
                                    onClick={() => setConfirmingOff(true)}
                                >
                                    Turn off autopay
                                </Button>
                            </div>
                        ) : (
                            <p className="text-muted-foreground">
                                Only a contact of this company can change
                                autopay.
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Invoices over your limit are not charged. You’ll get
                            them to pay by hand, as today.
                        </p>
                    </>
                ) : (
                    <>
                        <p className="text-muted-foreground">
                            Pay each invoice on its due date automatically, with
                            your default method. You can turn it off at any
                            time.
                        </p>
                        {autopay.enable_href !== null &&
                        autopay.wording !== null ? (
                            <div>
                                <Button
                                    type="button"
                                    onClick={() => setDialog('on')}
                                >
                                    Turn on autopay
                                </Button>
                            </div>
                        ) : (
                            <p className="text-muted-foreground">
                                {autopay.wording === null
                                    ? 'Autopay needs a default saved payment method. Save a card when you pay an invoice, then come back here.'
                                    : 'Only a contact of this company can turn on autopay.'}
                            </p>
                        )}
                    </>
                )}

                {autopay.consents.length > 0 && (
                    <details className="rounded-lg border border-border px-4 py-3">
                        <summary className="cursor-pointer font-medium">
                            Autopay history
                        </summary>
                        <ul className="mt-3 grid grid-cols-1 gap-2">
                            {autopay.consents.map((consent) => (
                                <li
                                    key={consent.id}
                                    className="min-w-0 wrap-anywhere"
                                >
                                    <span className="font-medium">
                                        {statusLabel(consent.action)}
                                    </span>
                                    {consent.limit_amount !== null &&
                                    consent.currency !== null
                                        ? `, up to ${autopayMoney(consent.limit_amount, consent.currency)}`
                                        : ''}
                                    {' · '}
                                    {formatTimestamp(consent.occurred_at)}
                                    {consent.actor_name === null
                                        ? ''
                                        : ` · ${consent.actor_name}`}
                                    {consent.reason === null
                                        ? ''
                                        : ` · ${consent.reason}`}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </CardContent>

            {dialog !== null &&
                autopay.enable_href !== null &&
                autopay.wording !== null && (
                    <ConsentDialog
                        mode={dialog}
                        href={autopay.enable_href}
                        autopay={autopay}
                        wording={autopay.wording}
                        onClose={() => setDialog(null)}
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
                            {providerName} will stop charging your payment
                            method on due dates. You’ll pay each invoice by
                            hand.
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
                                turnOff();
                            }}
                        >
                            Turn off autopay
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}

/**
 * The turn-on dialog: the limit, how it works, and the authorization the
 * client ticks. The sentence beside the checkbox is the sentence stored.
 */
function ConsentDialog({
    mode,
    href,
    autopay,
    wording,
    onClose,
}: {
    mode: 'on' | 'change';
    href: string;
    autopay: PortalAutopay;
    wording: NonNullable<PortalAutopay['wording']>;
    onClose: () => void;
}) {
    const [limitText, setLimitText] = useState(
        limitField(
            mode === 'change' && autopay.limit_amount !== null
                ? autopay.limit_amount
                : autopay.suggested_limit,
        ),
    );
    const [agreed, setAgreed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const limit = parseLimit(limitText);
    const sentence =
        limit === null ? null : authorizationWording({ ...wording, limit });

    const submit = () => {
        if (limit === null || sentence === null || !agreed || busy) {
            return;
        }

        setBusy(true);
        setError(null);
        router.post(
            href,
            { limit_amount: limit, wording: sentence, authorized: true },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Autopay could not be turned on.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
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
                        {mode === 'change'
                            ? 'Change your autopay limit'
                            : 'Turn on autopay'}
                    </DialogTitle>
                    <DialogDescription className="wrap-anywhere">
                        For {wording.company} invoices from {wording.provider}.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-5 text-sm">
                    <div className="grid grid-cols-1 gap-1.5">
                        <span className="font-medium">Charge</span>
                        <p className="rounded-lg border border-border px-3 py-2 wrap-anywhere">
                            {wording.method} (default)
                        </p>
                        <span className="text-xs text-muted-foreground">
                            Autopay always uses your default payment method.
                        </span>
                    </div>

                    <div className="grid grid-cols-1 gap-1.5">
                        <Label htmlFor="autopay-limit">
                            Charge up to, per invoice
                        </Label>
                        <div className="flex items-center gap-2">
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                {wording.currency.toUpperCase() === 'USD'
                                    ? '$'
                                    : wording.currency.toUpperCase()}
                            </span>
                            <Input
                                id="autopay-limit"
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
                            {autopay.largest_invoice === null
                                ? 'Anything over the limit comes to you to pay by hand.'
                                : `Your largest recent invoice was ${autopayMoney(autopay.largest_invoice, wording.currency)}. Anything over the limit comes to you to pay by hand.`}
                        </span>
                        {limitText !== '' && limit === null && (
                            <span
                                role="alert"
                                className="text-xs text-destructive"
                            >
                                Enter an amount like 5000 or 5,000.00.
                            </span>
                        )}
                    </div>

                    <div className="grid grid-cols-1 gap-2 rounded-lg bg-muted px-4 py-3">
                        <h3 className="font-semibold">How it works</h3>
                        <ul className="grid list-disc grid-cols-1 gap-1.5 pl-5">
                            <li>
                                You still receive each invoice when it’s issued.
                                It says the date it will be charged.
                            </li>
                            <li>
                                We charge the remaining balance on the due date,
                                never earlier.
                            </li>
                            <li>
                                If a charge fails we don’t retry. We tell you,
                                and the invoice waits for you to pay.
                            </li>
                            <li>
                                You can turn autopay off here at any time,
                                including before a due date.
                            </li>
                        </ul>
                    </div>

                    <div className="flex items-start gap-3">
                        <input
                            id="autopay-agree"
                            type="checkbox"
                            checked={agreed}
                            onChange={(event) =>
                                setAgreed(event.target.checked)
                            }
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <label
                            htmlFor="autopay-agree"
                            className="leading-snug wrap-anywhere"
                        >
                            {sentence ??
                                'Enter a limit to see exactly what you are authorizing.'}
                        </label>
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
                        disabled={busy || limit === null || !agreed}
                        onClick={submit}
                    >
                        {mode === 'change'
                            ? 'Save new limit'
                            : 'Turn on autopay'}
                    </Button>
                </DialogFooter>
                <p className="text-xs text-muted-foreground">
                    We record who agreed, when, and the exact wording above.{' '}
                    {wording.provider} can see that record.
                </p>
            </DialogContent>
        </Dialog>
    );
}

/** What the portal's invoice page is told about autopay on one invoice. */
export type PortalInvoiceAutopay = {
    state: string;
    state_label: string;
    amount: number;
    currency: string;
    limit_amount: number | null;
    method_label: string | null;
    will_charge_on: string | null;
    due_on: string | null;
    failure_reason: string | null;
    attempted_on: string | null;
    manage_href: string;
};

/**
 * Autopay on one invoice, as the client reads it: what will happen, what did,
 * and what is theirs to do. Sentences are composed from the server's state and
 * figures; nothing here decides eligibility.
 */
export function PortalInvoiceAutopayCard({
    autopay,
    payHref = '#pay',
}: {
    autopay: PortalInvoiceAutopay;
    payHref?: string;
}) {
    const amount = autopayMoney(autopay.amount, autopay.currency);
    const limit =
        autopay.limit_amount === null
            ? null
            : autopayMoney(autopay.limit_amount, autopay.currency);
    const copy = ((): {
        title: string;
        body: string;
        tone: 'ok' | 'bad' | 'plain';
    } => {
        switch (autopay.state) {
            case 'will_charge':
                return {
                    title: `Autopay on ${formatDay(autopay.will_charge_on)}`,
                    body: `We’ll charge ${autopay.method_label ?? 'your default method'} for ${amount} on the due date. Nothing to do.`,
                    tone: 'ok',
                };
            case 'processing':
                return {
                    title: 'Autopay is processing',
                    body: `We charged ${autopay.method_label ?? 'your default method'} for ${amount}. The payment is still being confirmed.`,
                    tone: 'plain',
                };
            case 'charged':
                return {
                    title: 'Paid by autopay',
                    body: `We charged ${autopay.method_label ?? 'your default method'}${autopay.attempted_on === null ? '' : ` on ${formatTimestamp(autopay.attempted_on)}`}.`,
                    tone: 'ok',
                };
            case 'failed':
                return {
                    title: 'Autopay couldn’t charge your card',
                    body: `${autopay.attempted_on === null ? '' : `${formatDay(autopay.attempted_on.slice(0, 10))}: `}${(autopay.failure_reason ?? 'The charge did not go through.').replace(/([^.!?])$/, '$1.')} We won’t try again automatically. Please pay by hand.`,
                    tone: 'bad',
                };
            case 'over_limit':
                return {
                    title: 'This invoice won’t be charged automatically',
                    body: `${amount} is over your${limit === null ? '' : ` ${limit}`} autopay limit. Pay it here${autopay.due_on === null ? '' : ` by ${formatDay(autopay.due_on)}`}.`,
                    tone: 'plain',
                };
            case 'no_default_method':
                return {
                    title: 'This invoice won’t be charged automatically',
                    body: 'There is no default payment method to charge. Pay it here, or save a method for autopay.',
                    tone: 'plain',
                };
            case 'skipped':
                return {
                    title: 'This invoice won’t be charged automatically',
                    body: `Autopay is skipped for this one. Pay it here${autopay.due_on === null ? '' : ` by ${formatDay(autopay.due_on)}`}.`,
                    tone: 'plain',
                };
            default:
                return {
                    title: statusLabel(autopay.state),
                    body: 'Autopay is checking this invoice. Please contact us before paying by hand.',
                    tone: 'plain',
                };
        }
    })();
    const payable = [
        'failed',
        'over_limit',
        'no_default_method',
        'skipped',
    ].includes(autopay.state);

    return (
        <Card aria-labelledby="invoice-autopay-heading">
            <CardContent className="grid grid-cols-1 gap-3 text-sm">
                <div className="flex min-w-0 items-center gap-2">
                    {copy.tone === 'bad' ? (
                        <CircleAlertIcon
                            aria-hidden="true"
                            className="size-4 shrink-0 text-destructive"
                        />
                    ) : (
                        <ClockIcon
                            aria-hidden="true"
                            className="size-4 shrink-0"
                        />
                    )}
                    <h2
                        id="invoice-autopay-heading"
                        className="min-w-0 font-semibold wrap-anywhere"
                    >
                        {copy.title}
                    </h2>
                </div>
                <p className="leading-relaxed wrap-anywhere text-muted-foreground">
                    {copy.body}
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    {payable && (
                        <Button
                            render={<a href={payHref} />}
                            variant="default"
                            size="sm"
                        >
                            Pay {amount}
                        </Button>
                    )}
                    <a
                        href={autopay.manage_href}
                        className="text-sm underline underline-offset-4"
                    >
                        Manage autopay
                    </a>
                </div>
            </CardContent>
        </Card>
    );
}
