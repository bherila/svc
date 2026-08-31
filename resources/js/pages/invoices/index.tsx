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
import { formatMoney } from '@/lib/money';

type WorkspaceInvoice = {
    id: string;
    invoice_number: string | null;
    status: string;
    currency: string | null;
    issue_date: string | null;
    due_date: string | null;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    company: { id: string | null; name: string | null };
    /**
     * Where this row leads, decided server-side.
     *
     * Null when there is nowhere to send the reader - a row whose client is not
     * in this workspace. Built there rather than here because the destination
     * depends on who is asking: a portal viewer would be refused the
     * client-scoped screen, which authorizes on workspace membership.
     */
    href: string | null;
};

/**
 * Every invoice in the workspace, above any one client.
 *
 * Deliberately outside the client chrome — there is no client in context here,
 * so a switcher naming one would be lying about where the reader is. The
 * workspace time sheet renders the same way for the same reason.
 *
 * Each row's destination is decided server-side. For a workspace member it is
 * the client's Invoices tab, because that is where an invoice lives — the
 * reader arrives with the client named around them. A portal viewer reaches
 * this list through a different door and would be refused that screen, so they
 * get the route that applies portal invoice authorization.
 *
 * The client column tolerates a missing name rather than assuming one.
 * `client_company_id` is NOT NULL, so every invoice names a client - but the
 * reference is lineage, and a row migrated from before #113's composite keys
 * can name a company that is no longer there. Saying "no client on record" is
 * more honest than omitting the row from a list that claims to be complete.
 */
export default function WorkspaceInvoices({
    workspace,
    invoices,
}: {
    workspace: { id: string; name: string };
    invoices: WorkspaceInvoice[];
}) {
    // Per currency, never summed across them. A workspace can bill in more
    // than one, and adding minor units together produces a number that is not
    // money in any of them - a caveat under it would not make it true.
    const outstanding = invoices.reduce<Record<string, number>>(
        (totals, invoice) => {
            const currency = invoice.currency ?? 'unknown';

            return {
                ...totals,
                [currency]: (totals[currency] ?? 0) + invoice.balance_amount,
            };
        },
        {},
    );

    return (
        <>
            <Head title="Invoices" />
            <main className="mx-auto grid max-w-6xl gap-6 p-6">
                <header className="grid gap-1">
                    <Link
                        href={`/workspaces/${workspace.id}/clients`}
                        className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        ← {workspace.name} clients
                    </Link>
                    <h1 className="text-2xl font-semibold">Invoices</h1>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {invoices.length}{' '}
                            {invoices.length === 1 ? 'invoice' : 'invoices'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {invoices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No invoices in this workspace.
                            </p>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Number</TableHead>
                                                <TableHead>Client</TableHead>
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
                                                        {invoice.href ===
                                                        null ? (
                                                            (invoice.invoice_number ??
                                                            'Unnumbered')
                                                        ) : (
                                                            <Link
                                                                href={
                                                                    invoice.href
                                                                }
                                                                className="underline-offset-4 hover:underline"
                                                            >
                                                                {invoice.invoice_number ??
                                                                    'Unnumbered'}
                                                            </Link>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {invoice.company.name ??
                                                            'No client on record'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline">
                                                            {invoice.status}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        {invoice.issue_date ??
                                                            'Not issued'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {invoice.due_date ??
                                                            '—'}
                                                    </TableCell>
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
                                <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                    {Object.entries(outstanding).map(
                                        ([currency, balance]) => (
                                            <div
                                                key={currency}
                                                className="flex gap-2"
                                            >
                                                <dt className="text-muted-foreground">
                                                    Outstanding
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {formatMoney(
                                                        balance,
                                                        currency === 'unknown'
                                                            ? null
                                                            : currency,
                                                    )}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                            </>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
