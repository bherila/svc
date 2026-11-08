import { MoonIcon, SunIcon } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDay } from '@/lib/datetime';
import { formatInvoiceDuration } from '@/lib/duration';
import { statusLabel } from '@/lib/labels';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

/**
 * The invoice document exactly as the server built it (`InvoiceDocument`):
 * the one shape behind the screen, the PDF and the proforma. Nothing here is
 * derived beyond sentence-casing a stored value and formatting an amount.
 */
export type InvoiceDocumentLine = {
    id: string;
    type: string;
    type_label: string;
    description: string;
    quantity: string;
    hours: number | null;
    line_date: string | null;
    unit_amount: number;
    tax_amount: number;
    total_amount: number;
};

export type InvoiceDocumentSection = {
    key: string;
    title: string;
    subtotal: number;
    tax: number;
    lines: InvoiceDocumentLine[];
};

export type InvoiceReceiptFile = {
    id: string;
    filename: string;
    media_type: string;
    bytes: number;
    /** A finished address, or null where this viewer may not open the file. */
    href: string | null;
    /** Why the PDF does not draw this file, or null where it does. */
    note: string | null;
};

export type InvoiceReceiptEntry = {
    ref: string;
    line_id: string;
    spent_on: string;
    description: string;
    amount: number;
    files: InvoiceReceiptFile[];
};

export type InvoiceDocumentData = {
    mode: 'invoice' | 'proforma';
    heading: string;
    stamp: string | null;
    audience: 'operator' | 'client';
    banner: string;
    issuer_name: string;
    invoice_number: string | null;
    status: string;
    currency: string;
    bill_to: string;
    issue_date: string | null;
    due_date: string | null;
    service_period_start: string | null;
    service_period_end: string | null;
    reissue_notice: string | null;
    void_notice: string | null;
    sections: InvoiceDocumentSection[];
    totals: {
        subtotal: number;
        tax: number;
        total: number;
        paid: number;
        balance: number;
    };
    statement: {
        intro: string;
        sections: {
            title: string;
            rows: { label: string; hours: string; kind: string }[];
        }[];
    } | null;
    deferred_work: {
        entry_count: number;
        total_hours: number;
        oldest_worked_on: string;
        entries: {
            worked_on: string;
            hours: number;
            description: string | null;
            /** Held out of billing by a client's dispute (docs/ux-overhaul.md 7b″). */
            disputed: boolean;
        }[];
    } | null;
    appendix: {
        generic_label_used: boolean;
        generic_label: string;
        blocks: {
            line_id: string;
            description: string;
            minutes: number;
            entries: {
                worked_on: string;
                project: string | null;
                description: string;
                minutes: number;
            }[];
        }[];
    } | null;
    receipts: {
        intro: string;
        receipt_count: number;
        entries: InvoiceReceiptEntry[];
    } | null;
    pay_url: string | null;
    /** Present when autopay will charge this invoice on its due date. */
    autopay_notice?: string | null;
};

/** "1.5000" reads as 1.5: the stored scale is not something a reader typed. */
function quantity(value: string): string {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? String(parsed) : value;
}

function entries(count: number): string {
    return `${count} ${count === 1 ? 'entry' : 'entries'}`;
}

function period(start: string | null, end: string | null): string {
    return `${start === null ? 'open' : formatDay(start)} to ${end === null ? 'open' : formatDay(end)}`;
}

/**
 * The invoice, drawn as the document it is.
 *
 * Two surfaces share this one component. `screen` takes the app theme: in dark
 * mode the sheet is a raised surface with light ink and muted rules. `paper`
 * re-declares the light tokens on the sheet (`.invoice-paper`), which is what
 * the PDF looks like, so a reader can check the printed page without leaving
 * the app. Print CSS forces paper whatever was chosen. The toggle is shown in
 * dark mode only, where the two differ.
 *
 * Every figure comes from the server's document; a column that holds prose
 * wraps, and an unbreakable run (an invoice number, a client name, a URL) has
 * somewhere to go.
 */
