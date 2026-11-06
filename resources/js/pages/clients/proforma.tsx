import { Head } from '@inertiajs/react';
import { InvoiceDocument } from '@/components/billing/invoice-document';
import type { InvoiceDocumentData } from '@/components/billing/invoice-document';
import { Card, CardContent } from '@/components/ui/card';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

/**
 * The next invoice of an agreement, as the client would receive it, drawn
 * through the same document as an issued invoice and stamped as not an
 * invoice. It is the period preview read as a document: nothing is stored, and
 * it changes as work is logged and approved.
 *
 * When the period cannot be previewed (already invoiced, not started) the
 * server says why in one sentence, and this page says it rather than showing
 * an empty document.
 */
export default function ClientProforma({
    agreement_title: agreementTitle,
    period_start: periodStart,
    document,
    reason,
}: {
    company: { id: string; name: string };
    agreement_title: string;
    period_start: string | null;
    document: InvoiceDocumentData | null;
    reason: string | null;
}) {
    return (
        <WorkspaceShell activeModule="invoices">
            <Head title="Proforma invoice" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <header className="grid grid-cols-1 gap-1">
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        Proforma invoice
                    </h1>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        {agreementTitle}
                        {periodStart !== null &&
                            ` · period starting ${formatDay(periodStart)}`}
                    </p>
                </header>
                {document !== null ? (
                    <InvoiceDocument document={document} />
                ) : (
                    <Card>
                        <CardContent>
                            <p className="text-sm wrap-anywhere">
                                {reason ?? 'There is nothing to preview.'}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </main>
        </WorkspaceShell>
    );
}
