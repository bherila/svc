import { Head } from '@inertiajs/react';
import { InvoiceForm } from '@/components/billing/invoice-draft-form';
import { InvoiceTable } from '@/components/clients/invoice-table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { CompanyInvoice } from '@/types/clients';

/**
 * Every invoice for the client in context.
 *
 * Client Home carries the latest one and links here; this is the full list.
 * Rendered for both an operator and the client themselves - the rows come from
 * the same component either way, so the two cannot describe an invoice
 * differently depending on who opened it. What differs is the query behind the
 * payload, and where a row links, which the server decides.
 *
 * Invoices hang off the company rather than a project because that is where
 * they live in the data - an invoice has no project of its own, only its lines
 * do - so there is nothing to filter by here and no filter is offered.
 */
export default function ClientInvoices({
    company,
    invoice_base_href: invoiceBaseHref,
    invoice_create_href: invoiceCreateHref = null,
    invoices,
}: {
    company: { id: string; name: string };
    /** Where a row links; the two audiences use different route families. */
    invoice_base_href: string;
    /**
     * Where a draft is posted; null for a viewer who may not draft (and for
     * the client's own copy of this page), and then the form is not drawn.
     */
    invoice_create_href?: string | null;
    invoices: CompanyInvoice[];
}) {
    return (
        <WorkspaceShell activeModule="invoices">
            <Head title={`${company.name} invoices`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <h1 className="text-2xl font-semibold tracking-tight">
                    Invoices
                </h1>
                {invoiceCreateHref !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Draft an invoice</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <InvoiceForm
                                href={invoiceCreateHref}
                                clientId={company.id}
                            />
                        </CardContent>
                    </Card>
                )}
                <InvoiceTable
                    invoices={invoices}
                    empty="No invoices yet."
                    hrefFor={(invoice) => `${invoiceBaseHref}/${invoice.id}`}
                />
            </main>
        </WorkspaceShell>
    );
}
