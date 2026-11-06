/**
 * Money formatting for operator screens.
 *
 * Amounts travel as minor units, because that is what the columns hold and
 * dividing by 100 in three places is how two screens end up disagreeing about
 * a total. The currency is the row's own: a workspace can invoice in more than
 * one, and formatting everything with a default silently relabels the rest.
 */
export function formatMoney(
    minorUnits: number,
    currency: string | null,
): string {
    if (currency === null || currency === '') {
        return (minorUnits / 100).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(minorUnits / 100);
}

/**
 * A typed amount in major units as minor units, or null when it is empty or not
 * a number. The write validators own every other bound.
 */
export function toMinorUnits(value: string): number | null {
    if (value.trim() === '') {
        return null;
    }

    const amount = Number(value);

    return Number.isFinite(amount) ? Math.round(amount * 100) : null;
}
