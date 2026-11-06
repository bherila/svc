import { Head, Link } from '@inertiajs/react';
import { PageLinks } from '@/components/page-links';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

export type WorkspaceInvoiceRow = {
    id: string;
    invoice_number: string;
    client: string;
    status: string;
    issue_date: string | null;
    due_date: string | null;
    balance_amount: number;
    currency: string;
    href: string;
};

/**
 * Every invoice in the workspace, across clients. Owners and admins only; the
 * server sends the page and the URL of each row.
 */
export default function WorkspaceInvoices({
    invoices,
    next_href: nextHref,
    previous_href: previousHref,
}: {
    invoices: WorkspaceInvoiceRow[];
    next_href: string | null;
    previous_href: string | null;
}) {
    return (
        <WorkspaceShell activeSection="invoices">
            <Head title="Invoices" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                    Invoices
                </h1>
                <Card>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No invoices yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Invoice</TableHead>
                                        <TableHead className="min-w-40">
                                            Client
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Due</TableHead>
                                        <TableHead className="text-right">
                                            Balance
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.map((invoice) => (
                                        <TableRow key={invoice.id}>
                                            <TableCell>
                                                <Link
                                                    href={invoice.href}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {invoice.invoice_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                {invoice.client}
                                            </TableCell>
                                            <TableCell>
                                                {statusLabel(invoice.status)}
                                            </TableCell>
                                            <TableCell>
                                                {invoice.due_date === null
                                                    ? '—'
                                                    : formatDay(
                                                          invoice.due_date,
                                                      )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    invoice.balance_amount,
                                                    invoice.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <PageLinks
                            label="Invoice pages"
                            nextHref={nextHref}
                            previousHref={previousHref}
                        />
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
