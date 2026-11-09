import type { ReactNode } from 'react';
import { formatDuration } from '@/lib/duration';
import { formatMoney } from '@/lib/money';
import { cadenceLabel, priceLine } from '@/lib/packages';
import type { ClientTerms } from '@/types/packages';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-1 gap-1 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4">
            <dt className="min-w-0 text-sm wrap-anywhere text-muted-foreground">
                {label}
            </dt>
            <dd className="min-w-0 text-sm wrap-anywhere">{children}</dd>
        </div>
    );
}

/** When it starts, in the client's words. */
function startsText(mode: string): string {
    return mode === 'automatic'
        ? 'When you sign'
        : 'After we review it, once you have signed';
}

/** The commercial terms a client agrees to, as a list of rows. */
export function ClientTermsList({ terms }: { terms: ClientTerms }) {
    const commitment = [
        terms.minimum_term_months === null
            ? null
            : `${terms.minimum_term_months}-month minimum`,
        terms.notice_days > 0 ? `${terms.notice_days} days’ notice` : null,
    ].filter((part) => part !== null);

    return (
        <dl className="grid grid-cols-1 divide-y divide-border">
            {terms.components.map((component, index) => (
                <Row key={index} label={component.description}>
                    <span className="tabular-nums">
                        {component.kind === 'setup_fee'
                            ? `${formatMoney(component.amount, terms.currency)} once`
                            : priceLine(
                                  component.amount,
                                  terms.currency,
                                  component.cadence,
                              )}
                    </span>
                </Row>
            ))}
            {terms.retainer_amount !== null && terms.retainer_amount > 0 && (
                <Row
                    label={
                        terms.retainer_minutes === null
                            ? 'Retainer'
                            : `Retainer · ${formatDuration(terms.retainer_minutes, 'decimal')} included`
                    }
                >
                    <span className="tabular-nums">
                        {priceLine(
                            terms.retainer_amount,
                            terms.currency,
                            terms.billing_cadence,
                        )}
                    </span>
                </Row>
            )}
            {terms.rollover_months !== null && terms.rollover_months > 0 && (
                <Row label="Unused hours">
                    Roll over for {terms.rollover_months}{' '}
                    {terms.rollover_months === 1 ? 'month' : 'months'}
                </Row>
            )}
            {terms.hourly_rate_amount !== null && (
                <Row label="Extra hours">
                    <span className="tabular-nums">
                        {formatMoney(terms.hourly_rate_amount, terms.currency)}{' '}
                        per hour
                    </span>
                </Row>
            )}
            <Row label="Billing">
                {cadenceLabel(terms.billing_cadence)}, in {terms.currency}
            </Row>
            <Row label="Starts">{startsText(terms.activation_mode)}</Row>
            {commitment.length > 0 && (
                <Row label="Commitment">{commitment.join(', then ')}</Row>
            )}
        </dl>
    );
}
