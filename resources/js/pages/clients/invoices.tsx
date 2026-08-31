import { Head } from '@inertiajs/react';
import { InvoiceTable } from '@/components/clients/invoice-table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ClientContextLayout from '@/layouts/client-context-layout';
import type { CompanyInvoice } from '@/types/clients';

/**
 * Every invoice for the client in context.
 *
 * Overview carries the most recent handful; this is the unbounded list. The
 * rows come from the same component, so the two cannot describe an invoice
 * differently depending on which screen the operator opened.
 *
 * Invoices hang off the company rather than a project because that is where
 * they live in the data - an invoice has no project of its own, only its lines
 * do - so there is nothing to filter by here and no filter is offered.
 */
export default function ClientInvoices({
    company,
    invoices,
}: {
    company: { id: string; name: string };
    invoices: CompanyInvoice[];
}) {
    return (
        <ClientContextLayout active="invoices">
            <Head title={`${company.name} invoices`} />
            <main className="mx-auto grid max-w-6xl gap-6 p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Invoices</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <InvoiceTable
                            invoices={invoices}
                            empty="No invoices for this client yet."
                        />
                    </CardContent>
                </Card>
            </main>
        </ClientContextLayout>
    );
}
