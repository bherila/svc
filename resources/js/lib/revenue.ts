import type { ChartMonth } from '@/types/revenue';

/**
 * Presentation helpers for the revenue screens. Nothing here derives a
 * revenue figure: the server computed every amount, and these only decide how
 * one is written (a compact axis tick, a month name, a sentence about a
 * change) and which months a narrow screen shows.
 */

/** "Nov" or "Nov 2026" for a `YYYY-MM` month, from its own parts so no zone can shift it. */
export function monthLabel(month: string, withYear = false): string {
    const [year, number] = month.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        year: withYear ? 'numeric' : undefined,
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, number - 1, 1)));
}

/** "November 2026". */
export function monthName(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, number - 1, 1)));
}

/** An axis tick: $12K rather than $12,000.00. */
export function compactMoney(
    minorUnits: number,
    currency: string | null,
): string {
    return new Intl.NumberFormat(undefined, {
        style: currency === null || currency === '' ? 'decimal' : 'currency',
        currency: currency === null || currency === '' ? undefined : currency,
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(minorUnits / 100);
}

/**
 * One line about a year-on-year change, in words so it never rests on colour:
 * "Up 12.5% on the same period last year".
 */
export function changeSentence(
    percent: number | null,
    against: string,
): string {
    if (percent === null) {
        return `Nothing to compare with ${against}`;
    }

    if (percent === 0) {
        return `Unchanged on ${against}`;
    }

    const size = `${Math.abs(percent).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;

    return `${percent > 0 ? 'Up' : 'Down'} ${size} on ${against}`;
}

/** Months a phone shows: six back, the current month and five ahead. */
export const PHONE_BACK = 6;
export const PHONE_AHEAD = 5;

export function phoneWindow(months: ChartMonth[]): ChartMonth[] {
    const current = months.findIndex((month) => month.kind === 'current');

    if (current === -1) {
        return months;
    }

    return months.slice(
        Math.max(0, current - PHONE_BACK),
        current + PHONE_AHEAD + 1,
    );
}
