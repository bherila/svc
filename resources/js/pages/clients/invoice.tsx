import { Head, Link, router } from '@inertiajs/react';
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
import WorkspaceShell from '@/layouts/workspace-shell';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { PAYMENT_METHOD_OTHER, PAYMENT_METHODS } from '@/lib/payments';
import { cn } from '@/lib/utils';
import type { CompanyInvoice } from '@/types/clients';

type InvoiceLine = {
    id: string;
    type: string;
    description: string;
    quantity: number;
    hours: number | null;
    line_date: string | null;
    unit_amount: number;
    total_amount: number;
};

type InvoicePayment = {
    id: string;
    status: string;
    method: string | null;
    reference: string | null;
    received_on: string | null;
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
    issue: string | null;
    send: string | null;
    payment: string | null;
    void: string | null;
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
    invoice,
    lines,
    payments,
}: {
    company: { id: string; name: string };
    invoices_href: string;
    pdf_href: string;
    actions: InvoiceActions;
    invoice: CompanyInvoice;
    lines: InvoiceLine[];
    payments: InvoicePayment[];
}) {
    const [paying, setPaying] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState<string>('bank_transfer');
    // Only meaningful while `method` is "other": the name of the arrangement
    // that is not on the list. Stored as the method itself, so the row reads
    // like every other one rather than saying "other" and leaving the actual
    // answer nowhere.
    const [otherMethod, setOtherMethod] = useState('');
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const post = (
        href: string,
        data: Record<string, string | number | null> = {},
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

    return (
        <WorkspaceShell activeModule="invoices">
            <Head title={invoice.invoice_number ?? 'Invoice'} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <header className="grid grid-cols-1 gap-1">
                    <Link
                        href={backHref}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {company.name} invoices
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold">
                            {invoice.invoice_number ?? 'Unnumbered invoice'}
                        </h1>
                        <Badge variant="outline">
                            {statusLabel(invoice.status)}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {invoice.issue_date === null
                            ? 'Not issued'
                            : `Issued ${invoice.issue_date}`}
                        {invoice.due_date !== null &&
                            ` · due ${invoice.due_date}`}
                    </p>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            render={<a href={pdfHref}>View PDF</a>}
                        />
                        {actions.issue !== null && (
                            <Button
                                size="sm"
                                disabled={busy}
                                onClick={() => post(actions.issue ?? '')}
                            >
                                Issue
                            </Button>
                        )}
                        {actions.send !== null && (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() => post(actions.send ?? '')}
                            >
                                Send to client
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
                            className="mt-3 grid gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end"
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
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>Totals</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-2 text-sm sm:grid-cols-3">
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
                                            <TableRow key={line.id}>
                                                <TableCell className="max-w-0 font-medium wrap-anywhere whitespace-normal">
                                                    {line.description}
                                                </TableCell>
                                                <TableCell>
                                                    {statusLabel(line.type)}
                                                </TableCell>
                                                <TableCell>
                                                    {line.line_date ?? '—'}
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
                                            </TableRow>
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
                                                    {payment.received_on ?? '—'}
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
                    </CardContent>
                </Card>
            </main>

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
