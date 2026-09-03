import { ChevronRightIcon } from 'lucide-react';
import { useState } from 'react';
import { TableCell, TableRow } from '@/components/ui/table';
import { formatDay } from '@/lib/datetime';
import { formatHours } from '@/lib/time';
import { cn } from '@/lib/utils';

/**
 * One entry of work behind an invoice line.
 *
 * What the reader sees depends on who they are, and that is decided on the
 * server: an operator gets every attached entry with its internal description,
 * a client only the entries written to be read by them. Nothing here filters —
 * a browser that could show more than it was sent would eventually be asked to.
 */
export type InvoiceLineItem = {
    worked_on: string;
    project: string | null;
    description: string;
    minutes: number;
};

/**
 * The work behind a line, expanded in place.
 *
 * A line reads "Deferred work items applied to retainer (12.50 hrs)" and the
 * one question it raises — which work — was unanswerable on the screen. The
 * pivot has held the answer since the billing engine was written; nothing ever
 * showed it.
 *
 * In place rather than on a page of its own. Comparing a line against its
 * contents means seeing both, and a detail screen puts the figure being
 * questioned behind a back button.
 *
 * Collapsed by default, and only offered for a line that has any. A retainer
 * sold for the coming cycle is a charge, not a record of hours; a disclosure
 * triangle beside it would promise something there is nothing behind.
 */
export function InvoiceLineRows({
    line,
    items,
    columns,
    children,
}: {
    line: { id: string; description: string };
    items: InvoiceLineItem[] | undefined;
    /** How wide the detail row spans, so it lines up under the table above it. */
    columns: number;
    /** The line's own cells, rendered inside the summary row. */
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const detail = items ?? [];
    const hasDetail = detail.length > 0;
    const panelId = `line-detail-${line.id}`;

    return (
        <>
            <TableRow>
                <TableCell className="w-8 align-top">
                    {hasDetail && (
                        <button
                            type="button"
                            aria-expanded={open}
                            aria-controls={open ? panelId : undefined}
                            aria-label={`${open ? 'Hide' : 'Show'} the work behind ${line.description}`}
                            className="rounded p-1 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            onClick={() => setOpen((value) => !value)}
                        >
                            <ChevronRightIcon
                                aria-hidden="true"
                                className={cn(
                                    'size-4 transition-transform',
                                    open && 'rotate-90',
                                )}
                            />
                        </button>
                    )}
                </TableCell>
                {children}
            </TableRow>

            {open && (
                <TableRow id={panelId}>
                    <TableCell />
                    <TableCell colSpan={columns} className="whitespace-normal">
                        <ul className="grid grid-cols-1 gap-1 py-1 text-sm">
                            {detail.map((item, index) => (
                                <li
                                    key={`${item.worked_on}-${index}`}
                                    className="flex flex-wrap items-baseline gap-x-3"
                                >
                                    <span className="text-muted-foreground tabular-nums">
                                        {formatDay(item.worked_on)}
                                    </span>
                                    {item.project !== null && (
                                        <span className="text-muted-foreground">
                                            {item.project}
                                        </span>
                                    )}
                                    <span className="min-w-40 flex-1 wrap-anywhere">
                                        {item.description}
                                    </span>
                                    <span className="tabular-nums">
                                        {formatHours(item.minutes)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </TableCell>
                </TableRow>
            )}
        </>
    );
}
