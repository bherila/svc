/**
 * Autopay wording and amounts (#636).
 *
 * The sentence a client agrees to is stored word for word on the consent
 * record, and the server refuses a turn-on whose wording is not exactly the one
 * it would have composed. `authorizationWording` therefore mirrors
 * `AutopayWording::authorization()` character for character; both are pinned
 * to the same literal in their tests, so drift between them fails a test
 * rather than reaching a client's card.
 */

/** Minor units as the page writes them: `$5,000.00`, or `EUR 5,000.00`. */
export function autopayMoney(minor: number, currency: string): string {
    const formatted = (minor / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return currency.toUpperCase() === 'USD'
        ? `$${formatted}`
        : `${currency.toUpperCase()} ${formatted}`;
}

export function authorizationWording(parts: {
    provider: string;
    company: string;
    method: string;
    limit: number;
    currency: string;
}): string {
    return `I authorize ${parts.provider} to charge ${parts.method} for ${parts.company} invoices on their due dates, up to ${autopayMoney(parts.limit, parts.currency)} each, until I turn autopay off.`;
}

/**
 * A typed amount (`5,000`, `5000.50`, `$5,000.00`) as minor units, or null when
 * it is empty, not a plain amount, or finer than a cent. Strict on purpose: a
 * limit that is silently rounded is a limit nobody agreed to.
 */
export function parseLimit(text: string): number | null {
    const cleaned = text.trim().replace(/^\$/, '').replace(/,/g, '');

    if (!/^\d+(\.\d{1,2})?$/.test(cleaned)) {
        return null;
    }

    const [whole, cents = ''] = cleaned.split('.');
    const minor = Number(whole) * 100 + Number(cents.padEnd(2, '0'));

    return Number.isSafeInteger(minor) && minor > 0 ? minor : null;
}

/** An amount in minor units as an editable field value: `5000.00`. */
export function limitField(minor: number | null): string {
    return minor === null ? '' : (minor / 100).toFixed(2);
}
