import { Head } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatHours } from '@/lib/time';

/**
 * The work done, as the client reads it.
 *
 * Its own screen rather than the operator's time sheet, and deliberately. That
 * sheet is a working tool: it logs, it approves, and it shows how much of a
 * retainer is left. This is a statement of work done. Sharing one component
 * would mean either handing a client the capacity strip and the approval
 * controls, or taking the operator's tool away - so the shell and the
 * navigation are shared and the table is not.
 *
 * Read-only, and there is no route behind it that is not.
 */
export default function PortalTime({
    company,
    entries,
}: {
    company: { id: string; name: string };
    entries: {
        id: string;
        worked_on: string;
        project: string | null;
        description: string | null;
        minutes: number;
    }[];
}) {
    const total = entries.reduce((sum, entry) => sum + entry.minutes, 0);

    return (
        <WorkspaceShell activeModule="time">
            <Head title={`${company.name} time`} />
            <main className="mx-auto grid max-w-4xl gap-6 px-6 py-8">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Time
                    </h1>
                    {entries.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {formatHours(total)} in total
                        </p>
                    )}
                </div>

                {entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No time has been shared yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Hours</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="tabular-nums">
                                            {entry.worked_on}
                                        </TableCell>
                                        <TableCell>
                                            {entry.project ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {entry.description}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatHours(entry.minutes)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </main>
        </WorkspaceShell>
    );
}
