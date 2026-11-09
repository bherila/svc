import { ClientTermsList } from '@/components/packages/client-terms';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDay } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import type { PackageTermChange, PortalServiceMove } from '@/types/packages';

/** The terms a move changes, each with its old and new value, as the server worded them. */
export function TermChanges({
    changes,
    fromLabel = 'Now',
    toLabel = 'New',
}: {
    changes: PackageTermChange[];
    fromLabel?: string;
    toLabel?: string;
}) {
    return (
        <dl className="grid grid-cols-1 divide-y divide-border">
            {changes.map((change) => (
                <div
                    key={change.term}
                    className="grid grid-cols-1 gap-1 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4"
                >
                    <dt className="min-w-0 text-sm wrap-anywhere text-muted-foreground">
                        {change.label}
                    </dt>
                    <dd className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                        <span className="wrap-anywhere">
                            <span className="text-muted-foreground">
                                {fromLabel}:{' '}
                            </span>
                            {change.from}
                        </span>
                        <span className="wrap-anywhere">
                            <span className="text-muted-foreground">
                                {toLabel}:{' '}
                            </span>
                            {change.to}
                        </span>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * The portal's notice of a scheduled move to new terms (MIG-2): the date, what
 * changes from what to what, the provider's note, and - when the client's
 * autopay limit is below the new price - that it will not be raised (MIG-4).
 * The new terms in full are one click away.
 */
export function MoveNotice({
    move,
    serviceName,
}: {
    move: PortalServiceMove;
    serviceName: string;
}) {
    const day = formatDay(move.effective_on);

    return (
        <Card role="note" aria-label={`New terms from ${day}`}>
            <CardHeader>
                <CardTitle className="wrap-anywhere">
                    New terms from {day}
                </CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 text-sm">
                <p className="wrap-anywhere">
                    The terms of {serviceName} change on {day}. Until then
                    nothing changes; from then on, the new terms apply.
                </p>
                {move.changes.length > 0 && (
                    <TermChanges changes={move.changes} />
                )}
                {move.message !== null && move.message !== '' && (
                    <p className="wrap-anywhere whitespace-pre-wrap">
                        <span className="text-muted-foreground">Note: </span>
                        {move.message}
                    </p>
                )}
                {move.autopay_below_price &&
                    move.autopay_limit_amount !== null && (
                        <p
                            role="status"
                            className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 wrap-anywhere"
                        >
                            Your autopay limit is{' '}
                            {formatMoney(
                                move.autopay_limit_amount,
                                move.terms.currency,
                            )}
                            , below the new price of{' '}
                            {formatMoney(
                                move.new_price_amount,
                                move.terms.currency,
                            )}
                            . It will not be raised for you: an invoice above it
                            is not charged automatically. You can change the
                            limit on your billing page.
                        </p>
                    )}
                <p className="wrap-anywhere text-muted-foreground">
                    If you would rather not continue, you can cancel before then
                    under your current terms.
                </p>
                <details className="grid grid-cols-1 gap-2">
                    <summary className="cursor-pointer underline underline-offset-4">
                        The new terms in full
                    </summary>
                    <div className="grid grid-cols-1 gap-3 pt-2">
                        <ClientTermsList terms={move.terms} />
                        {move.terms.agreement_text !== null && (
                            <div
                                tabIndex={0}
                                role="region"
                                aria-label="New agreement text"
                                className="max-h-40 overflow-y-auto text-sm wrap-anywhere whitespace-pre-wrap focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {move.terms.agreement_text}
                            </div>
                        )}
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}
