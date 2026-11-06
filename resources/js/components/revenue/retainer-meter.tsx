import { AlertTriangleIcon, CalendarClockIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { useDurationStyle } from '@/hooks/use-duration-style';
import { formatDuration } from '@/lib/duration';
import { cn } from '@/lib/utils';

/**
 * Retainer hours used against the hours included this period, as a compact
 * meter with its figures in words (docs/ux-overhaul.md section 3).
 *
 * The hours are whole minutes from the server, printed through the shared
 * duration formatter so the reader's "2.5 h / 2:30" preference applies. The
 * bar is capped at full while the figures are not, so an over-run reads as one
 * rather than as a bar that stopped growing; a retainer of zero hours would
 * divide by zero, so it never reaches a bar.
 *
 * `status` is how the server read the ledger: a meter is shown only for `ok`.
 * `pending` is the moment before the deferred read arrives.
 */
export type MeterStatus =
    'pending' | 'none' | 'ok' | 'no_retainer' | 'ledger_unreadable';

export function RetainerMeter({
    status,
    minutesUsed,
    minutesIncluded,
    over = false,
    endingSoon = false,
}: {
    status: MeterStatus;
    minutesUsed: number | null;
    minutesIncluded: number | null;
    over?: boolean;
    endingSoon?: boolean;
}) {
    const style = useDurationStyle();
    const metered =
        status === 'ok' && minutesUsed !== null && minutesIncluded !== null;
    const fraction =
        metered && minutesIncluded > 0
            ? Math.min(1, minutesUsed / minutesIncluded)
            : 0;

    return (
        <div
            data-slot="retainer-meter"
            className="grid min-w-0 grid-cols-1 gap-1 text-sm"
        >
            {metered ? (
                <>
                    <span className="tabular-nums">
                        {formatDuration(minutesUsed, style)} /{' '}
                        {formatDuration(minutesIncluded, style)}
                    </span>
                    <div
                        className="h-1.5 w-full max-w-32 overflow-hidden rounded-full bg-muted"
                        role="presentation"
                    >
                        <div
                            className={cn(
                                'h-full rounded-full',
                                over ? 'bg-destructive' : 'bg-primary',
                            )}
                            style={{ width: `${Math.round(fraction * 100)}%` }}
                        />
                    </div>
                </>
            ) : (
                <span className="text-muted-foreground">
                    {status === 'pending' && 'Reading hours…'}
                    {status === 'none' && 'No agreement'}
                    {status === 'no_retainer' && 'No retainer hours'}
                    {status === 'ledger_unreadable' && 'Hours unavailable'}
                </span>
            )}
            {(over || endingSoon) && (
                <span className="flex flex-wrap gap-1">
                    {over && (
                        <Badge variant="outline">
                            <AlertTriangleIcon
                                aria-hidden="true"
                                data-icon="inline-start"
                            />
                            Over retainer
                        </Badge>
                    )}
                    {endingSoon && (
                        <Badge variant="outline">
                            <CalendarClockIcon
                                aria-hidden="true"
                                data-icon="inline-start"
                            />
                            Ends within 60 days
                        </Badge>
                    )}
                </span>
            )}
        </div>
    );
}
