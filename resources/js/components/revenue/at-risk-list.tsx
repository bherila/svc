import { Link } from '@inertiajs/react';
import { CalendarXIcon, ChevronRightIcon } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDay } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import type { AtRiskAgreement } from '@/types/revenue';

/**
 * "At risk in the next 12 months": every agreement that ends inside the
 * window, with its end date and the monthly amount that drops off
 * (docs/ux-overhaul.md section 2b). The projection assumes no renewal, which
 * the card says once, as its one line of supporting text. The amounts and the
 * order are the server's.
 */
export function AtRiskList({
    atRisk,
    currency,
}: {
    atRisk: { agreements: AtRiskAgreement[]; monthly_total: number };
    currency: string | null;
}) {
    return (
        <Card data-slot="at-risk">
            <CardHeader>
                <CardTitle>At risk in the next 12 months</CardTitle>
                <p className="text-sm text-muted-foreground">
                    {atRisk.agreements.length === 0
                        ? 'No agreement ends in the next 12 months.'
                        : `${formatMoney(atRisk.monthly_total, currency)} a month drops off. The projection does not assume a renewal.`}
                </p>
            </CardHeader>
            {atRisk.agreements.length > 0 && (
                <CardContent>
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {atRisk.agreements.map((agreement) => (
                            <li
                                key={agreement.agreement_id}
                                className="flex min-h-11 items-center gap-3 py-2"
                            >
                                <CalendarXIcon
                                    aria-hidden="true"
                                    className="size-5 shrink-0 text-muted-foreground"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium wrap-anywhere">
                                        <Link
                                            href={agreement.client_href}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {agreement.client_name}
                                        </Link>
                                    </p>
                                    <p className="text-xs wrap-anywhere text-muted-foreground">
                                        {agreement.title} · ends{' '}
                                        {formatDay(agreement.ends_on)}
                                    </p>
                                </div>
                                <span className="shrink-0 text-sm tabular-nums">
                                    {formatMoney(
                                        agreement.monthly_amount,
                                        currency,
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        / month
                                    </span>
                                </span>
                                <Link
                                    href={agreement.href}
                                    className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`View agreement: ${agreement.title}`}
                                >
                                    View
                                    <ChevronRightIcon
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            )}
        </Card>
    );
}
