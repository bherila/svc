import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ClientContextLayout from '@/layouts/client-context-layout';
import { formatMoney } from '@/lib/money';
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
 * One invoice, read-only, inside the client it belongs to.
 *
 * The Invoices tab stays marked while this is open, because a row's detail is
 * still that tab rather than somewhere else — the chrome keeps saying which
 * client this is, which is the whole reason the invoice hangs off the client
 * route instead of a workspace-wide one.
 *
 * Hours are shown beside quantity rather than instead of it. They are separate
 * columns on the line and can legitimately disagree: quantity is what was
 * billed, hours is what the ledger draws against, and a fee line has one
 * without the other.
 */
export default function ClientInvoiceDetail({
    workspace,
    company,
    invoice,
    lines,
    payments,
}: {
    workspace: { id: string };
    company: { id: string; name: string };
    invoice: CompanyInvoice;
    lines: InvoiceLine[];
    payments: InvoicePayment[];
}) {
    const backHref = `/workspaces/${workspace.id}/clients/${company.id}/invoices`;

    return (
        <ClientContextLayout active="invoices">
            <Head title={invoice.invoice_number ?? 'Invoice'} />
            <main className="mx-auto grid max-w-6xl gap-6 p-6">
                <header className="grid gap-1">
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
                        <Badge variant="outline">{invoice.status}</Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {invoice.issue_date === null
                            ? 'Not issued'
                            : `Issued ${invoice.issue_date}`}
                        {invoice.due_date !== null &&
                            ` · due ${invoice.due_date}`}
                    </p>
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
                                            <TableHead>Description</TableHead>
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
                                                <TableCell className="font-medium">
                                                    {line.description}
                                                </TableCell>
                                                <TableCell>
                                                    {line.type}
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
                                                        {payment.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {payment.method ?? '—'}
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
        </ClientContextLayout>
    );
}