export function InvoiceDocument({
    document,
    defaultPrinted = false,
}: {
    document: InvoiceDocumentData;
    /** Start on the paper rendering rather than the app theme. */
    defaultPrinted?: boolean;
}) {
    const [printed, setPrinted] = useState(defaultPrinted);
    const money = (minor: number) => formatMoney(minor, document.currency);

    return (
        <section
            aria-label={document.heading}
            className="grid grid-cols-1 gap-3"
        >
            <div className="hidden justify-end dark:flex print:hidden">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-pressed={printed}
                    onClick={() => setPrinted((current) => !current)}
                >
                    {printed ? (
                        <MoonIcon aria-hidden="true" />
                    ) : (
                        <SunIcon aria-hidden="true" />
                    )}
                    Show as printed
                </Button>
            </div>
            <article
                data-surface={printed ? 'paper' : 'screen'}
                className={cn(
                    'invoice-sheet grid grid-cols-1 gap-6 rounded-xl border border-border bg-card p-5 text-card-foreground sm:p-8',
                    printed && 'invoice-paper',
                )}
            >
                {document.stamp !== null && (
                    <p
                        role="note"
                        className="border-2 border-amber-700 px-3 py-2 text-center text-sm font-semibold tracking-wide text-amber-700 dark:border-amber-500 dark:text-amber-500 print:border-amber-700 print:text-amber-700"
                    >
                        {document.stamp}
                    </p>
                )}

                <header className="grid grid-cols-1 gap-4 border-b-2 border-foreground pb-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div className="grid grid-cols-1 gap-1">
                        <h2 className="text-2xl font-semibold">
                            {document.heading}
                        </h2>
                        {document.invoice_number !== null && (
                            <p className="text-sm wrap-anywhere text-muted-foreground">
                                {document.invoice_number}
                            </p>
                        )}
                        {document.issuer_name !== '' && (
                            <p className="text-sm wrap-anywhere text-muted-foreground">
                                From {document.issuer_name}
                            </p>
                        )}
                    </div>
                    <div className="text-sm sm:text-right">
                        <p className="font-semibold">
                            {statusLabel(document.status)}
                        </p>
                        <p className="text-muted-foreground">
                            {document.currency}
                        </p>
                    </div>
                </header>

                <dl className="grid grid-cols-1 gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
                    <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">Bill to</dt>
                        <dd className="min-w-0 font-medium wrap-anywhere">
                            {document.bill_to}
                        </dd>
                    </div>
                    <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">Issued</dt>
                        <dd>{formatDay(document.issue_date)}</dd>
                    </div>
                    <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">
                            Service period
                        </dt>
                        <dd>
                            {period(
                                document.service_period_start,
                                document.service_period_end,
                            )}
                        </dd>
                    </div>
                    <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">Due</dt>
                        <dd>{formatDay(document.due_date)}</dd>
                    </div>
                </dl>

                {(document.reissue_notice !== null ||
                    document.void_notice !== null) && (
                    <div className="grid grid-cols-1 gap-2 text-sm">
                        {document.reissue_notice !== null && (
                            <p className="wrap-anywhere text-muted-foreground">
                                {document.reissue_notice}
                            </p>
                        )}
                        {document.void_notice !== null && (
                            <p
                                role="note"
                                className="rounded-md border border-border bg-muted px-3 py-2 wrap-anywhere"
                            >
                                {document.void_notice}
                            </p>
                        )}
                    </div>
                )}

                {document.autopay_notice != null && (
                    <p
                        role="note"
                        className="rounded-md border border-border bg-muted px-3 py-2 text-sm wrap-anywhere"
                    >
                        {document.autopay_notice}
                    </p>
                )}

                {document.sections.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        This invoice has no lines.
                    </p>
                ) : (
                    document.sections.map((section) => (
                        <div
                            key={section.key}
                            className="grid grid-cols-1 gap-1"
                        >
                            <h3 className="border-b-2 border-foreground pb-1 text-sm font-semibold">
                                {section.title}
                            </h3>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="min-w-56 text-xs text-muted-foreground uppercase">
                                            Description
                                        </TableHead>
                                        <TableHead className="text-xs text-muted-foreground uppercase">
                                            Type
                                        </TableHead>
                                        <TableHead className="text-right text-xs text-muted-foreground uppercase">
                                            Quantity
                                        </TableHead>
                                        <TableHead className="text-right text-xs text-muted-foreground uppercase">
                                            Unit
                                        </TableHead>
                                        <TableHead className="text-right text-xs text-muted-foreground uppercase">
                                            Tax
                                        </TableHead>
                                        <TableHead className="text-right text-xs text-muted-foreground uppercase">
                                            Total
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {section.lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                {line.description}
                                                {line.line_date !== null && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {formatDay(
                                                            line.line_date,
                                                        )}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.type_label}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {quantity(line.quantity)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(line.unit_amount)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(line.tax_amount)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(line.total_amount)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {section.lines.length > 1 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="font-semibold"
                                            >
                                                {section.title} subtotal
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {money(section.subtotal)}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    ))
                )}

                <dl className="grid grid-cols-1 gap-1 text-sm sm:ml-auto sm:w-72">
                    {(
                        [
                            ['Subtotal', document.totals.subtotal],
                            ['Tax', document.totals.tax],
                        ] as const
                    ).map(([label, amount]) => (
                        <div key={label} className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="tabular-nums">{money(amount)}</dd>
                        </div>
                    ))}
                    <div className="flex justify-between gap-4 border-t-2 border-foreground pt-1 font-semibold">
                        <dt>Total</dt>
                        <dd className="tabular-nums">
                            {money(document.totals.total)}
                        </dd>
                    </div>
                    {(
                        [
                            ['Paid', document.totals.paid],
                            ['Balance', document.totals.balance],
                        ] as const
                    ).map(([label, amount]) => (
                        <div key={label} className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="tabular-nums">{money(amount)}</dd>
                        </div>
                    ))}
                </dl>

                {document.statement !== null && (
                    <div className="grid grid-cols-1 gap-4 border-t border-border pt-6">
                        <div className="grid grid-cols-1 gap-1">
                            <h3 className="text-lg font-semibold">
                                Hours statement
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {document.statement.intro}
                            </p>
                        </div>
                        {document.statement.sections.map((section) => (
                            <div
                                key={section.title}
                                className="grid grid-cols-1 gap-1"
                            >
                                <h4 className="text-sm font-semibold wrap-anywhere">
                                    {section.title}
                                </h4>
                                <Table>
                                    <TableBody>
                                        {section.rows.map((row, index) => (
                                            <TableRow
                                                key={`${row.label}-${index}`}
                                                className={cn(
                                                    row.kind === 'total' &&
                                                        'font-semibold',
                                                    row.kind === 'note' &&
                                                        'text-muted-foreground italic',
                                                )}
                                            >
                                                <TableCell
                                                    className={cn(
                                                        'max-w-0 wrap-anywhere whitespace-normal',
                                                        row.kind === 'detail' &&
                                                            'pl-6 text-muted-foreground',
                                                    )}
                                                >
                                                    {row.label}
                                                </TableCell>
                                                <TableCell className="w-24 text-right tabular-nums">
                                                    {row.hours}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        ))}
                    </div>
                )}

                {document.deferred_work !== null && (
                    <div className="grid grid-cols-1 gap-2 border-t border-border pt-6">
                        <h3 className="text-lg font-semibold">
                            Still deferred
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {entries(document.deferred_work.entry_count)} ·{' '}
                            {document.deferred_work.total_hours.toFixed(4)}{' '}
                            hours · oldest worked date{' '}
                            {formatDay(document.deferred_work.oldest_worked_on)}
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="min-w-56">
                                        Work
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Hours
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {document.deferred_work.entries.map(
                                    (entry, index) => (
                                        <TableRow
                                            key={`${entry.worked_on}-${index}`}
                                        >
                                            <TableCell>
                                                {formatDay(entry.worked_on)}
                                            </TableCell>
                                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                {entry.description ?? '—'}
                                                {entry.disputed && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-2 bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200"
                                                        title="Held out of billing until the dispute is settled"
                                                    >
                                                        Disputed
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {entry.hours.toFixed(4)}
                                            </TableCell>
                                        </TableRow>
                                    ),
                                )}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {document.appendix !== null && (
                    <div className="grid grid-cols-1 gap-4 border-t border-border pt-6">
                        <div className="grid grid-cols-1 gap-1">
                            <h3 className="text-lg font-semibold">
                                Appendix A: time entries
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Grouped by the line each entry was billed on.
                                {document.appendix.generic_label_used &&
                                    ` Entries shown as “${document.appendix.generic_label}” are billed work without a client-facing description.`}
                            </p>
                        </div>
                        {document.appendix.blocks.map((block) => (
                            <div
                                key={block.line_id}
                                className="grid grid-cols-1 gap-1"
                            >
                                <h4 className="text-sm font-semibold wrap-anywhere">
                                    {block.description}
                                </h4>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date</TableHead>
                                            <TableHead>Project</TableHead>
                                            <TableHead className="min-w-48">
                                                Work
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Hours
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {block.entries.map((entry, index) => (
                                            <TableRow
                                                key={`${entry.worked_on}-${index}`}
                                            >
                                                <TableCell>
                                                    {formatDay(entry.worked_on)}
                                                </TableCell>
                                                <TableCell className="max-w-40 wrap-anywhere whitespace-normal">
                                                    {entry.project ?? '—'}
                                                </TableCell>
                                                <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                    {entry.description}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatInvoiceDuration(
                                                        entry.minutes,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="font-semibold">
                                            <TableCell colSpan={3}>
                                                {entries(block.entries.length)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatInvoiceDuration(
                                                    block.minutes,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        ))}
                    </div>
                )}

                {document.receipts !== null && (
                    <div
                        id="appendix-b"
                        className="grid grid-cols-1 gap-4 border-t border-border pt-6"
                    >
                        <div className="grid grid-cols-1 gap-1">
                            <h3 className="text-lg font-semibold">
                                Appendix B: receipts
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {document.receipts.intro}
                            </p>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Ref</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="min-w-48">
                                        Description
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead className="min-w-40">
                                        Receipts
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {document.receipts.entries.map((entry) => (
                                    <TableRow key={entry.ref}>
                                        <TableCell>{entry.ref}</TableCell>
                                        <TableCell>
                                            {formatDay(entry.spent_on)}
                                        </TableCell>
                                        <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                            {entry.description}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(entry.amount)}
                                        </TableCell>
                                        <TableCell className="max-w-48 wrap-anywhere whitespace-normal">
                                            {entry.files.length === 0 ? (
                                                <span className="text-muted-foreground">
                                                    No receipt on file
                                                </span>
                                            ) : (
                                                <ul className="grid grid-cols-1 gap-1">
                                                    {entry.files.map((file) => (
                                                        <li key={file.id}>
                                                            {file.href ===
                                                            null ? (
                                                                file.filename
                                                            ) : (
                                                                <a
                                                                    href={
                                                                        file.href
                                                                    }
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="underline underline-offset-4"
                                                                >
                                                                    {
                                                                        file.filename
                                                                    }
                                                                </a>
                                                            )}
                                                            {file.note !==
                                                                null && (
                                                                <span className="block text-xs text-muted-foreground">
                                                                    {file.note}
                                                                </span>
                                                            )}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </article>
        </section>
    );
}
