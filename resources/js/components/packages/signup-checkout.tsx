import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AddMethodDialog } from '@/components/billing/payment-methods-portal';
import { StripeCardPayment } from '@/components/billing/stripe-card-payment';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDay, formatShortDay } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { paymentStateLabel } from '@/lib/packages';
import type {
    PortalCheckout,
    PortalServicePayment,
    SignupChargeLine,
} from '@/types/packages';

function ChargeLine({
    line,
    currency,
}: {
    line: SignupChargeLine;
    currency: string;
}) {
    const dates =
        line.period_start !== null && line.period_end !== null
            ? `${formatShortDay(line.period_start)} to ${formatShortDay(line.period_end)}`
            : null;

    return (
        <li className="flex min-w-0 items-baseline justify-between gap-4">
            <span className="min-w-0 wrap-anywhere">
                {line.description}
                {dates !== null && (
                    <span className="block text-xs text-muted-foreground">
                        {dates}
                    </span>
                )}
            </span>
            <span className="shrink-0 tabular-nums">
                {formatMoney(line.amount, currency)}
            </span>
        </li>
    );
}

function AutopayBlock({
    autopay,
    companyName,
}: {
    autopay: NonNullable<PortalCheckout['autopay']>;
    companyName: string;
}) {
    return (
        <div className="grid grid-cols-1 gap-1">
            <p className="font-medium">Autopay required</p>
            <p className="wrap-anywhere text-muted-foreground">
                This service is paid by autopay, up to {autopay.limit_label} per
                invoice, for {companyName}.
            </p>
        </div>
    );
}

/**
 * The autopay step of a sign-up (ACC-4): save a card through the existing
 * save-card dialog, then read the server's exact sentence and authorize it.
 * The wording, limit, URL and fields are the server's; nothing is rebuilt here.
 */
