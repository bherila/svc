import { Head, Link } from '@inertiajs/react';
import { PageLinks } from '@/components/page-links';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDurationStyle } from '@/hooks/use-duration-style';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import { SHELL_CONTAINER } from '@/lib/layout';
import { cn } from '@/lib/utils';

export type WorkspaceTimeRow = {
    id: string;
    client: string;
    project: string;
    worked_on: string;
    minutes: number;
    description: string;
    href: string;
};

/**
 * Time waiting for approval, across clients. Approving happens on each
 * client's time sheet; this says where to go, oldest first.
 */
export default function WorkspaceTime({
    entries,
    next_href: nextHref,
    previous_href: previousHref,
}: {
    entries: WorkspaceTimeRow[];
    next_href: string | null;
    previous_href: string | null;
}) {
    const style = useDurationStyle();

    return (
        <WorkspaceShell activeSection="time">
            <Head title="Time" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                    Time to approve
                </h1>
                <Card>
                    <CardContent>
                        {entries.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing needs approving
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Client</TableHead>
                                        <TableHead className="min-w-64">
                                            Work
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Time
                                        </TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Open
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell>
                                                {formatDay(entry.worked_on)}
                                            </TableCell>
                                            <TableCell>
                                                {entry.client}
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.project}
                                                </span>
                                            </TableCell>
                                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                {entry.description}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatDuration(
                                                    entry.minutes,
                                                    style,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={entry.href}
                                                    className="text-sm underline underline-offset-4"
                                                >
                                                    Review
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <PageLinks
                            label="Time pages"
                            nextHref={nextHref}
                            previousHref={previousHref}
                        />
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
