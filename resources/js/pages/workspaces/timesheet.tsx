import { Head, Link } from '@inertiajs/react';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    DownloadIcon,
    FileTextIcon,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDurationStyle } from '@/hooks/use-duration-style';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatShortDay, formatTimestampDate } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';
import type { TimesheetProps } from '@/types/submission';

/**
 * The approved timesheet: the hours a reviewer or the client's contact
 * approved for one calendar month, with who worked each and who approved it
 * (docs/ux-overhaul.md section 7e). It is what a provider invoices from or
 * sends, so it prints no rate and nothing that was not approved.
 */
export default function Timesheet({
    workspace,
    timesheet,
    links,
}: TimesheetProps) {
    const style = useDurationStyle();

    return (
        <WorkspaceShell activeSection="timesheet">
            <Head title="Approved timesheet" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <h1 className="min-w-0 text-[17px] font-semibold tracking-tight md:text-lg">
                        Approved timesheet
                    </h1>
                    <nav
                        aria-label="Month"
                        className="flex items-center gap-2 text-sm"
                    >
                        <Link
                            href={links.previous_href}
                            className="inline-flex min-h-11 items-center gap-1 underline underline-offset-4 md:min-h-8"
                        >
                            <ChevronLeftIcon
                                aria-hidden="true"
                                className="size-4"
                            />
                            Previous
                        </Link>
                        <span
                            aria-live="polite"
                            className="min-w-28 text-center font-medium"
                            data-test="period"
                        >
                            {timesheet.period_label}
                        </span>
                        <Link
                            href={links.next_href}
                            className="inline-flex min-h-11 items-center gap-1 underline underline-offset-4 md:min-h-8"
                        >
                            Next
                            <ChevronRightIcon
                                aria-hidden="true"
                                className="size-4"
                            />
                        </Link>
                    </nav>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="wrap-anywhere">
                            {workspace.name}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4">
                        <p className="text-sm text-muted-foreground">
                            {formatDuration(timesheet.total_minutes, style)}{' '}
                            approved in {timesheet.period_label}.
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                className="min-h-11 md:min-h-8"
                                render={
                                    <a
                                        href={links.csv_href}
                                        download
                                        data-test="export-csv"
                                    />
                                }
                            >
                                <DownloadIcon aria-hidden="true" />
                                Download CSV
                            </Button>
                            <Button
                                variant="outline"
                                className="min-h-11 md:min-h-8"
                                render={
                                    <a
                                        href={links.pdf_href}
                                        download
                                        data-test="export-pdf"
                                    />
                                }
                            >
                                <FileTextIcon aria-hidden="true" />
                                Download PDF
                            </Button>
                            <Link
                                href={links.submissions_href}
                                className="inline-flex min-h-11 items-center text-sm underline underline-offset-4 md:min-h-8"
                            >
                                Submit for review
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        {timesheet.entries.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing was approved in {timesheet.period_label}
                                . Hours appear here once a reviewer or your
                                client approves them.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead scope="col">Date</TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-56"
                                        >
                                            Description
                                        </TableHead>
                                        <TableHead scope="col">
                                            Project
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="text-right"
                                        >
                                            Time
                                        </TableHead>
                                        <TableHead scope="col">
                                            Worked by
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-44"
                                        >
                                            Approved by
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {timesheet.entries.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatShortDay(
                                                    entry.worked_on,
                                                )}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-56 wrap-anywhere whitespace-normal">
                                                {entry.description}
                                                {entry.task !== null && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {entry.task}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-32 wrap-anywhere whitespace-normal">
                                                {entry.project}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatDuration(
                                                    entry.minutes,
                                                    style,
                                                )}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-28 wrap-anywhere whitespace-normal">
                                                {entry.worked_by}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <span className="block wrap-anywhere">
                                                    {entry.approved_by}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.approved_via}
                                                    {entry.approved_at !==
                                                        null &&
                                                        `, ${formatTimestampDate(entry.approved_at)}`}
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="font-medium"
                                        >
                                            Total
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatDuration(
                                                timesheet.total_minutes,
                                                style,
                                            )}
                                        </TableCell>
                                        <TableCell colSpan={2} />
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        )}
                        {timesheet.truncated && (
                            <p className="mt-3 text-xs text-muted-foreground">
                                This month has more approved rows than fit. The
                                first 1000 are shown.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
