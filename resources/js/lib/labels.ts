/**
 * A stored status, as a person reads it.
 *
 * The columns hold `partially_paid`, `one_time`, `in_progress` - the vocabulary
 * the billing enums own, and the right thing to store. Printing it raw into a
 * badge shows the reader the database, and it did: "partially_paid" sat on the
 * client's own home screen beside a figure they were being asked to pay.
 *
 * Sentence case rather than Title Case: these sit inside small badges next to
 * running text, and Title Case makes every one of them shout.
 *
 * Deliberately a formatter and not a lookup table. A status this does not know
 * still reads as words - a new enum case shows up spelled out rather than
 * blank, which is the failure mode to prefer on a screen about money.
 */
export function statusLabel(status: string | null | undefined): string {
    if (status === null || status === undefined || status === '') {
        return '—';
    }

    const words = status.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}
