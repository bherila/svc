import { TermTip } from '@/components/help/term-tip';
import { StatTile } from '@/components/ui/stat-tile';
import { formatMoney } from '@/lib/money';
import { changeSentence } from '@/lib/revenue';
import { cn } from '@/lib/utils';
import type { RevenueKpis } from '@/types/revenue';

/**
 * The key figures: a number each with one line of context (docs/ux-overhaul.md
 * section 2a). Four tiles, two by two on a phone. The figures are the server's;
 * the region is `aria-live` so a reader using a screen reader hears them change
 * when a toggle is flipped.
 *
 * `variant="workspace"` is the Dashboard's strip (YTD, TTM, next 12 months, open
 * A/R); `"client"` is the client overview's (MRR in place of the next 12 months).
 */
export function KpiStrip({
    kpis,
    currency,
    variant,
    busy = false,
}: {
    kpis: RevenueKpis;
    currency: string | null;
    variant: 'workspace' | 'client';
    busy?: boolean;
}) {
    const money = (amount: number) => formatMoney(amount, currency);
    const open = kpis.open_receivables;
    const next = kpis.next_12_months;

    return (
        <section
            aria-label="Key figures"
            aria-live="polite"
            aria-busy={busy}
            data-slot="kpi-strip"
            className={cn(
                'grid grid-cols-2 gap-3 lg:grid-cols-4',
                busy && 'opacity-60 transition-opacity',
            )}
        >
            {variant === 'client' && (
                <StatTile
                    label={
                        <>
                            MRR <TermTip term="MRR" />
                        </>
                    }
                    value={money(kpis.mrr ?? 0)}
                    context="Recurring revenue per month"
                />
            )}
            <StatTile
                label="Year to date"
                value={money(kpis.ytd.amount)}
                context={changeSentence(
                    kpis.ytd.change_percent,
                    'the same period last year',
                )}
            />
            <StatTile
                label="Trailing 12 months"
                value={money(kpis.ttm.amount)}
                context={changeSentence(
                    kpis.ttm.change_percent,
                    'the prior 12 months',
                )}
            />
            {variant === 'workspace' && (
                <StatTile
                    label={
                        <>
                            Next 12 months <TermTip term="Contracted" />
                        </>
                    }
                    value={money(next.contracted)}
                    context={
                        next.projected_overage === null
                            ? 'Contracted recurring revenue'
                            : `+ ${money(next.projected_overage)} projected overage`
                    }
                />
            )}
            <StatTile
                label={variant === 'client' ? 'Open balance' : 'Open A/R'}
                value={money(open.outstanding)}
                context={
                    open.overdue_count === 0
                        ? 'Nothing overdue'
                        : `${money(open.overdue_amount)} overdue on ${open.overdue_count} ${open.overdue_count === 1 ? 'invoice' : 'invoices'}`
                }
            />
        </section>
    );
}
