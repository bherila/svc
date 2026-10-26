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
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { apiRequest, newIdempotencyKey } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';

/**
 * Payment reminders on an invoice, as the operator sees them (#438): the
 * API's own `InvoiceReminders`, plus the finished URL that sends one, null
 * when none may be sent now.
 */
export type InvoiceRemindersState = ApiSchema<'InvoiceReminders'> & {
    send_href: string | null;
};

/** The client's automatic cadence, with the finished URL that switches it. */
export type CompanyRemindersState = ApiSchema<'CompanyPaymentReminders'> & {
    update_href: string;
};

const payLinkWords: Record<string, string> = {
    square: 'a link to pay on Square',
    svc_portal: 'a link to the invoice in the client portal, and the PDF',
    none: 'the invoice PDF',
};

const payLinkLabels: Record<string, string> = {
    square: 'Square page',
    svc_portal: 'Client portal',
    none: 'PDF',
};

function outcomeVariant(
    status: string,
): 'destructive' | 'outline' | 'secondary' {
    if (status === 'failed' || status === 'cancelled') {
        return 'destructive';
    }

    return status === 'sent' ? 'secondary' : 'outline';
}

/**
 * Send a reminder now, see where the client's cadence stands for this
 * invoice, and read every reminder already sent. Nothing here decides
 * eligibility: the server says whether a reminder may go and why not.
 */
