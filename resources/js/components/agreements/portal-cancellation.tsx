import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatDay, formatTimestampDate } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import type { PortalCancellation } from '@/types/agreement';

/** The client's cancellation terms in one sentence, as the mockup words them. */
export function cancellationTermsSentence(
    panel: Pick<
        PortalCancellation,
        'notice_days' | 'minimum_term_months' | 'minimum_term_ends_on'
    >,
): string {
    const notice =
        panel.notice_days === 0
            ? 'no notice'
            : `${panel.notice_days} days’ notice`;

    if (panel.minimum_term_months === null) {
        return `${notice.charAt(0).toUpperCase()}${notice.slice(1)}, ending at the close of a billing period`;
    }

    return `${panel.minimum_term_months}-month minimum${panel.minimum_term_ends_on === null ? '' : ` (to ${formatDay(panel.minimum_term_ends_on)})`}, then ${notice}`;
}

/** Post a portal cancellation act and report its refusal, if any. */
function post(
    url: string,
    data: Record<string, string>,
    onError: (message: string) => void,
    onFinish: () => void,
) {
    router.post(url, data, {
        preserveScroll: true,
        onError: (errors) =>
            onError(
                Object.values(errors)[0] ??
                    'This could not be done. Reload the page and try again.',
            ),
        onFinish,
    });
}

/**
 * Ending an agreement from the portal (CAN-2, CAN-3, CAN-4, EDGE-9, EDGE-11).
 *
 * Everything is the server's: the dates offered, whether this viewer may act
 * (a URL or null), what is pending or scheduled, and what is still owed. The
 * browser never works a date out. In self-serve mode cancelling schedules the
 * end at once and can be undone until then; in request mode it sends a
 * request the provider decides, which can be withdrawn while it waits.
 */
