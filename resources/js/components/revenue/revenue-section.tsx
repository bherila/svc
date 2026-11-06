import { lazy, Suspense, useState } from 'react';
import { KpiStrip } from '@/components/revenue/kpi-strip';
import { RevenueToggles } from '@/components/revenue/revenue-toggles';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatMoney } from '@/lib/money';
import { monthName } from '@/lib/revenue';
import type {
    ChartMonth,
    RevenueAssumptions,
    RevenueFilters,
    RevenueKpis,
} from '@/types/revenue';

/**
 * Recharts is the one heavy dependency on these screens, so it loads when a
 * page that draws the chart renders, and in no other bundle.
 */
const RevenueChart = lazy(() => import('@/components/revenue/revenue-chart'));

function summary(
    months: ChartMonth[],
    assumptions: RevenueAssumptions,
): string {
    const first = months[0];
    const last = months[months.length - 1];
    const current = months.find((month) => month.kind === 'current');

    if (first === undefined || last === undefined) {
        return 'Revenue by month: no months to show.';
    }

    return `Revenue by month from ${monthName(first.month)} to ${monthName(last.month)}, ${assumptions.basis} basis.${
        current === undefined
            ? ''
            : ` This month: ${formatMoney(current.total, assumptions.currency)}.`
    }`;
}

/**
 * The basis and expense toggles, the key figures and the chart, for the
 * Dashboard and for one client's overview. `only` names the Inertia prop the
 * toggles reload, so a toggle refetches the revenue and nothing beside it.
 */
export function RevenueSection({
    toggleHref,
    filters,
    assumptions,
    kpis,
    months,
    variant,
    only,
}: {
    toggleHref: string;
    filters: RevenueFilters;
    assumptions: RevenueAssumptions;
    kpis: RevenueKpis;
    months: ChartMonth[];
    variant: 'workspace' | 'client';
    only: string;
}) {
    const [busy, setBusy] = useState(false);

    return (
        <section
            aria-label="Revenue"
            data-slot="revenue-section"
            className="grid min-w-0 grid-cols-1 gap-4"
        >
            <RevenueToggles
                filters={filters}
                href={toggleHref}
                only={only}
                onBusyChange={setBusy}
            />
            <KpiStrip
                kpis={kpis}
                currency={assumptions.currency}
                variant={variant}
                busy={busy}
            />
            <Card>
                <CardHeader>
                    <CardTitle>Revenue by month</CardTitle>
                </CardHeader>
                <CardContent className="min-w-0">
                    <Suspense
                        fallback={
                            <p className="h-60 text-sm text-muted-foreground sm:h-72">
                                Loading the chart…
                            </p>
                        }
                    >
                        <RevenueChart
                            months={months}
                            filters={filters}
                            assumptions={assumptions}
                            caption={summary(months, assumptions)}
                            busy={busy}
                        />
                    </Suspense>
                </CardContent>
            </Card>
        </section>
    );
}
