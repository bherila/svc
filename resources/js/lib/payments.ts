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
