import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { statusLabel } from '@/lib/labels';
import { formatMoney } from '@/lib/money';
import type { CompanyInvoice } from '@/types/clients';

/**
 * One company's invoices, rendered the same way wherever they appear.
 *
 * Overview shows the most recent handful and the Invoices tab shows all of
 * them, but they are the same rows and must stay the same rows: a second copy
 * drifts, and the version an operator trusts becomes whichever screen they
 * happened to open. That is the duplication the tabbed hierarchy exists to
 * avoid, one layer down from the navigation.
 *
 * Money arrives as minor units and is formatted per row, in the row's own
 * currency - an invoice carries its own, and a workspace can hold more than
 * one.
 */
export function InvoiceTable({
    invoices,
    empty,
    hrefFor,
}: {
    invoices: CompanyInvoice[];
    /** What to say instead of an empty table. */
    empty: string;
    /**
     * Where a row goes, when it goes anywhere.
     *
     * Optional so the table stays usable on a screen that has no detail route
     * to offer, rather than rendering links that go nowhere.
     */
    hrefFor?: (invoice: CompanyInvoice) => string;
}) {
    if (invoices.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Number</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Issued</TableHead>
                        <TableHead>Due</TableHead>
                        <TableHead>Total</TableHead>
                        <TableHead>Balance</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {invoices.map((invoice) => (
                        <TableRow key={invoice.id}>
                            <TableCell className="font-medium">
                                {hrefFor === undefined ? (
                                    (invoice.invoice_number ?? 'Unnumbered')
                                ) : (
                                    <Link
                                        href={hrefFor(invoice)}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {invoice.invoice_number ?? 'Unnumbered'}
                                    </Link>
                                )}
                            </TableCell>
                            <TableCell>
                                <Badge variant="outline">
                                    {statusLabel(invoice.status)}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                {invoice.issue_date ?? 'Not issued'}
                            </TableCell>
                            <TableCell>{invoice.due_date ?? '—'}</TableCell>
                            <TableCell className="tabular-nums">
                                {formatMoney(
                                    invoice.total_amount,
                                    invoice.currency,
                                )}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {formatMoney(
                                    invoice.balance_amount,
                                    invoice.currency,
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
