import { formatDay } from '@/lib/datetime';

/**
 * How a payment reached us, as a closed list rather than a typed string.
 *
 * The column is free text and stays that way — imports carry whatever the
 * source system called it, and Stripe writes `stripe` itself — but an operator
 * recording a payment by hand was typing into an empty box, so the same
 * arrangement arrived as `bank_transfer`, `Bank Transfer` and `wire` depending
 * on the day. Nothing downstream reads `method`, which is exactly why the
 * drift went unnoticed: it only ever shows up on this screen, spelled three
 * ways.
 *
 * `other` is here so the list can stay short without becoming a wall the
 * unusual payment cannot get through: choosing it asks for the name instead of
 * storing the literal string "other".
 */
export const PAYMENT_METHOD_OTHER = 'other';

export const PAYMENT_METHODS = [
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'wire', label: 'Wire' },
    { value: 'ach', label: 'ACH' },
    { value: 'check', label: 'Check' },
    { value: 'card', label: 'Card' },
    { value: 'cash', label: 'Cash' },
    { value: PAYMENT_METHOD_OTHER, label: 'Other…' },
] as const;

/**
 * `YYYY-MM-DD`, and nothing else.
 *
 * The comparisons below are string comparisons, which is exact for this format
 * and only for this format: zero-padded fixed-width fields make lexicographic
 * order chronological order, with no date object acquiring a time and a zone on
 * the way through - the off-by-one this application already documents at the
 * top of `lib/datetime.ts`. So the shape is checked rather than assumed, and
 * anything else is treated as no date at all.
 */
function isCalendarDate(value: string | null | undefined): value is string {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

/**
 * Is this payment dated before the invoice it is recorded against was issued?
 *
 * Deliberately not a refusal. A deposit or an advance retainer paid before the
 * invoice that applies it is a real arrangement, so the service accepts the
 * write; what an operator needs is to be told, at the moment they type it and
 * again wherever the payment is shown, that the date they entered is outside
 * the invoice's own life. The usual cause is a year or a month typed wrong.
 */
export function predatesInvoiceIssue(
    receivedOn: string | null | undefined,
    issueDate: string | null | undefined,
): boolean {
    return (
        isCalendarDate(receivedOn) &&
        isCalendarDate(issueDate) &&
        receivedOn < issueDate
    );
}

/**
 * What to say about one, naming the date it is measured against.
 *
 * Specific rather than a general caution: the operator is being told which
 * invoice date this payment falls before, so they can tell a deposit they meant
 * from a year they mistyped without leaving the screen.
 */
export function predatesInvoiceIssueWarning(issueDate: string): string {
    return `This payment is dated before the invoice was issued on ${formatDay(issueDate)}. That is allowed — a deposit or an advance retainer can arrive before the invoice that applies it — so check the date is the one you meant.`;
}
