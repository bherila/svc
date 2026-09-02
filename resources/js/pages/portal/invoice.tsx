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
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatMoney } from '@/lib/money';

type PortalLine = {
    id: string;
    description: string;
    quantity: number;
    hours: number | null;
    line_date: string | null;
    unit_amount: number;
    total_amount: number;
};

type PortalInvoice = {
    id: string;
    invoice_number: string | null;
    status: string;
    currency: string | null;
    issue_date: string | null;
    due_date: string | null;
    service_period_start: string | null;
    service_period_end: string | null;
    subtotal_amount: number;
    tax_amount: number;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    pdf_url: string;
};

/**
 * One invoice, as the client it was sent to.
 *
 * The portal's list answers "what do I owe"; this answers "what for", which is
 * the question a client actually opens a portal to ask. It is read-only, like
 * every client-reachable route — there is no action on this page, and that is a
 * property of the surface rather than an omission.
 *
 * Hours appear beside quantity only where the line has them. A fee line has a
 * quantity and no hours, and showing a dash rather than a zero keeps the two
 * apart for the person being billed.
 */
export default function PortalInvoice({
    company,
    home_href: homeHref,
    invoice,
    lines,
}: {
    company: { id: string; name: string };
    home_href: string;
    invoice: PortalInvoice;
    lines: PortalLine[];
}) {
    const period =
        invoice.service_period_start === null &&
        invoice.service_period_end === null
            ? null
            : `${invoice.service_period_start ?? 'open'} → ${invoice.service_period_end ?? 'open'}`;

    return (
        <WorkspaceShell activeModule="invoices">
            <Head title={invoice.invoice_number ?? 'Invoice'} />
            <main className="mx-auto grid max-w-4xl gap-6 p-6">
                <header className="grid gap-1">
                    <Link
                        href={homeHref}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {company.name}
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold">
                            {invoice.invoice_number ?? 'Invoice'}
                        </h1>
                        <Badge variant="outline">{invoice.status}</Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {invoice.issue_date === null
                            ? 'Not dated'
                            : `Issued ${invoice.issue_date}`}
                        {invoice.due_date !== null &&
                            ` · due ${invoice.due_date}`}
                        {period !== null && ` · covering ${period}`}
                    </p>
                    <p className="text-sm">
                        <a
                            href={invoice.pdf_url}
                            className="underline underline-offset-4"
                        >
                            Download PDF
                        </a>
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>What this covers</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {lines.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                This invoice has no itemised lines.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Description</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Quantity</TableHead>
                                            <TableHead>Hours</TableHead>
                                            <TableHead>Rate</TableHead>
                                            <TableHead>Amount</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.map((line) => (
                                            <TableRow key={line.id}>
                                                <TableCell className="font-medium">
                                                    {line.description}
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
                        <CardTitle>Totals</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">
                                    Subtotal
                                </dt>
                                <dd className="tabular-nums">
                                    {formatMoney(
                                        invoice.subtotal_amount,
                                        invoice.currency,
                                    )}
                                </dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">Tax</dt>
                                <dd className="tabular-nums">
                                    {formatMoney(
                                        invoice.tax_amount,
                                        invoice.currency,
                                    )}
                                </dd>
                            </div>
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
                                <dd className="font-medium tabular-nums">
                                    {formatMoney(
                                        invoice.balance_amount,
                                        invoice.currency,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