export function PortalCancellationCard({
    panel,
    serviceName,
}: {
    panel: PortalCancellation;
    serviceName: string;
}) {
    const selfServe = panel.mode === 'self_serve';
    const [endDate, setEndDate] = useState(panel.offered_end_dates[0] ?? '');
    const [reason, setReason] = useState('');
    const [confirming, setConfirming] = useState<
        'cancel' | 'withdraw' | 'undo' | null
    >(null);
    const items = panel.offered_end_dates.map((date, index) => ({
        value: date,
        label: `${formatDay(date)}${index === 0 ? ' (earliest)' : ''}`,
    }));

    const act = (url: string, data: Record<string, string>) =>
        new Promise<string | null>((resolve) => {
            let refusal: string | null = null;
            post(
                url,
                data,
                (message) => {
                    refusal = message;
                },
                () => resolve(refusal),
            );
        });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Cancellation</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {panel.scheduled_end !== null ? (
                    <div className="grid grid-cols-1 gap-2">
                        <p className="font-medium wrap-anywhere">
                            Ends {formatDay(panel.scheduled_end.ends_on)}
                        </p>
                        <p className="wrap-anywhere text-muted-foreground">
                            {panel.cancelled_by_client
                                ? `Cancelled by you on ${formatTimestampDate(panel.scheduled_end.scheduled_at)}.`
                                : `Cancellation accepted by ${panel.provider_name}.`}{' '}
                            The service continues until then.
                        </p>
                        {panel.undo_url !== null && (
                            <div>
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirming('undo')}
                                >
                                    Undo cancellation…
                                </Button>
                                <ConfirmedAction
                                    open={confirming === 'undo'}
                                    onOpenChange={(open) =>
                                        setConfirming(open ? 'undo' : null)
                                    }
                                    title={`Keep ${serviceName}?`}
                                    description={`The cancellation is undone and ${serviceName} continues as before. ${panel.provider_name} is told.`}
                                    confirmLabel="Undo cancellation"
                                    run={() => act(panel.undo_url ?? '', {})}
                                />
                            </div>
                        )}
                    </div>
                ) : panel.pending_request !== null ? (
                    <div className="grid grid-cols-1 gap-2">
                        <p className="font-medium wrap-anywhere">
                            Cancellation requested ·{' '}
                            {formatDay(panel.pending_request.requested_end_on)}
                        </p>
                        <p className="wrap-anywhere text-muted-foreground">
                            Sent{' '}
                            {formatTimestampDate(
                                panel.pending_request.requested_at,
                            )}{' '}
                            · waiting for {panel.provider_name}. The service
                            continues while it waits.
                        </p>
                        {panel.pending_request.reason !== null && (
                            <p className="wrap-anywhere whitespace-pre-wrap">
                                {panel.pending_request.reason}
                            </p>
                        )}
                        {panel.withdraw_url !== null && (
                            <div>
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirming('withdraw')}
                                >
                                    Withdraw request…
                                </Button>
                                <ConfirmedAction
                                    open={confirming === 'withdraw'}
                                    onOpenChange={(open) =>
                                        setConfirming(open ? 'withdraw' : null)
                                    }
                                    title="Withdraw your cancellation request?"
                                    description={`${serviceName} continues as before, and ${panel.provider_name} no longer has a request to decide.`}
                                    confirmLabel="Withdraw request"
                                    run={() =>
                                        act(panel.withdraw_url ?? '', {})
                                    }
                                />
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-3">
                        <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div className="grid grid-cols-1 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Your terms
                                </dt>
                                <dd className="wrap-anywhere">
                                    {cancellationTermsSentence(panel)}
                                </dd>
                            </div>
                            <div className="grid grid-cols-1 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Earliest end
                                </dt>
                                <dd>{formatDay(panel.earliest_end_on)}</dd>
                            </div>
                        </dl>
                        {panel.cancel_url === null ? (
                            <p className="wrap-anywhere text-muted-foreground">
                                {panel.cancel_refusal?.message ??
                                    'Someone with access to the whole company can cancel this.'}
                            </p>
                        ) : (
                            <>
                                <FormField label="End on">
                                    {(id, aria) => (
                                        <Select
                                            items={items}
                                            value={endDate}
                                            onValueChange={(next) => {
                                                if (typeof next === 'string') {
                                                    setEndDate(next);
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                id={id}
                                                {...aria}
                                                className="w-full sm:w-72"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {items.map((item) => (
                                                    <SelectItem
                                                        key={item.value}
                                                        value={item.value}
                                                    >
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </FormField>
                                <FormField label="Reason (optional)">
                                    {(id, aria) => (
                                        <Textarea
                                            id={id}
                                            {...aria}
                                            rows={3}
                                            maxLength={1000}
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                    )}
                                </FormField>
                                <p className="wrap-anywhere text-muted-foreground">
                                    {selfServe
                                        ? `The service and its billing continue until the end date. You can undo this until then.`
                                        : `${panel.provider_name} reviews cancellation requests. The service and its billing continue until the end date.`}
                                </p>
                                <div>
                                    <Button
                                        variant={
                                            selfServe
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        onClick={() => setConfirming('cancel')}
                                        disabled={endDate === ''}
                                    >
                                        {selfServe
                                            ? `Cancel on ${formatDay(endDate)}…`
                                            : 'Request cancellation…'}
                                    </Button>
                                </div>
                                <ConfirmedAction
                                    open={confirming === 'cancel'}
                                    onOpenChange={(open) =>
                                        setConfirming(open ? 'cancel' : null)
                                    }
                                    title={
                                        selfServe
                                            ? `Cancel ${serviceName} on ${formatDay(endDate)}?`
                                            : `Request cancellation of ${serviceName}?`
                                    }
                                    description={
                                        selfServe
                                            ? `${serviceName} ends on ${formatDay(endDate)} and stays active until then. You can undo this until that date.`
                                            : `${panel.provider_name} is asked to end ${serviceName} on ${formatDay(endDate)}, and decides.`
                                    }
                                    confirmLabel={
                                        selfServe
                                            ? `Cancel on ${formatDay(endDate)}`
                                            : 'Send request'
                                    }
                                    run={() =>
                                        act(panel.cancel_url ?? '', {
                                            end_date: endDate,
                                            ...(reason.trim() === ''
                                                ? {}
                                                : { reason: reason.trim() }),
                                        })
                                    }
                                >
                                    <OwedNote panel={panel} />
                                </ConfirmedAction>
                            </>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/** EDGE-11: what is owed stays owed, and never stands in the way. */
function OwedNote({ panel }: { panel: PortalCancellation }) {
    const owed = panel.balance;

    if (owed === null || owed.amount_due === null || owed.amount_due <= 0) {
        return null;
    }

    return (
        <p className="rounded-md bg-muted px-3 py-2 text-sm wrap-anywhere">
            {owed.overdue && owed.overdue_amount !== null
                ? `${formatMoney(owed.overdue_amount, owed.currency)} is overdue`
                : `${formatMoney(owed.amount_due, owed.currency)} is due`}{' '}
            on {owed.invoice_count === 1 ? 'an invoice' : 'invoices'} already
            issued. Cancelling does not change it: it stays owed.
        </p>
    );
}