function AutopayStep({
    autopay,
    cardSetup,
    companyName,
}: {
    autopay: NonNullable<PortalCheckout['autopay']>;
    cardSetup: PortalCheckout['card_setup'];
    companyName: string;
}) {
    const [agreed, setAgreed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [adding, setAdding] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const enableUrl = autopay.enable_url;
    const fields = autopay.enable_fields;

    if (autopay.satisfied) {
        return (
            <p role="status" className="wrap-anywhere">
                Autopay is on, up to {autopay.limit_label} per invoice.
            </p>
        );
    }

    const enable = () => {
        if (enableUrl === null || fields === null || !agreed || busy) {
            return;
        }

        setBusy(true);
        setError(null);
        router.post(
            enableUrl,
            { ...fields, authorized: true },
            {
                preserveScroll: true,
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
        <div className="grid grid-cols-1 gap-3">
            <AutopayBlock autopay={autopay} companyName={companyName} />
            {autopay.wording === null ? (
                cardSetup === null ? (
                    <p className="wrap-anywhere text-muted-foreground">
                        Someone with company-wide access saves a card and turns
                        autopay on.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-2">
                        <p className="wrap-anywhere text-muted-foreground">
                            Save a card first. It becomes your default payment
                            method, and you then confirm autopay here.
                        </p>
                        <div>
                            <Button
                                variant="outline"
                                onClick={() => setAdding(true)}
                            >
                                Save a card
                            </Button>
                        </div>
                        {adding && (
                            <AddMethodDialog
                                providerName="your service provider"
                                companyName={companyName}
                                setup={{
                                    setup_href: cardSetup.setup_url,
                                    confirm_href: cardSetup.confirm_url,
                                    publishable_key: cardSetup.publishable_key,
                                }}
                                onClose={() => setAdding(false)}
                            />
                        )}
                    </div>
                )
            ) : enableUrl === null || fields === null ? (
                <p className="wrap-anywhere text-muted-foreground">
                    Someone with company-wide access turns autopay on.
                </p>
            ) : (
                <div className="grid grid-cols-1 gap-3">
                    <blockquote className="rounded-lg bg-muted/40 p-3 wrap-anywhere">
                        {autopay.wording}
                    </blockquote>
                    <label
                        htmlFor="signup-autopay-agree"
                        className="flex min-w-0 items-start gap-2"
                    >
                        <input
                            id="signup-autopay-agree"
                            type="checkbox"
                            className="mt-0.5"
                            checked={agreed}
                            onChange={(event) =>
                                setAgreed(event.target.checked)
                            }
                        />
                        <span className="min-w-0 wrap-anywhere">
                            I authorize this, and can turn autopay off at any
                            time from Billing.
                        </span>
                    </label>
                    {error !== null && (
                        <p
                            role="alert"
                            className="wrap-anywhere text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    <div>
                        <Button disabled={!agreed || busy} onClick={enable}>
                            Turn on autopay
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** What the sign-up is still waiting for, in words. */
function waitingText(payment: PortalServicePayment): string | null {
    if (payment.waiting_for.length === 0) {
        return null;
    }

    const parts = payment.waiting_for.map((wait) =>
        wait === 'payment' ? 'the sign-up payment' : 'autopay',
    );

    return `Your service starts once ${parts.join(' and ')} ${parts.length > 1 ? 'are' : 'is'} done.`;
}

/**
 * What sign-up asks for (ACC-3, ACC-4, ACC-6): "Due now" as an estimate before
 * acceptance and as the invoice's own lines after, the way to pay it, autopay
 * consent when required, and the deadline. Every URL and the consent wording
 * come finished from the server; a null URL renders nothing to act on.
 */
export function SignupCheckout({
    checkout,
    payment,
    companyName,
    accepted,
}: {
    checkout: PortalCheckout;
    payment: PortalServicePayment;
    companyName: string;
    accepted: boolean;
}) {
    const { due_now: dueNow, autopay } = checkout;
    const state = paymentStateLabel(payment.state);
    const waiting = waitingText(payment);
    const owed = dueNow.balance ?? dueNow.total;
    const showPay =
        accepted &&
        owed > 0 &&
        (checkout.payment_options !== null || checkout.pay_url !== null);

    return (
        <Card aria-label="Due now">
            <CardHeader>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <CardTitle>
                        {dueNow.estimate ? 'Due now (estimate)' : 'Due now'}
                    </CardTitle>
                    {accepted && state !== null && (
                        <Badge variant="outline">{state}</Badge>
                    )}
                </div>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {dueNow.lines.length > 0 && (
                    <ul className="grid grid-cols-1 gap-2">
                        {dueNow.lines.map((line, index) => (
                            <ChargeLine
                                key={`${line.kind}-${index}`}
                                line={line}
                                currency={dueNow.currency}
                            />
                        ))}
                    </ul>
                )}
                <div className="flex items-baseline justify-between gap-4 border-t pt-3 font-medium">
                    <span>Total</span>
                    <span className="tabular-nums">
                        {formatMoney(dueNow.total, dueNow.currency)}
                    </span>
                </div>
                {dueNow.balance !== undefined &&
                    dueNow.balance !== dueNow.total && (
                        <div className="flex items-baseline justify-between gap-4">
                            <span>Still owed</span>
                            <span className="tabular-nums">
                                {formatMoney(dueNow.balance, dueNow.currency)}
                            </span>
                        </div>
                    )}
                {dueNow.estimate && (
                    <p className="wrap-anywhere text-muted-foreground">
                        This is an estimate. The invoice is made when you sign,
                        and its lines are final then.
                        {checkout.pay_before_activation
                            ? ' The service starts once it is paid.'
                            : ''}
                    </p>
                )}

                {showPay && (
                    <div className="grid grid-cols-1 gap-3">
                        {checkout.payment_options !== null && (
                            <StripeCardPayment
                                intentHref={
                                    checkout.payment_options.intent_href
                                }
                                publishableKey={
                                    checkout.payment_options.publishable_key
                                }
                                primary
                            />
                        )}
                        {checkout.pay_url !== null && (
                            // A plain link: a card row's trailing action is never a bordered one.
                            <a
                                href={checkout.pay_url}
                                className="text-sm font-medium underline underline-offset-4"
                            >
                                Open the invoice to pay
                            </a>
                        )}
                    </div>
                )}

                {autopay !== null && (
                    <AutopayStep
                        autopay={autopay}
                        cardSetup={checkout.card_setup}
                        companyName={companyName}
                    />
                )}

                {waiting !== null && (
                    <p role="status" className="wrap-anywhere">
                        {waiting}
                    </p>
                )}
                {payment.lapses_on !== null && (
                    <p className="wrap-anywhere text-muted-foreground">
                        {accepted
                            ? `Pay by ${formatDay(payment.lapses_on)}, or this sign-up lapses.`
                            : `This offer is open until ${formatDay(payment.lapses_on)}.`}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
