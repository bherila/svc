import { router } from '@inertiajs/react';
import { useState } from 'react';
import { TermTip } from '@/components/help/term-tip';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { RevenueBasis, RevenueFilters } from '@/types/revenue';

/**
 * The basis toggle (Invoiced or Collected) and the two switches that drive the
 * key figures and the chart.
 *
 * A toggle never recomputes anything here. It asks the server for the same
 * page with a different query string - `toggle_href` is finished by the
 * server - and reloads only the prop that holds the revenue (`only`), so the
 * figures a reader sees are always the revenue read model's. While the answer
 * is on its way the controls say so (`aria-busy`) and keep the old figures.
 */
export function RevenueToggles({
    filters,
    href,
    only,
    onBusyChange,
}: {
    filters: RevenueFilters;
    href: string;
    /** The Inertia prop that holds the revenue. */
    only: string;
    onBusyChange?: (busy: boolean) => void;
}) {
    const [busy, setBusy] = useState(false);

    function ask(next: RevenueFilters) {
        router.get(
            href,
            {
                basis: next.basis,
                expenses: next.expenses ? 1 : 0,
                overage: next.overage ? 1 : 0,
            },
            {
                only: [only],
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onStart: () => {
                    setBusy(true);
                    onBusyChange?.(true);
                },
                onFinish: () => {
                    setBusy(false);
                    onBusyChange?.(false);
                },
            },
        );
    }

    const bases: { value: RevenueBasis; label: string }[] = [
        { value: 'invoiced', label: 'Invoiced' },
        { value: 'collected', label: 'Collected' },
    ];

    return (
        <div
            className="flex flex-wrap items-center gap-x-6 gap-y-3"
            aria-busy={busy}
            data-slot="revenue-toggles"
        >
            <div
                role="group"
                aria-label="Revenue basis"
                className="inline-flex items-center gap-1"
            >
                {bases.map((basis) => (
                    <Button
                        key={basis.value}
                        type="button"
                        size="sm"
                        variant={
                            filters.basis === basis.value
                                ? 'secondary'
                                : 'ghost'
                        }
                        aria-pressed={filters.basis === basis.value}
                        className={cn(
                            'min-h-8 pointer-coarse:min-h-11',
                            filters.basis === basis.value && 'font-semibold',
                        )}
                        onClick={() => {
                            if (filters.basis !== basis.value) {
                                ask({ ...filters, basis: basis.value });
                            }
                        }}
                    >
                        {basis.label}
                    </Button>
                ))}
                <TermTip term="Basis" />
            </div>
            <label className="flex min-h-8 items-center gap-2 text-sm pointer-coarse:min-h-11">
                <Switch
                    aria-label="Include expenses"
                    checked={filters.expenses}
                    onCheckedChange={(expenses) =>
                        ask({ ...filters, expenses })
                    }
                />
                Include expenses
            </label>
            <label className="flex min-h-8 items-center gap-2 text-sm pointer-coarse:min-h-11">
                <Switch
                    aria-label="Include overage projection"
                    checked={filters.overage}
                    onCheckedChange={(overage) => ask({ ...filters, overage })}
                />
                Include overage projection
            </label>
            <TermTip term="Projected overage" />
        </div>
    );
}
