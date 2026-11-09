import { useState } from 'react';
import { ConfirmedAction } from '@/components/shared-projects/participation-panels';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest } from '@/lib/api';
import { formatDay, formatTimestampDate } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type {
    CancellationOption,
    OperatorCancellation,
} from '@/types/agreement';

/** Each offered date as the select shows it, marked where it only waives notice. */
function optionItems(
    options: CancellationOption[],
    requested: string | null,
    earliest: string | null,
) {
    return options.map((option) => {
        const notes = [
            option.value === requested ? 'requested' : null,
            option.value === earliest ? 'earliest with notice' : null,
            option.waives_notice ? 'waive notice' : null,
        ].filter((note) => note !== null);

        return {
            value: option.value,
            label: `${formatDay(option.value)}${notes.length === 0 ? '' : ` (${notes.join(', ')})`}`,
        };
    });
}

/** Send one decision; a stale page is refreshed and said so, a refusal read out. */
async function send(
    href: string,
    body: Record<string, unknown>,
): Promise<string | null> {
    const result = await apiRequest('POST', href, { ...body, confirm: true });

    if (!result.ok) {
        if (result.status === 409) {
            await refreshPageProps();

            return 'This changed since the page loaded. The page now shows its current state; check it and try again.';
        }

        return result.message;
    }

    await refreshPageProps();

    return null;
}

