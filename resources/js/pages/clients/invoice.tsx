import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { InvoiceLineRows } from '@/components/billing/invoice-line-detail';
import type { InvoiceLineItem } from '@/components/billing/invoice-line-detail';
import { SendInvoiceDialog } from '@/components/billing/send-invoice-dialog';
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
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import {
    PAYMENT_METHOD_OTHER,
    PAYMENT_METHODS,
    predatesInvoiceIssue,
    predatesInvoiceIssueWarning,
} from '@/lib/payments';
import { todayIn } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { CompanyInvoice } from '@/types/clients';
import type {
    InvoiceDelivery,
    InvoiceEmailContext,
} from '@/types/invoice-email';

type InvoiceLine = {
    id: string;
    type: string;
    description: string;
    quantity: number;
    hours: number | null;
    line_date: string | null;
    unit_amount: number;
    tax_amount?: number;
    total_amount: number;
    money_correctable?: boolean;
};

function correctionFields(lines: InvoiceLine[]) {
    return lines.map((line) => ({
        id: line.id,
        description: line.description,
        quantity: String(line.quantity),
        unit_amount: (line.unit_amount / 100).toFixed(2),
        tax_amount: ((line.tax_amount ?? 0) / 100).toFixed(2),
        money_correctable: line.money_correctable ?? false,
    }));
}

type InvoicePayment = {
    id: string;
    status: string;
    method: string | null;
    reference: string | null;
    received_on: string | null;
    /**
     * Where a corrected date is sent, or null for a viewer who may not correct
     * one. A finished URL rather than an id and a boolean, like every other
     * capability on this page.
     */
    correct_date_href: string | null;
    amount: number;
    refunded_amount: number;
    currency: string | null;
};

/**
 * What an operator may do to this invoice, as the server sees it.
 *
 * Nulls rather than booleans, because the answer is a URL and the browser
 * should not be assembling one. Each is offered only where the invoice's status
 * admits it, and each endpoint authorizes again: a button nobody rendered is
 * not an authorization check.
 */
type InvoiceActions = {
    add_time?: string | null;
    issue: string | null;
    send: string | null;
    payment: string | null;
    void: string | null;
    correct?: string | null;
    hold_automatic?: string | null;
    release_automatic?: string | null;
};

type AdministratorNotification = {
    id: string;
    status: string;
    sent_at: string | null;
    failed_at: string | null;
    attempt_count: number;
    error_summary: string | null;
    invoice_revision: number;
};

/**
 * One invoice, inside the client it belongs to.
 *
 * The Invoices tab stays marked while this is open, because a row's detail is
 * still that tab rather than somewhere else — the chrome keeps saying which
 * client this is, which is the whole reason the invoice hangs off the client
 * route instead of a workspace-wide one.
 *
 * The lifecycle actions live here too. They used to sit on a workspace-wide
 * operations screen holding every client's everything, which meant issuing an
 * invoice started by leaving the client you were looking at and finding it
 * again in a longer list.
 *
 * Hours are shown beside quantity rather than instead of it. They are separate
 * columns on the line and can legitimately disagree: quantity is what was
 * billed, hours is what the ledger draws against, and a fee line has one
 * without the other.
 */
