import { Head } from '@inertiajs/react';
import { CreditCardIcon, DownloadIcon } from 'lucide-react';
import { PortalInvoiceAutopayCard } from '@/components/billing/autopay-portal';
import type { PortalInvoiceAutopay } from '@/components/billing/autopay-portal';
import { InvoiceDocument } from '@/components/billing/invoice-document';
import type { InvoiceDocumentData } from '@/components/billing/invoice-document';
import {
    InvoicePaymentState,
    InvoiceReceiptsCard,
} from '@/components/billing/invoice-rail';
import { StripeCardPayment } from '@/components/billing/stripe-card-payment';
import AttachmentSharingPanel from '@/components/files/attachment-sharing-panel';
import { Button } from '@/components/ui/button';
import WorkspaceShell from '@/layouts/workspace-shell';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { AttachmentPanel } from '@/types/files';

/**
 * What this workspace's connections can take payment with: finished values from
 * the server, or null. Nothing here is a flag the browser interprets.
 */
export type PortalPaymentOptions = {
    stripe: { intent_href: string; publishable_key: string } | null;
    square: { href: string } | null;
};

/**
 * One invoice, as the client it was sent to.
 *
 * The same document the operator and the PDF show, for the client's audience,
 * with an action rail beside it: where the invoice stands and how to pay it.
 * Paying is offered through whatever the workspace has connected (its own
 * Stripe account, Square's hosted page for a linked invoice) and not at all
 * for an invoice that cannot be paid. Which work each line was billed from is
 * Appendix A of the document, narrowed on the server to entries written to be
 * read by this client.
 */
export default function PortalInvoice({
    attachment_panel = null,
    autopay = null,
    document,
    payment,
    pdf_url: pdfUrl,
}: {
    attachment_panel?: AttachmentPanel | null;
    /** Autopay on this invoice as the client reads it (#636); null when it plays no part. */
    autopay?: PortalInvoiceAutopay | null;
    company: { id: string; name: string };
    home_href: string;
    document: InvoiceDocumentData;
    payment: PortalPaymentOptions;
    pdf_url: string;
}) {
    const stripe = payment.stripe;
    const square = payment.square;

    return (
        <WorkspaceShell activeModule="invoices">
            <Head title={document.invoice_number ?? 'Invoice'} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-8')}
            >
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                    <InvoiceDocument document={document} />
                    <aside
                        id="pay"
                        aria-label="Invoice actions"
                        className="order-first grid grid-cols-1 gap-4 lg:order-none"
                    >
                        {autopay !== null && (
                            <PortalInvoiceAutopayCard autopay={autopay} />
                        )}
                        <InvoicePaymentState document={document}>
                            {stripe !== null && (
                                <StripeCardPayment
                                    intentHref={stripe.intent_href}
                                    publishableKey={stripe.publishable_key}
                                    primary
                                />
                            )}
                            {square !== null && (
                                <Button
                                    variant={
                                        stripe === null ? 'default' : 'outline'
                                    }
                                    render={
                                        <a
                                            href={square.href}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        />
                                    }
                                >
                                    <CreditCardIcon aria-hidden="true" />
                                    Pay in Square
                                </Button>
                            )}
                            <a
                                href={pdfUrl}
                                className="inline-flex items-center gap-1.5 text-sm underline underline-offset-4"
                            >
                                <DownloadIcon
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                Download PDF
                            </a>
                        </InvoicePaymentState>
                        <InvoiceReceiptsCard document={document} />
                    </aside>
                </div>
                {attachment_panel && (
                    <AttachmentSharingPanel panel={attachment_panel} />
                )}
            </main>
        </WorkspaceShell>
    );
}