function DateChoice({
    id,
    label,
    items,
    value,
    onChange,
}: {
    id: string;
    label: string;
    items: { value: string; label: string }[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select
                items={items}
                value={value}
                onValueChange={(next) => {
                    if (typeof next === 'string') {
                        onChange(next);
                    }
                }}
            >
                <SelectTrigger id={id} className="w-full sm:w-80">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {items.map((item) => (
                        <SelectItem key={item.value} value={item.value}>
                            {item.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/**
 * Cancellation on the agreement page, for owners and admins (CAN-4, EDGE-9):
 * approve a client's request on the date they asked for or another offered
 * one (waiving notice where that is what allows it), decline it with a note,
 * undo a scheduled end, or schedule one directly. Every date and endpoint is
 * the server's, and each act is confirmed before it is sent.
 */
export function OperatorCancellationCard({
    panel,
}: {
    panel: OperatorCancellation;
}) {
    const pending = panel.pending;
    const pendingItems =
        pending === null
            ? []
            : optionItems(
                  pending.options,
                  pending.requested_end_on,
                  panel.earliest_end_on,
              );
    const scheduleItems =
        panel.schedule === null
            ? []
            : optionItems(panel.schedule.options, null, panel.earliest_end_on);
    const offeredRequest =
        pending !== null &&
        pending.options.some(
            (option) => option.value === pending.requested_end_on,
        );
    const [approveOn, setApproveOn] = useState(
        offeredRequest && pending !== null
            ? pending.requested_end_on
            : (pendingItems[0]?.value ?? ''),
    );
    const [scheduleOn, setScheduleOn] = useState(
        panel.earliest_end_on ?? scheduleItems[0]?.value ?? '',
    );
    const [note, setNote] = useState('');
    const [open, setOpen] = useState<
        'approve' | 'decline' | 'undo' | 'schedule' | null
    >(null);
    const waives = (options: CancellationOption[], date: string) =>
        options.find((option) => option.value === date)?.waives_notice === true;
    const toggle =
        (which: 'approve' | 'decline' | 'undo' | 'schedule') =>
        (next: boolean) =>
            setOpen(next ? which : null);

    if (
        pending === null &&
        panel.scheduled === null &&
        panel.schedule === null
    ) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {pending !== null
                        ? 'Cancellation request'
                        : panel.scheduled !== null
                          ? 'Scheduled end'
                          : 'End this agreement'}
                </CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                {pending !== null && (
                    <>
                        <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div className="grid min-w-0 grid-cols-1 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Requested by
                                </dt>
                                <dd className="wrap-anywhere">
                                    {pending.requested_by ?? 'A client contact'}{' '}
                                    ·{' '}
                                    {formatTimestampDate(pending.requested_at)}
                                </dd>
                            </div>
                            <div className="grid min-w-0 grid-cols-1 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Asked to end
                                </dt>
                                <dd>
                                    {formatDay(pending.requested_end_on)}
                                    {pending.requested_end_on ===
                                        panel.earliest_end_on &&
                                        ' · earliest allowed'}
                                </dd>
                            </div>
                            {pending.reason !== null && (
                                <div className="grid min-w-0 grid-cols-1 gap-0.5 sm:col-span-2">
                                    <dt className="text-muted-foreground">
                                        Reason
                                    </dt>
                                    <dd className="wrap-anywhere whitespace-pre-wrap">
                                        {pending.reason}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        {pendingItems.length === 0 ? (
                            <p className="wrap-anywhere text-muted-foreground">
                                No end date can be offered now. Decline the
                                request, or correct the agreement&apos;s terms
                                first.
                            </p>
                        ) : (
                            <DateChoice
                                id="approve-end-on"
                                label="End on"
                                items={pendingItems}
                                value={approveOn}
                                onChange={setApproveOn}
                            />
                        )}
                        <div className="grid grid-cols-1 gap-2">
                            <Label htmlFor="cancellation-note">
                                Note to client (optional)
                            </Label>
                            <Textarea
                                id="cancellation-note"
                                rows={3}
                                maxLength={1000}
                                value={note}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                            />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setOpen('decline')}
                            >
                                Decline…
                            </Button>
                            {pendingItems.length > 0 && (
                                <Button onClick={() => setOpen('approve')}>
                                    Approve, end {formatDay(approveOn)}…
                                </Button>
                            )}
                        </div>
                        <p className="text-muted-foreground">
                            Approving schedules the end. You can undo it until{' '}
                            {formatDay(approveOn)}.
                        </p>
                        <ConfirmedAction
                            open={open === 'approve'}
                            onOpenChange={toggle('approve')}
                            title={`End this agreement on ${formatDay(approveOn)}?`}
                            description={`The agreement stays active until ${formatDay(approveOn)}, then ends. The client is emailed the date${note.trim() === '' ? '' : ' and your note'}.${waives(pending.options, approveOn) ? ' This date waives the notice period.' : ''}`}
                            confirmLabel="Approve"
                            run={() =>
                                send(pending.approve_href, {
                                    expected_version: pending.version,
                                    end_date: approveOn,
                                    waive_notice: waives(
                                        pending.options,
                                        approveOn,
                                    ),
                                    ...(note.trim() === ''
                                        ? {}
                                        : { note: note.trim() }),
                                })
                            }
                        />
                        <ConfirmedAction
                            open={open === 'decline'}
                            onOpenChange={toggle('decline')}
                            title="Decline this cancellation request?"
                            description={`The agreement continues as it is. The client is emailed that you declined${note.trim() === '' ? '' : ', with your note'}.`}
                            confirmLabel="Decline"
                            run={() =>
                                send(pending.decline_href, {
                                    expected_version: pending.version,
                                    ...(note.trim() === ''
                                        ? {}
                                        : { note: note.trim() }),
                                })
                            }
                        />
                    </>
                )}

                {panel.scheduled !== null && (
                    <>
                        <p className="font-medium wrap-anywhere">
                            Ends {formatDay(panel.scheduled.ends_on)} ·{' '}
                            {statusLabel(panel.scheduled.source)}
                        </p>
                        <p className="wrap-anywhere text-muted-foreground">
                            Scheduled{' '}
                            {formatTimestampDate(panel.scheduled.scheduled_at)}.
                            The agreement stays active until then; undoing
                            restores{' '}
                            {panel.scheduled.previous_ends_on === null
                                ? 'no end date'
                                : `its end on ${formatDay(panel.scheduled.previous_ends_on)}`}
                            .
                        </p>
                        <div>
                            <Button
                                variant="outline"
                                onClick={() => setOpen('undo')}
                            >
                                Undo scheduled end…
                            </Button>
                        </div>
                        <ConfirmedAction
                            open={open === 'undo'}
                            onOpenChange={toggle('undo')}
                            title="Undo the scheduled end?"
                            description="The agreement continues past that date. You and the client are emailed."
                            confirmLabel="Undo scheduled end"
                            run={() =>
                                send(panel.scheduled?.undo_href ?? '', {
                                    expected_version: panel.scheduled?.version,
                                })
                            }
                        />
                    </>
                )}

                {panel.schedule !== null && panel.scheduled === null && (
                    <>
                        <p className="wrap-anywhere text-muted-foreground">
                            End this agreement at the close of a billing period.
                            It stays active until then, and you can undo it
                            before the date.
                        </p>
                        <DateChoice
                            id="schedule-end-on"
                            label="End on"
                            items={scheduleItems}
                            value={scheduleOn}
                            onChange={setScheduleOn}
                        />
                        <div>
                            <Button
                                variant="outline"
                                onClick={() => setOpen('schedule')}
                            >
                                Schedule end…
                            </Button>
                        </div>
                        <ConfirmedAction
                            open={open === 'schedule'}
                            onOpenChange={toggle('schedule')}
                            title={`End this agreement on ${formatDay(scheduleOn)}?`}
                            description={`The agreement stays active until ${formatDay(scheduleOn)}, then ends. The client is emailed the date.`}
                            confirmLabel="Schedule end"
                            run={() =>
                                send(panel.schedule?.href ?? '', {
                                    expected_version: panel.schedule?.version,
                                    end_date: scheduleOn,
                                    waive_notice: waives(
                                        panel.schedule?.options ?? [],
                                        scheduleOn,
                                    ),
                                })
                            }
                        />
                    </>
                )}
            </CardContent>
        </Card>
    );
}
