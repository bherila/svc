import { Head } from '@inertiajs/react';
import { InvoiceTable } from '@/components/clients/invoice-table';
import WorkspaceShell from '@/layouts/workspace-shell';
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
    invoices,
}: {
    company: { id: string; name: string };
    /** Where a row links; the two audiences use different route families. */
    invoice_base_href: string;
    invoices: CompanyInvoice[];
}) {
    return (
        <WorkspaceShell activeModule="invoices">
            <Head title={`${company.name} invoices`} />
            <main className="mx-auto grid max-w-5xl grid-cols-1 gap-6 px-6 py-8">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Invoices
                </h1>
                <InvoiceTable
                    invoices={invoices}
                    empty="No invoices yet."
                    hrefFor={(invoice) => `${invoiceBaseHref}/${invoice.id}`}
                />
            </main>
        </WorkspaceShell>
    );
}