export function PaymentRemindersCard({
    reminders,
}: {
    reminders: InvoiceRemindersState;
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    // One key per logical send, kept across retries until one succeeds.
    // Sending changes nothing the invoice version could refuse, so a send
    // that reached the server but whose answer was lost must replay, not
    // email the client a second time.
    const [sendKey, setSendKey] = useState<string | null>(null);
    const [notice, setNotice] = useState<{
        tone: 'status' | 'alert';
        text: string;
    } | null>(null);
    const manual = reminders.manual;
    const cadence = reminders.cadence;

    const send = async () => {
        if (busy || reminders.send_href === null) {
            return;
        }

        const body: ApiSchema<'InvoiceReminderSendRequest'> = {
            expected_version: reminders.invoice_version,
            confirm: true,
        };
        const key = sendKey ?? newIdempotencyKey();
        setSendKey(key);
        setBusy(true);
        const result = await apiRequest<ApiSchema<'InvoiceReminder'>>(
            'POST',
            reminders.send_href,
            body,
            key,
        );
        setConfirming(false);

        if (result.ok) {
            setSendKey(null);
            setNotice(
                result.data.status === 'sent'
                    ? {
                          tone: 'status',
                          text: `Reminder sent to ${result.data.recipients.join(', ')}.`,
                      }
                    : {
                          tone: 'alert',
                          text:
                              result.data.error_summary ??
                              `Reminder ${statusLabel(result.data.status).toLowerCase()}.`,
                      },
            );
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setNotice({ tone: 'alert', text: result.message });
        setBusy(false);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Payment reminders</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {notice !== null && (
                    <p
                        role={notice.tone}
                        className={
                            notice.tone === 'alert'
                                ? 'rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 wrap-anywhere text-destructive'
                                : 'rounded-lg border border-border px-3 py-2 wrap-anywhere'
                        }
                    >
                        {notice.text}
                    </p>
                )}

                <div className="grid grid-cols-1 gap-2">
                    {reminders.send_href !== null &&
                    manual.pay_link !== null ? (
                        <div className="flex min-w-0 flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => setConfirming(true)}
                            >
                                Send reminder
                            </Button>
                            <span className="min-w-0 wrap-anywhere text-muted-foreground">
                                Emails {manual.recipients.join(', ')} with{' '}
                                {payLinkWords[manual.pay_link] ??
                                    statusLabel(manual.pay_link)}
                                .
                            </span>
                        </div>
                    ) : (
                        <p className="wrap-anywhere text-muted-foreground">
                            {manual.refusal?.message ??
                                'A reminder cannot be sent for this invoice.'}
                        </p>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-2">
                    <p className="wrap-anywhere">
                        {cadence.enabled
                            ? `Automatic reminders are on for this client${cadence.enabled_at === null ? '' : ` since ${formatTimestamp(cadence.enabled_at)}`}.`
                            : 'Automatic reminders are off for this client. Turn them on from the client’s settings.'}
                    </p>
                    {cadence.enabled && cadence.hold !== null && (
                        <p className="wrap-anywhere text-muted-foreground">
                            {cadence.hold.message}
                        </p>
                    )}
                    <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                        {cadence.schedule.map((row) => (
                            <li
                                key={row.step}
                                className="flex min-w-0 flex-wrap items-center gap-2"
                            >
                                <span>{row.label}</span>
                                <span className="text-muted-foreground">
                                    {row.scheduled_on === null
                                        ? 'No due date'
                                        : formatDay(row.scheduled_on)}
                                </span>
                                <Badge variant="outline">
                                    {statusLabel(row.state)}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>

                {reminders.reminders.length === 0 ? (
                    <p className="text-muted-foreground">
                        No reminders have been sent for this invoice.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Reminder</TableHead>
                                    <TableHead className="min-w-48">
                                        To
                                    </TableHead>
                                    <TableHead>Link</TableHead>
                                    <TableHead>Result</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reminders.reminders.map((reminder) => (
                                    <TableRow key={reminder.id}>
                                        <TableCell>
                                            {formatTimestamp(
                                                reminder.sent_at ??
                                                    reminder.failed_at ??
                                                    reminder.queued_at,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {reminder.step === null
                                                ? `By ${reminder.triggered_by_name ?? statusLabel(reminder.triggered_by)}`
                                                : (cadence.schedule.find(
                                                      (row) =>
                                                          row.step ===
                                                          reminder.step,
                                                  )?.label ??
                                                  statusLabel(reminder.step))}
                                        </TableCell>
                                        <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                            {reminder.recipients.join(', ')}
                                            {reminder.error_summary !==
                                                null && (
                                                <span className="block text-muted-foreground">
                                                    {reminder.error_summary}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {payLinkLabels[reminder.pay_link] ??
                                                statusLabel(reminder.pay_link)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={outcomeVariant(
                                                    reminder.status,
                                                )}
                                            >
                                                {statusLabel(reminder.status)}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <AlertDialog open={confirming} onOpenChange={setConfirming}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Send a payment reminder?
                            </AlertDialogTitle>
                            <AlertDialogDescription className="wrap-anywhere">
                                SVC will email {manual.recipients.join(', ')}{' '}
                                now, with{' '}
                                {manual.pay_link === null
                                    ? 'the invoice'
                                    : (payLinkWords[manual.pay_link] ??
                                      statusLabel(manual.pay_link))}
                                .
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={busy}>
                                Cancel
                            </AlertDialogCancel>
                            <AlertDialogAction
                                disabled={busy}
                                onClick={(event) => {
                                    event.preventDefault();
                                    void send();
                                }}
                            >
                                Send reminder
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </CardContent>
        </Card>
    );
}

/**
 * The client's automatic cadence on its settings page: off until switched on
 * here, through the same operation agents use.
 */
export function PaymentReminderCadenceCard({
    cadence,
}: {
    cadence: CompanyRemindersState;
}) {
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const toggle = async (enabled: boolean) => {
        if (busy) {
            return;
        }

        const body: ApiSchema<'CompanyPaymentRemindersRequest'> = {
            expected_version: cadence.version,
            enabled,
        };
        setBusy(true);
        const result = await apiRequest('PUT', cadence.update_href, body);

        if (result.ok) {
            setNotice(null);
            void refreshPageProps({ onFinish: () => setBusy(false) });

            return;
        }

        setNotice(result.message);
        setBusy(false);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Payment reminders</CardTitle>
            </CardHeader>
            <CardContent className="grid max-w-xl grid-cols-1 gap-3 text-sm">
                <div className="flex items-center gap-3">
                    <Switch
                        id="payment-reminder-cadence"
                        checked={cadence.enabled}
                        disabled={busy}
                        onCheckedChange={(next) => void toggle(next === true)}
                    />
                    <Label htmlFor="payment-reminder-cadence">
                        Automatically remind this client about unpaid invoices
                    </Label>
                </div>
                <p className="wrap-anywhere text-muted-foreground">
                    {cadence.steps.map((step) => step.label).join(', ')}, sent
                    between 09:00 and 17:00 to the billing address and portal
                    users.{' '}
                    {cadence.enabled && cadence.enabled_at !== null
                        ? `On since ${formatTimestamp(cadence.enabled_at)}; steps that fell due before then are not sent.`
                        : 'Turning this on never sends reminders for steps that have already passed.'}{' '}
                    Invoices Square collects are reminded about with
                    Square&rsquo;s own payment page, and not on this schedule
                    while Square charges a card on file or sends its own
                    reminders.
                </p>
                {notice !== null && (
                    <p
                        role="alert"
                        className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 wrap-anywhere text-destructive"
                    >
                        {notice}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
