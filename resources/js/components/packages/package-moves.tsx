import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { apiRequest } from '@/lib/api';
import { formatDay, formatTimestampDate } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type { PackageMoveRow, PackageMovesPanel } from '@/types/packages';

const STALE =
    'This move changed since you opened the page. Reload to see where it stands, then try again.';

/** How a move's subscribers stand, in words: "2 scheduled, 1 moved". */
function countsText(move: PackageMoveRow): string {
    const parts = [
        move.counts.scheduled > 0 ? `${move.counts.scheduled} scheduled` : null,
        move.counts.applied > 0 ? `${move.counts.applied} moved` : null,
        move.counts.skipped > 0 ? `${move.counts.skipped} skipped` : null,
        move.counts.cancelled > 0 ? `${move.counts.cancelled} cancelled` : null,
    ].filter((part) => part !== null);

    return parts.length === 0 ? 'No subscribers' : parts.join(', ');
}

/** Subscribers still waiting on an owner, each with why. */
function waiting(move: PackageMoveRow) {
    return move.subscribers.filter(
        (subscriber) =>
            subscriber.status === 'scheduled' && subscriber.outcome !== null,
    );
}

/**
 * The editor's versions and moves (MIG-1, MIG-5): who is on each published
 * version, where moving an older version's subscribers starts, and every move
 * with what it did and, while it is scheduled, a confirmed cancel. Every link
 * and endpoint is the server's.
 */
export function PackageMoves({ panel }: { panel: PackageMovesPanel }) {
    const [cancelling, setCancelling] = useState<PackageMoveRow | null>(null);

    const cancel = async (): Promise<string | null> => {
        if (cancelling === null || cancelling.cancel_url === null) {
            return 'This move can no longer be cancelled.';
        }

        const result = await apiRequest('POST', cancelling.cancel_url, {
            expected_version: cancelling.version,
            confirm: true,
        });

        if (!result.ok) {
            return result.status === 409 ? STALE : result.message;
        }

        await refreshPageProps();

        return null;
    };

    if (panel.versions.length === 0 && panel.moves.length === 0) {
        return null;
    }

    return (
        <Card aria-label="Versions and moves">
            <CardHeader>
                <CardTitle>Versions and subscribers</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-6">
                <ul className="grid grid-cols-1 divide-y divide-border text-sm">
                    {panel.versions.map((version) => (
                        <li
                            key={version.id}
                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2"
                        >
                            <span className="min-w-0 wrap-anywhere">
                                v{version.number}
                                {version.published_at !== null &&
                                    ` · published ${formatTimestampDate(version.published_at)}`}
                            </span>
                            <span className="flex flex-wrap items-center gap-3">
                                <span className="text-muted-foreground tabular-nums">
                                    {version.subscribers}{' '}
                                    {version.subscribers === 1
                                        ? 'subscriber'
                                        : 'subscribers'}
                                </span>
                                {version.move_url !== null && (
                                    <Link
                                        href={version.move_url}
                                        className="underline underline-offset-4"
                                    >
                                        Move to the published version…
                                        <span className="sr-only">
                                            {' '}
                                            from v{version.number}
                                        </span>
                                    </Link>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>

                {panel.moves.length > 0 && (
                    <div className="grid grid-cols-1 gap-2">
                        <h3 className="text-sm font-medium">Moves</h3>
                        <div className="min-w-0 overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Versions</TableHead>
                                        <TableHead>Takes effect</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="min-w-56">
                                            Subscribers
                                        </TableHead>
                                        <TableHead className="text-right">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {panel.moves.map((move) => (
                                        <TableRow key={move.id}>
                                            <TableCell>
                                                v{move.from_version.number} → v
                                                {move.to_version.number}
                                            </TableCell>
                                            <TableCell>
                                                {formatDay(move.effective_on)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {statusLabel(move.status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                <span>{countsText(move)}</span>
                                                {waiting(move).map(
                                                    (subscriber) => (
                                                        <span
                                                            key={subscriber.id}
                                                            className="block text-xs text-muted-foreground"
                                                        >
                                                            {
                                                                subscriber
                                                                    .client.name
                                                            }
                                                            :{' '}
                                                            {
                                                                subscriber
                                                                    .outcome
                                                                    ?.message
                                                            }
                                                        </span>
                                                    ),
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {move.cancel_url !== null && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setCancelling(move)
                                                        }
                                                    >
                                                        Cancel move…
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                )}
            </CardContent>
            {cancelling !== null && (
                <ConfirmedAction
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setCancelling(null);
                        }
                    }}
                    title={`Cancel the move to v${cancelling.to_version.number}?`}
                    description={`Subscribers not yet moved keep their current terms, and each is emailed that nothing changes. Anyone already moved on ${formatDay(cancelling.effective_on)} stays moved.`}
                    confirmLabel="Cancel the move"
                    run={cancel}
                />
            )}
        </Card>
    );
}
