import {
    BanIcon,
    CircleCheckIcon,
    ClockIcon,
    FileTextIcon,
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
