import {
    BanIcon,
    CircleCheckIcon,
    ClockIcon,
    FileTextIcon,
    ReceiptIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { InvoiceDocumentData } from '@/components/billing/invoice-document';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDay } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { formatMoney } from '@/lib/money';

/** Status is an icon plus words, never colour alone. */
const STATUS_ICONS: Record<string, LucideIcon> = {
    draft: FileTextIcon,
    issued: ClockIcon,
    partially_paid: ClockIcon,
    paid: CircleCheckIcon,
    disputed: TriangleAlertIcon,
    void: BanIcon,
};

/**
 * The action rail's first card: where the invoice stands, in a figure and one
 * line. The operator page and the portal both draw it; the portal puts its Pay
 * actions in `children`, the operator page its own.
 */
export function InvoicePaymentState({
    document,
    children,
}: {
    document: InvoiceDocumentData;
    children?: ReactNode;
}) {
    const Icon = STATUS_ICONS[document.status] ?? FileTextIcon;
    const owing = document.totals.balance > 0 && document.status !== 'void';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Payment</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <Icon aria-hidden="true" className="size-4 shrink-0" />
                    {statusLabel(document.status)}
                </p>
                <p className="text-2xl font-semibold tabular-nums">
                    {formatMoney(
                        owing ? document.totals.balance : document.totals.paid,
                        document.currency,
                    )}
                    <span className="block text-sm font-normal text-muted-foreground">
                        {owing ? 'Balance due' : 'Paid'}
                        {owing &&
                            document.due_date !== null &&
                            ` · due ${formatDay(document.due_date)}`}
                    </span>
                </p>
                {children}
            </CardContent>
        </Card>
    );
}

/**
 * The rail's receipts card: the expenses on this invoice and a link to each
 * receipt filed on them, the way Appendix B of the PDF lists them. Every link
 * is an address the server chose for this viewer; a receipt it did not give an
 * address for is named and not linked. Drawn only when the document has an
 * Appendix B, so a viewer who may be told of no receipt sees no card.
 */
export function InvoiceReceiptsCard({
    document,
}: {
    document: InvoiceDocumentData;
}) {
    const receipts = document.receipts;

    if (receipts === null) {
        return null;
    }

    return (
        <Card aria-label="Expense receipts">
            <CardHeader>
                <CardTitle>
                    Expenses · {receipts.receipt_count}{' '}
                    {receipts.receipt_count === 1 ? 'receipt' : 'receipts'}
                </CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-2 text-sm">
                {receipts.entries.map((entry) => (
                    <div
                        key={entry.ref}
                        className="flex items-start justify-between gap-3"
                    >
                        <span className="flex min-w-0 items-start gap-2">
                            <ReceiptIcon
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <span className="min-w-0 wrap-anywhere">
                                {entry.description}
                            </span>
                        </span>
                        <span className="flex shrink-0 flex-wrap justify-end gap-x-2">
                            {entry.files.length === 0 ? (
                                <span className="text-muted-foreground">
                                    None
                                </span>
                            ) : (
                                entry.files.map((file, index) => {
                                    const label =
                                        entry.files.length === 1
                                            ? entry.ref
                                            : `${entry.ref}.${index + 1}`;

                                    return file.href === null ? (
                                        <span
                                            key={file.id}
                                            title={file.filename}
                                        >
                                            {label}
                                        </span>
                                    ) : (
                                        <a
                                            key={file.id}
                                            href={file.href}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title={file.filename}
                                            aria-label={`Open receipt ${label}: ${file.filename}`}
                                            className="inline-flex min-h-6 items-center underline underline-offset-4"
                                        >
                                            {label}
                                        </a>
                                    );
                                })
                            )}
                        </span>
                    </div>
                ))}
                <p className="text-xs text-muted-foreground">
                    Appendix B of the PDF
                </p>
            </CardContent>
        </Card>
    );
}