export default function ClientInvoiceDetail({
    company,
    invoices_href: invoicesHref,
    pdf_href: pdfHref,
    actions,
    email,
    deliveries,
    administrator_notification: administratorNotification = null,
    invoice,
    lines,
    line_detail: lineDetail,
    payments,
    timezone,
}: {
    company: { id: string; name: string };
    invoices_href: string;
    pdf_href: string;
    /** The workspace's calendar, which a payment's date is read on. */
    timezone: string;
    actions: InvoiceActions;
    /** Null for a viewer who cannot send, alongside a `send` action of null. */
    email: InvoiceEmailContext | null;
    deliveries: InvoiceDelivery[];
    administrator_notification?: AdministratorNotification | null;
    invoice: CompanyInvoice;
    lines: InvoiceLine[];
    /** The work behind each line, keyed by line id. Absent for a line with none. */
    line_detail: Record<string, InvoiceLineItem[]>;
    payments: InvoicePayment[];
}) {
    const [paying, setPaying] = useState(false);
    const [sending, setSending] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const [correctingInvoice, setCorrectingInvoice] = useState(false);
    const [correctionReason, setCorrectionReason] = useState('');
    const [correctionDueDate, setCorrectionDueDate] = useState(
        invoice.due_date ?? '',
    );
    const [correctionLines, setCorrectionLines] = useState(() =>
        correctionFields(lines),
    );
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState<string>('bank_transfer');
    // Only meaningful while `method` is "other": the name of the arrangement
    // that is not on the list. Stored as the method itself, so the row reads
    // like every other one rather than saying "other" and leaving the actual
    // answer nowhere.
    const [otherMethod, setOtherMethod] = useState('');
    const [reference, setReference] = useState('');
    // Set when the dialog opens rather than at mount, so a screen left open
    // overnight offers today rather than the day it was loaded.
    const [receivedOn, setReceivedOn] = useState('');
    // Which payment's date is being corrected, and to what. One at a time,
    // because a correction is a repair rather than an editing mode.
    const [correcting, setCorrecting] = useState<string | null>(null);
    const [correctedDate, setCorrectedDate] = useState('');
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const post = (
        href: string,
        data: Record<
            string,
            string | number | null | Array<Record<string, string | number>>
        > = {},
    ) => {
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(href, data, {
            preserveScroll: true,
            onSuccess: () => {
                setNotice(null);
                setPaying(false);
                setVoiding(false);
                setCorrectingInvoice(false);
                setCorrecting(null);
            },
            onError: (errors) =>
                setNotice(
                    Object.values(errors)[0] ??
                        'That action could not be completed.',
                ),
            onFinish: () => setBusy(false),
        });
    };

    const backHref = invoicesHref;
    const predatingPayments = payments.filter((payment) =>
        predatesInvoiceIssue(payment.received_on, invoice.issue_date),
    ).length;

    return (
        <WorkspaceShell activeModule="invoices">
            <Head title={invoice.invoice_number ?? 'Invoice'} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <header className="grid grid-cols-1 gap-1">
                    <Link
                        href={backHref}
                        className="text-sm wrap-anywhere text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {company.name} invoices
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold wrap-anywhere">
                            {invoice.invoice_number ?? 'Unnumbered invoice'}
                        </h1>
                        <Badge variant="outline">
                            {statusLabel(invoice.status)}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {invoice.issue_date === null
                            ? 'Not issued'
                            : `Issued ${formatDay(invoice.issue_date)}`}
                        {invoice.due_date !== null &&
                            ` · due ${formatDay(invoice.due_date)}`}
                    </p>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <a
                            href={pdfHref}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            View PDF
                        </a>
                        {actions.add_time && (
                            <Link
                                href={actions.add_time}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                Add time
                            </Link>
                        )}
                        {actions.issue !== null && (
                            <Button
                                size="sm"
                                disabled={busy}
                                onClick={() => post(actions.issue ?? '')}
                            >
                                Issue
                            </Button>
                        )}
                        {actions.send !== null && email !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setSending(true)}
                            >
                                Send to client
                            </Button>
                        )}
                        {(actions.correct ?? null) !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    // Inertia keeps this page mounted when a
                                    // correction refreshes its props. Start
                                    // every edit from that newest revision,
                                    // not from the state captured at mount.
                                    setCorrectionReason('');
                                    setCorrectionDueDate(
                                        invoice.due_date ?? '',
                                    );
                                    setCorrectionLines(correctionFields(lines));
                                    setCorrectingInvoice(true);
                                }}
                            >
                                Correct invoice
                            </Button>
                        )}
                        {(actions.hold_automatic ?? null) !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() =>
                                    post(actions.hold_automatic ?? '')
                                }
                            >
                                Hold automatic sending
                            </Button>
                        )}
                        {(actions.release_automatic ?? null) !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() =>
                                    post(actions.release_automatic ?? '')
                                }
                            >
                                Release automatic sending
                            </Button>
                        )}
                        {actions.payment !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    // Pre-filled with what is still owed,
                                    // because that is the payment being
                                    // recorded almost every time - and typing
                                    // a total again is how a digit goes
                                    // missing.
                                    setAmount(
                                        (invoice.balance_amount / 100).toFixed(
                                            2,
                                        ),
                                    );
                                    setReceivedOn(todayIn(timezone));
                                    // Cleared rather than carried. The
                                    // dialog's state outlives one submission,
                                    // and a reference identifies one payment:
                                    // the second cheque recorded in a sitting
                                    // would otherwise arrive under the first
                                    // one's number. The method is deliberately
                                    // kept - nothing downstream reads it, and
                                    // an operator who always takes cheques
                                    // should not re-pick it every time.
                                    setReference('');
                                    setPaying(true);
                                }}
                            >
                                Record payment
                            </Button>
                        )}
                        {actions.void !== null && (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => setVoiding(true)}
                            >
                                Void
                            </Button>
                        )}
                    </div>

                    {notice !== null && (
                        <p
                            role="alert"
                            className="mt-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {notice}
                        </p>
                    )}

                    {paying && (
                        <form
                            className="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-border p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto] lg:items-end"
                            onSubmit={(event) => {
                                event.preventDefault();

                                const chosenMethod =
                                    method === PAYMENT_METHOD_OTHER
                                        ? otherMethod.trim()
                                        : method;

                                // Refused here rather than sent as an empty
                                // string for the server to reject: "other"
                                // with no name is a payment whose method
                                // nobody would be able to read back.
                                if (chosenMethod === '') {
                                    setNotice(
                                        'Name the payment method, or choose one from the list.',
                                    );

                                    return;
                                }

                                post(actions.payment ?? '', {
                                    // Minor units, the way every amount in this
                                    // system travels. Rounded rather than
                                    // truncated: 12.34 is not exactly
                                    // representable, and truncating it records
                                    // a cent less than the client paid.
                                    amount: Math.round(
                                        Number.parseFloat(amount || '0') * 100,
                                    ),
                                    currency: invoice.currency,
                                    method: chosenMethod,
                                    reference:
                                        reference === '' ? null : reference,
                                    // The day the money arrived, which is not
                                    // the day it was typed in. Sent as the
                                    // same `YYYY-MM-DD` the operations screen
                                    // sends, so both doors write the column
                                    // the same shape.
                                    received_on: receivedOn,
                                });
                            }}
                        >
                            <div className="grid grid-cols-1 gap-2">
                                <Label htmlFor="payment-amount">Amount</Label>
                                <Input
                                    id="payment-amount"
                                    inputMode="decimal"
                                    value={amount}
                                    onChange={(event) =>
                                        setAmount(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid grid-cols-1 gap-2">
                                <Label htmlFor="payment-method">Method</Label>
                                <Select
                                    // Without `items` the trigger renders the
                                    // stored value, so this field read
                                    // "bank_transfer" back at the operator who
                                    // had just picked "Bank transfer".
                                    items={PAYMENT_METHODS}
                                    value={method}
                                    onValueChange={(next) => {
                                        // Base UI can emit null on clear; there
                                        // is nothing to clear to here, so an
                                        // empty change leaves the choice alone
                                        // rather than blanking a required
                                        // field.
                                        if (typeof next === 'string') {
                                            setMethod(next);
                                        }
                                    }}
                                >
                                    <SelectTrigger
                                        id="payment-method"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PAYMENT_METHODS.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {method === PAYMENT_METHOD_OTHER && (
                                    <Input
                                        aria-label="Name of the payment method"
                                        placeholder="Name the method"
                                        value={otherMethod}
                                        onChange={(event) =>
                                            setOtherMethod(event.target.value)
                                        }
                                    />
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-2">
                                <Label htmlFor="payment-received-on">
                                    Received
                                </Label>
                                <Input
                                    id="payment-received-on"
                                    type="date"
                                    // Today on the workspace's calendar, not
                                    // the browser's. No floor here: how far
                                    // back this workspace records payments is
                                    // the service's policy, and it names the
                                    // earliest acceptable date when it
                                    // refuses one - a bound restated in the
                                    // browser is a second copy of that policy
                                    // that can disagree with it.
                                    max={todayIn(timezone)}
                                    value={receivedOn}
                                    onChange={(event) =>
                                        setReceivedOn(event.target.value)
                                    }
                                />
                                {predatesInvoiceIssue(
                                    receivedOn,
                                    invoice.issue_date,
                                ) && (
                                    <p
                                        role="status"
                                        className="text-xs wrap-anywhere text-amber-700 dark:text-amber-500"
                                    >
                                        {predatesInvoiceIssueWarning(
                                            invoice.issue_date ?? '',
                                        )}
                                    </p>
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-2">
                                <Label htmlFor="payment-reference">
                                    Reference
                                </Label>
                                <Input
                                    id="payment-reference"
                                    value={reference}
                                    onChange={(event) =>
                                        setReference(event.target.value)
                                    }
                                />
                            </div>
                            <Button type="submit" disabled={busy}>
                                Record
                            </Button>
                        </form>
                    )}

                    {correctingInvoice && (
                        <form
                            className="mt-3 grid grid-cols-1 gap-4 rounded-lg border border-amber-500/40 p-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                post(actions.correct ?? '', {
                                    expected_revision:
                                        invoice.document_revision ?? 1,
                                    reason: correctionReason,
                                    due_date:
                                        correctionDueDate === ''
                                            ? null
                                            : correctionDueDate,
                                    lines: correctionLines.map((line) => ({
                                        id: line.id,
                                        description: line.description,
                                        quantity: line.quantity,
                                        unit_amount: Math.round(
                                            Number.parseFloat(
                                                line.unit_amount || '0',
                                            ) * 100,
                                        ),
                                        tax_amount: Math.round(
                                            Number.parseFloat(
                                                line.tax_amount || '0',
                                            ) * 100,
                                        ),
                                    })),
                                });
                            }}
                        >
                            <p className="text-sm wrap-anywhere text-muted-foreground">
                                Corrections are limited to an unpaid invoice
                                that has never been sent to the client. Existing
                                line identities and allocations stay in place.
                                Generated and allocated lines allow wording
                                changes only; use void and regeneration for
                                accounting changes.
                            </p>
                            <div className="grid max-w-xs grid-cols-1 gap-2">
                                <Label htmlFor="correction-due-date">
                                    Due date
                                </Label>
                                <Input
                                    id="correction-due-date"
                                    type="date"
                                    value={correctionDueDate}
                                    onChange={(event) =>
                                        setCorrectionDueDate(event.target.value)
                                    }
                                />
                            </div>
                            {correctionLines.map((line, index) => (
                                <div
                                    key={line.id}
                                    className="grid grid-cols-1 gap-2 rounded-md border p-3 md:grid-cols-[minmax(0,1fr)_8rem_9rem_9rem]"
                                >
                                    <div className="grid grid-cols-1 gap-1">
                                        <Label
                                            htmlFor={`correction-description-${line.id}`}
                                        >
                                            Description
                                        </Label>
                                        <Textarea
                                            id={`correction-description-${line.id}`}
                                            value={line.description}
                                            onChange={(event) =>
                                                setCorrectionLines((current) =>
                                                    current.map(
                                                        (item, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...item,
                                                                      description:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    {(
                                        [
                                            'quantity',
                                            'unit_amount',
                                            'tax_amount',
                                        ] as const
                                    ).map((field) => (
                                        <div
                                            key={field}
                                            className="grid grid-cols-1 gap-1"
                                        >
                                            <Label
                                                htmlFor={`${field}-${line.id}`}
                                            >
                                                {field === 'quantity'
                                                    ? 'Quantity'
                                                    : field === 'unit_amount'
                                                      ? 'Unit amount'
                                                      : 'Tax amount'}
                                            </Label>
                                            <Input
                                                id={`${field}-${line.id}`}
                                                inputMode="decimal"
                                                disabled={
                                                    !line.money_correctable
                                                }
                                                value={line[field]}
                                                onChange={(event) =>
                                                    setCorrectionLines(
                                                        (current) =>
                                                            current.map(
                                                                (
                                                                    item,
                                                                    itemIndex,
                                                                ) =>
                                                                    itemIndex ===
                                                                    index
                                                                        ? {
                                                                              ...item,
                                                                              [field]:
                                                                                  event
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            ))}
                            <div className="grid max-w-xl grid-cols-1 gap-2">
                                <Label htmlFor="correction-reason">
                                    Reason for correction
                                </Label>
                                <Textarea
                                    id="correction-reason"
                                    required
                                    maxLength={500}
                                    value={correctionReason}
                                    onChange={(event) =>
                                        setCorrectionReason(event.target.value)
                                    }
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={busy}>
                                    Save audited correction
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setCorrectingInvoice(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    )}
                </header>

                {(invoice.automatic_delivery_status ?? null) !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Automatic client delivery</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-2 text-sm">
                            <p>
                                <Badge variant="outline">
                                    {statusLabel(
                                        invoice.automatic_delivery_status ?? '',
                                    )}
                                </Badge>
                            </p>
                            {invoice.automatic_delivery_due_at !== null &&
                                invoice.automatic_delivery_due_at !==
                                    undefined && (
                                    <p>
                                        {[
                                            'scheduled',
                                            'failed',
                                            'sending',
                                        ].includes(
                                            invoice.automatic_delivery_status ??
                                                '',
                                        )
                                            ? 'Next send time'
                                            : 'Scheduled time'}
                                        :{' '}
                                        {formatTimestamp(
                                            invoice.automatic_delivery_due_at,
                                        )}
                                    </p>
                                )}
                            {invoice.automatic_delivery_note !== null &&
                                invoice.automatic_delivery_note !==
                                    undefined && (
                                    <p className="wrap-anywhere text-muted-foreground">
                                        {invoice.automatic_delivery_note}
                                    </p>
                                )}
                        </CardContent>
                    </Card>
                )}

                {administratorNotification !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Administrator review notice</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-2 text-sm">
                            <p>
                                Revision{' '}
                                {administratorNotification.invoice_revision} ·{' '}
                                {statusLabel(administratorNotification.status)}
                            </p>
                            <p className="text-muted-foreground">
                                {formatTimestamp(
                                    administratorNotification.sent_at ??
                                        administratorNotification.failed_at,
                                )}
                            </p>
                            {administratorNotification.error_summary !==
                                null && (
                                <p className="wrap-anywhere text-destructive">
                                    {administratorNotification.error_summary}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Totals</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">Total</dt>
                                <dd className="tabular-nums">
                                    {formatMoney(
                                        invoice.total_amount,
                                        invoice.currency,
                                    )}
                                </dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">Paid</dt>
                                <dd className="tabular-nums">
                                    {formatMoney(
                                        invoice.paid_amount,
                                        invoice.currency,
                                    )}
                                </dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">
                                    Balance
                                </dt>
                                <dd className="tabular-nums">
                                    {formatMoney(
                                        invoice.balance_amount,
                                        invoice.currency,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Lines</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {lines.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                This invoice has no lines.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {/*
                                             * The disclosure column, headed by
                                             * nothing: a column of triangles is
                                             * not something to name.
                                             */}
                                            <TableHead className="w-8" />
                                            <TableHead className="min-w-64">
                                                Description
                                            </TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Quantity</TableHead>
                                            <TableHead>Hours</TableHead>
                                            <TableHead>Unit</TableHead>
                                            <TableHead>Total</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.map((line) => (
                                            <InvoiceLineRows
                                                key={line.id}
                                                line={line}
                                                items={lineDetail[line.id]}
                                                columns={7}
                                            >
                                                <TableCell className="max-w-0 font-medium wrap-anywhere whitespace-normal">
                                                    {line.description}
                                                </TableCell>
                                                <TableCell>
                                                    {statusLabel(line.type)}
                                                </TableCell>
                                                <TableCell>
                                                    {formatDay(line.line_date)}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {line.quantity}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {line.hours ?? '—'}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatMoney(
                                                        line.unit_amount,
                                                        invoice.currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatMoney(
                                                        line.total_amount,
                                                        invoice.currency,
                                                    )}
                                                </TableCell>
                                            </InvoiceLineRows>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Payments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {payments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No payments recorded against this invoice.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Received</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Method</TableHead>
                                            <TableHead>Reference</TableHead>
                                            <TableHead>Amount</TableHead>
                                            <TableHead>Refunded</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {payments.map((payment) => (
                                            <TableRow key={payment.id}>
                                                <TableCell>
                                                    {correcting ===
                                                    payment.id ? (
                                                        <span className="flex flex-wrap items-center gap-2">
                                                            <Input
                                                                aria-label={`Corrected date for the ${formatMoney(payment.amount, payment.currency)} payment`}
                                                                type="date"
                                                                className="w-40"
                                                                max={todayIn(
                                                                    timezone,
                                                                )}
                                                                value={
                                                                    correctedDate
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setCorrectedDate(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                            />
                                                            <Button
                                                                size="sm"
                                                                disabled={busy}
                                                                onClick={() =>
                                                                    post(
                                                                        payment.correct_date_href ??
                                                                            '',
                                                                        {
                                                                            received_on:
                                                                                correctedDate,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setCorrecting(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </span>
                                                    ) : (
                                                        <>
                                                            {formatDay(
                                                                payment.received_on,
                                                            )}
                                                            {predatesInvoiceIssue(
                                                                payment.received_on,
                                                                invoice.issue_date,
                                                            ) && (
                                                                <span className="ml-2 text-xs text-amber-700 dark:text-amber-500">
                                                                    predates
                                                                    issue
                                                                </span>
                                                            )}
                                                            {payment.correct_date_href !==
                                                                null && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="ml-2"
                                                                    onClick={() => {
                                                                        setCorrectedDate(
                                                                            payment.received_on ??
                                                                                todayIn(
                                                                                    timezone,
                                                                                ),
                                                                        );
                                                                        setCorrecting(
                                                                            payment.id,
                                                                        );
                                                                    }}
                                                                >
                                                                    Correct date
                                                                </Button>
                                                            )}
                                                        </>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {statusLabel(
                                                            payment.status,
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {statusLabel(
                                                        payment.method,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {payment.reference ?? '—'}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatMoney(
                                                        payment.amount,
                                                        payment.currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatMoney(
                                                        payment.refunded_amount,
                                                        payment.currency,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                        {/*
                         * Said once, in full, rather than in every marked row:
                         * a `TableCell` does not wrap, so a sentence in the
                         * date column would push every column right of it off
                         * the screen. The rows say which payments; this says
                         * what it means and which date they are measured
                         * against.
                         */}
                        {predatingPayments > 0 &&
                            invoice.issue_date !== null && (
                                <p
                                    role="status"
                                    className="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm wrap-anywhere text-amber-800 dark:text-amber-300"
                                >
                                    {predatingPayments === 1
                                        ? 'One payment above is'
                                        : `${predatingPayments} payments above are`}{' '}
                                    dated before this invoice was issued on{' '}
                                    {formatDay(invoice.issue_date)}. That is
                                    allowed — a deposit or an advance retainer
                                    can arrive before the invoice that applies
                                    it — but a payment reconciles into the
                                    period its date names, so check the dates
                                    are the ones you meant.
                                </p>
                            )}
                    </CardContent>
                </Card>
                {email !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Sent to the client</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {deliveries.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    This invoice has not been emailed.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>When</TableHead>
                                                <TableHead className="min-w-48">
                                                    To
                                                </TableHead>
                                                <TableHead>Delivery</TableHead>
                                                <TableHead>
                                                    Our record
                                                </TableHead>
                                                <TableHead>
                                                    Provider says
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {deliveries.map((delivery) => (
                                                <TableRow key={delivery.id}>
                                                    <TableCell>
                                                        {formatTimestamp(
                                                            delivery.sent_at ??
                                                                delivery.failed_at,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                        {delivery.recipients.join(
                                                            ', ',
                                                        )}
                                                        {delivery.bcc.length >
                                                            0 &&
                                                            ` · bcc ${delivery.bcc.join(', ')}`}
                                                    </TableCell>
                                                    <TableCell>
                                                        {statusLabel(
                                                            delivery.origin ??
                                                                'manual',
                                                        )}{' '}
                                                        · revision{' '}
                                                        {delivery.invoice_revision ??
                                                            0}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={
                                                                delivery.status ===
                                                                'failed'
                                                                    ? 'destructive'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {statusLabel(
                                                                delivery.status,
                                                            )}
                                                        </Badge>
                                                        {delivery.error_summary !==
                                                            null && (
                                                            <p className="mt-1 text-xs wrap-anywhere text-muted-foreground">
                                                                {
                                                                    delivery.error_summary
                                                                }
                                                            </p>
                                                        )}
                                                    </TableCell>
                                                    {/*
                                                     * The provider's word, kept
                                                     * apart from ours. "Sent"
                                                     * means it left here;
                                                     * whether it arrived is
                                                     * this column, and it stays
                                                     * blank until the provider
                                                     * says.
                                                     */}
                                                    <TableCell>
                                                        {delivery.provider_status ===
                                                        null ? (
                                                            <span className="text-muted-foreground">
                                                                Not reported yet
                                                            </span>
                                                        ) : (
                                                            statusLabel(
                                                                delivery.provider_status,
                                                            )
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </main>

            {actions.send !== null && email !== null && (
                <SendInvoiceDialog
                    open={sending}
                    onOpenChange={setSending}
                    sendHref={actions.send}
                    email={email}
                />
            )}

            <AlertDialog
                open={voiding}
                onOpenChange={(open: boolean) => setVoiding(open)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Void this invoice?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {invoice.invoice_number ?? 'This invoice'} stops
                            being collectible, and the work on it returns to
                            being unbilled.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            render={<Button variant="outline">Cancel</Button>}
                        />
                        <AlertDialogAction
                            render={
                                <Button
                                    variant="destructive"
                                    disabled={busy}
                                    onClick={() => post(actions.void ?? '')}
                                >
                                    Void
                                </Button>
                            }
                        />
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </WorkspaceShell>
    );
}
