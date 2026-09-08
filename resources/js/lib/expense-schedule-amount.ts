/** Schedule amounts arrive as decimal strings to preserve PHP integer precision. */
export function formatScheduleAmount(
    amount: string,
    currency: string,
    locale?: string,
): string {
    const minorUnits = BigInt(amount);
    // Expenses use hundredths. Keep both the integer and remainder exact; a
    // Number conversion here would round valid stored amounts above 2^53 - 1.
    const fraction = new Intl.NumberFormat(locale, {
        useGrouping: false,
        minimumIntegerDigits: 2,
    }).format(minorUnits % 100n);

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
        .formatToParts(minorUnits / 100n)
        .map((part) => (part.type === 'fraction' ? fraction : part.value))
        .join('');
}
