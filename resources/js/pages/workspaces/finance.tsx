import { Head, Link, usePage } from '@inertiajs/react';
import { PageLinks } from '@/components/page-links';
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
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { WorkspaceNavigation } from '@/types/navigation';

export type OpenInvoiceRow = {
    id: string;
    invoice_number: string;
    client: string;
    status: string;
    due_date: string | null;
    balance_amount: number;
    currency: string;
    href: string;
};

/**
 * What is owed, and where to record what arrived. The reconciliation half of
 * the retired Operations screen: recording a payment stays on the invoice,
 * where its version and idempotency checks live.
 */
export default function WorkspaceFinance({
    open_invoices: invoices,
    next_href: nextHref,
    previous_href: previousHref,
}: {
    open_invoices: OpenInvoiceRow[];
    next_href: string | null;
    previous_href: string | null;
}) {
    const navigation = usePage().props
        .workspaceNavigation as WorkspaceNavigation | null;

    return (
        <WorkspaceShell activeSection="finance">
            <Head title="Finance" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                    Finance
                </h1>
                <Card>
                    <CardHeader>
                        <CardTitle>Waiting for a payment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing is waiting for a payment
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
                                        <TableHead>
                                            <span className="sr-only">
                                                Record
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.map((invoice) => (
                                        <TableRow key={invoice.id}>
                                            <TableCell className="font-medium">
                                                {invoice.invoice_number}
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
                                            <TableCell>
                                                <Link
                                                    href={invoice.href}
                                                    className="text-sm underline underline-offset-4"
                                                >
                                                    Open invoice
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <PageLinks
                            label="Finance pages"
                            nextHref={nextHref}
                            previousHref={previousHref}
                        />
                    </CardContent>
                </Card>

                {navigation?.square_href && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Square</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Link
                                href={navigation.square_href}
                                className="text-sm underline underline-offset-4"
                            >
                                Open mirrored Square invoices
                            </Link>
                        </CardContent>
                    </Card>
                )}
            </main>
        </WorkspaceShell>
    );
}
