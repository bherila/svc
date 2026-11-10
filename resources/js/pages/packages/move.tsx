import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ClientsTabs } from '@/components/packages/clients-tabs';
import { TermChanges } from '@/components/packages/move-notice';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest } from '@/lib/api';
import { formatDay } from '@/lib/datetime';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { PackageMoveCandidate, PackageMoveProps } from '@/types/packages';

const MESSAGE_MAX = 2000;

const STALE =
    'The package changed since you opened this page. Reload it, check the subscribers and the date again, then schedule.';

/** What happens to one subscriber on the chosen date, in words. */
function effect(subscriber: PackageMoveCandidate, date: string | null): string {
    if (subscriber.refusal !== null) {
        return subscriber.refusal.message;
    }

    return date === null ? 'Can move' : `Moves ${formatDay(date)}`;
}

/**
 * Moving a version's subscribers to the package's published version (MIG-1,
 * MIG-2, MIG-4): what changes, when, and who. Every date offered and every
 * subscriber's refusal, its label and autopay flag are the server's, as is the
 * split between those some date suits and those refused on every one; another
 * date asks the server again. Scheduling is confirmed, then posts to the agent
 * endpoint the page was given, and each client is emailed.
 */
export default function PackageMove({
    preview,
    package_version: packageVersion,
    urls,
}: PackageMoveProps) {
    const refused = preview.refused;
    const eligible = useMemo(
        () =>
            preview.subscribers
                .filter((subscriber) => subscriber.refusal === null)
                .map((subscriber) => subscriber.enrollment_id),
        [preview.subscribers],
    );
    const [chosen, setChosen] = useState<string[]>(eligible);
    const [message, setMessage] = useState('');
    const [confirming, setConfirming] = useState(false);
    const selected = chosen.filter((id) => eligible.includes(id));
    const from = preview.from_version.number;
    const to = preview.to_version?.number ?? null;
    const total = preview.subscribers.length;
    const items = preview.effective_dates.map((option) => ({
        value: option.date,
        label: `${formatDay(option.date)} · suits ${option.subscribers} of ${total}`,
    }));

    const pickDate = (value: string) => {
        router.get(
            urls.preview,
            { effective_on: value },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const toggle = (id: string, on: boolean) => {
        setChosen((current) =>
            on
                ? [...current.filter((value) => value !== id), id]
                : current.filter((value) => value !== id),
        );
    };

    const schedule = async (): Promise<string | null> => {
        if (preview.effective_on === null || selected.length === 0) {
            return 'Choose a date and at least one subscriber.';
        }

        const result = await apiRequest('POST', urls.schedule, {
            expected_version: packageVersion,
            confirm: true,
            from_version_id: preview.from_version.id,
            effective_on: preview.effective_on,
            enrollment_ids: selected,
            message: message.trim() === '' ? null : message.trim(),
        });

        if (!result.ok) {
            return result.status === 409 ? STALE : result.message;
        }

        router.visit(urls.editor);

        return null;
    };

    const clients = selected.length === 1 ? 'client' : 'clients';

    return (
        <WorkspaceShell activeSection="clients">
            <Head title={`Move subscribers · ${preview.package.name}`} />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
                data-appearance-bridge
            >
                <ClientsTabs active="packages" />
                <div className="grid min-w-0 grid-cols-1 gap-1">
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        <Link
                            href={urls.editor}
                            className="underline underline-offset-4"
                        >
                            {preview.package.name}
                        </Link>
                    </p>
                    <h1 className="text-[17px] font-semibold tracking-tight wrap-anywhere md:text-lg">
                        {to === null
                            ? `Move subscribers from v${from}`
                            : `Move subscribers from v${from} to v${to}`}
                    </h1>
                </div>

                {preview.refusal !== null ? (
                    <Card role="note">
                        <CardContent className="grid grid-cols-1 gap-3 text-sm">
                            <p className="wrap-anywhere">
                                {preview.refusal.message}
                            </p>
                            <p>
                                <Link
                                    href={urls.editor}
                                    className="underline underline-offset-4"
                                >
                                    Back to the package
                                </Link>
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <Card aria-label="What changes">
                            <CardHeader>
                                <CardTitle>What changes</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 gap-3 text-sm">
                                {preview.changes.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        No term the clients see changes.
                                    </p>
                                ) : (
                                    <TermChanges
                                        changes={preview.changes}
                                        fromLabel={`v${from}`}
                                        toLabel={`v${to ?? ''}`}
                                    />
                                )}
                                <p className="wrap-anywhere text-muted-foreground">
                                    On the date, each client&apos;s agreement
                                    ends the day before and a new one with these
                                    terms starts, so every billing period is
                                    invoiced on the terms in force for it.
                                </p>
                            </CardContent>
                        </Card>

                        <Card aria-label="When">
                            <CardHeader>
                                <CardTitle>When</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 gap-4">
                                {items.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No subscriber can be moved yet.
                                    </p>
                                ) : (
                                    <div className="grid grid-cols-1 gap-2">
                                        <Label htmlFor="move-effective-on">
                                            Takes effect
                                        </Label>
                                        <Select
                                            items={items}
                                            value={preview.effective_on ?? ''}
                                            onValueChange={(next) => {
                                                if (typeof next === 'string') {
                                                    pickDate(next);
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                id="move-effective-on"
                                                className="w-full sm:w-96"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {items.map((item) => (
                                                    <SelectItem
                                                        key={item.value}
                                                        value={item.value}
                                                    >
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-sm text-muted-foreground">
                                            The start of a billing period, after
                                            the client&apos;s notice period and
                                            after every period already invoiced.
                                        </p>
                                    </div>
                                )}
                                <div className="grid grid-cols-1 gap-2">
                                    <Label htmlFor="move-message">
                                        Message to clients (added to the notice
                                        email)
                                    </Label>
                                    <Textarea
                                        id="move-message"
                                        maxLength={MESSAGE_MAX}
                                        value={message}
                                        onChange={(event) =>
                                            setMessage(event.target.value)
                                        }
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <Card aria-label="Subscribers">
                            <CardHeader>
                                <CardTitle>Subscribers · {total}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 gap-3">
                                {total === 0 && refused.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No active service is on v{from}.
                                    </p>
                                ) : total === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No active service on v{from} can be
                                        moved to this version.
                                    </p>
                                ) : (
                                    <div className="min-w-0 overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        <span className="sr-only">
                                                            Move
                                                        </span>
                                                    </TableHead>
                                                    <TableHead className="min-w-48">
                                                        Client
                                                    </TableHead>
                                                    <TableHead>Since</TableHead>
                                                    <TableHead>
                                                        Autopay limit
                                                    </TableHead>
                                                    <TableHead className="min-w-56">
                                                        Effect
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {preview.subscribers.map(
                                                    (subscriber) => {
                                                        const id = `move-${subscriber.enrollment_id}`;
                                                        const can =
                                                            subscriber.refusal ===
                                                            null;

                                                        return (
                                                            <TableRow
                                                                key={
                                                                    subscriber.enrollment_id
                                                                }
                                                            >
                                                                <TableCell>
                                                                    <input
                                                                        id={id}
                                                                        type="checkbox"
                                                                        aria-label={`Move ${subscriber.client.name}`}
                                                                        disabled={
                                                                            !can
                                                                        }
                                                                        checked={
                                                                            can &&
                                                                            selected.includes(
                                                                                subscriber.enrollment_id,
                                                                            )
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            toggle(
                                                                                subscriber.enrollment_id,
                                                                                event
                                                                                    .target
                                                                                    .checked,
                                                                            )
                                                                        }
                                                                    />
                                                                </TableCell>
                                                                <TableCell className="wrap-anywhere whitespace-normal">
                                                                    <label
                                                                        htmlFor={
                                                                            id
                                                                        }
                                                                    >
                                                                        {
                                                                            subscriber
                                                                                .client
                                                                                .name
                                                                        }
                                                                    </label>
                                                                </TableCell>
                                                                <TableCell>
                                                                    {formatDay(
                                                                        subscriber
                                                                            .agreement
                                                                            .starts_on,
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="tabular-nums">
                                                                    {subscriber.autopay_limit_amount ===
                                                                    null
                                                                        ? '—'
                                                                        : formatMoney(
                                                                              subscriber.autopay_limit_amount,
                                                                              preview
                                                                                  .new_price
                                                                                  ?.currency ??
                                                                                  null,
                                                                          )}
                                                                </TableCell>
                                                                <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                                    <span>
                                                                        {effect(
                                                                            subscriber,
                                                                            preview.effective_on,
                                                                        )}
                                                                    </span>
                                                                    <span className="mt-1 flex flex-wrap gap-1">
                                                                        {subscriber.autopay_below_price && (
                                                                            <Badge variant="outline">
                                                                                Limit
                                                                                below
                                                                                new
                                                                                price
                                                                            </Badge>
                                                                        )}
                                                                        {subscriber.pending_cancellation_end_on !==
                                                                            null && (
                                                                            <Badge variant="outline">
                                                                                Cancellation
                                                                                pending
                                                                            </Badge>
                                                                        )}
                                                                        {subscriber.scheduled_end_on !==
                                                                            null && (
                                                                            <Badge variant="outline">
                                                                                Ends{' '}
                                                                                {formatDay(
                                                                                    subscriber.scheduled_end_on,
                                                                                )}
                                                                            </Badge>
                                                                        )}
                                                                    </span>
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    },
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                                {preview.new_price !== null && (
                                    <p className="text-sm wrap-anywhere text-muted-foreground">
                                        The new price is{' '}
                                        {preview.new_price.label} a period. SVC
                                        never raises a client&apos;s autopay
                                        limit: a limit below it is flagged to
                                        you and in the client&apos;s notice.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {refused.length > 0 && (
                            <Card aria-label="Cannot move">
                                <CardHeader>
                                    <CardTitle>
                                        Cannot move · {refused.length}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid grid-cols-1 gap-3">
                                    <p className="text-sm wrap-anywhere text-muted-foreground">
                                        No date on offer suits these
                                        subscribers, so they keep their terms
                                        and count towards no date.
                                    </p>
                                    <div className="min-w-0 overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="min-w-48">
                                                        Client
                                                    </TableHead>
                                                    <TableHead>Since</TableHead>
                                                    <TableHead className="min-w-56">
                                                        Reason
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {refused.map((subscriber) => (
                                                    <TableRow
                                                        key={
                                                            subscriber.enrollment_id
                                                        }
                                                    >
                                                        <TableCell className="wrap-anywhere whitespace-normal">
                                                            {
                                                                subscriber
                                                                    .client.name
                                                            }
                                                        </TableCell>
                                                        <TableCell>
                                                            {formatDay(
                                                                subscriber
                                                                    .agreement
                                                                    .starts_on,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                            <span className="flex flex-wrap gap-1">
                                                                <Badge variant="outline">
                                                                    {
                                                                        subscriber
                                                                            .refusal
                                                                            .label
                                                                    }
                                                                </Badge>
                                                            </span>
                                                            <span className="mt-1 block text-muted-foreground">
                                                                {
                                                                    subscriber
                                                                        .refusal
                                                                        .message
                                                                }
                                                            </span>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <Link
                                href={urls.editor}
                                className={cn(
                                    buttonVariants({ variant: 'outline' }),
                                    'min-h-11 md:min-h-0',
                                )}
                            >
                                Cancel
                            </Link>
                            <Button
                                type="button"
                                className="min-h-11 md:min-h-0"
                                disabled={
                                    selected.length === 0 ||
                                    preview.effective_on === null
                                }
                                onClick={() => setConfirming(true)}
                            >
                                Schedule for {selected.length} {clients} and
                                send notices
                            </Button>
                        </div>
                    </>
                )}
            </main>

            {preview.effective_on !== null && (
                <ConfirmedAction
                    open={confirming}
                    onOpenChange={setConfirming}
                    title={`Move ${selected.length} ${clients} to v${to ?? ''} on ${formatDay(preview.effective_on)}?`}
                    description="Each client is emailed the changes and the date now, and sees a notice in the client portal. On the date their agreements move to the new terms unless they have ended them first. You can cancel the move until then."
                    confirmLabel="Schedule and send notices"
                    run={schedule}
                />
            )}
        </WorkspaceShell>
    );
}
