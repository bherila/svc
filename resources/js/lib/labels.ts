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

/**
 * Every stored status the interface prints, and what happens next.
 *
 * A badge is never shown without a next step: "Reviewed" alone says where the
 * thing is, not what is being waited for. The record below is typed over the
 * tuple, so adding a status here without a line fails type-checking - the same
 * exhaustiveness the billing enums get from `match`. A status this does not
 * know still reads as words through `statusLabel`, and has no next step.
 */
export const KNOWN_STATUSES = [
    'draft',
    'submitted',
    'reviewed',
    'approved',
    'disputed',
    'invoiced',
    'issued',
    'partially_paid',
    'paid',
    'void',
    'sent',
    'accepted',
    'declined',
    'expired',
    'active',
    'paused',
    'terminated',
    'open',
    'in_progress',
    'completed',
    'pending',
    'succeeded',
    'failed',
    'refunded',
    'canceled',
    'cancelled',
    'requires_action',
    'revoked',
    'in_transit',
    'recorded',
    'unmatched',
    'ambiguous',
    'ignored',
    'superseded',
    'not_applied',
    'sending',
] as const;

export type KnownStatus = (typeof KNOWN_STATUSES)[number];

const NEXT_STEPS: Record<KnownStatus, string> = {
    draft: 'Finish it and send it on; nothing is billed until then.',
    submitted: 'Waiting for the business to review it.',
    reviewed: 'Waiting for the client to approve it.',
    approved: 'Ready to go onto the next invoice.',
    disputed: 'Settle the question; it stays out of billing until then.',
    invoiced: 'Nothing to do; it is on an issued invoice.',
    issued: 'Waiting for the client to pay.',
    partially_paid: 'Waiting for the rest of the balance.',
    paid: 'Nothing to do; the balance is settled.',
    void: 'Nothing to do; it was cancelled and no longer counts.',
    sent: 'Waiting for the client to respond.',
    accepted: 'Nothing to do; the client agreed.',
    declined: 'Revise it and send it again, or close it out.',
    expired: 'Renew it or let it lapse.',
    active: 'In force; no action needed unless terms change.',
    paused: 'Resume it when work should start again.',
    terminated: 'Nothing to do; it has ended.',
    open: 'Waiting to be started.',
    in_progress: 'Being worked on; mark it complete when done.',
    completed: 'Nothing to do; the work is finished.',
    pending: 'Waiting for the payment processor to confirm.',
    succeeded: 'Nothing to do; the payment went through.',
    failed: 'Ask the client to try another payment method.',
    refunded: 'Nothing to do; the money was returned.',
    canceled: 'Nothing to do; it was cancelled.',
    cancelled: 'Nothing to do; it was cancelled.',
    requires_action: 'Waiting for the client to finish confirming it.',
    revoked: 'Nothing to do; access was withdrawn.',
    in_transit: 'Waiting for the payout to arrive at the bank.',
    recorded: 'Nothing to do; it is on the books.',
    unmatched: 'Match it to an invoice or payment to reconcile it.',
    ambiguous: 'Choose which invoice or payment it belongs to.',
    ignored: 'Nothing to do; it was set aside on purpose.',
    superseded: 'Nothing to do; a newer version replaced it.',
    not_applied: 'Apply it to an invoice to put it to use.',
    sending: 'Being delivered; check again shortly.',
};

export function isKnownStatus(status: string): status is KnownStatus {
    return (KNOWN_STATUSES as readonly string[]).includes(status);
}

/** The one-line next step for a status, or null for one this has never seen. */
export function statusNextStep(
    status: string | null | undefined,
): string | null {
    if (status === null || status === undefined) {
        return null;
    }

    const key = status
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');

    return isKnownStatus(key) ? NEXT_STEPS[key] : null;
}

/** The label and the next step together, as a badge shows them. */
export function describeStatus(status: string | null | undefined): {
    label: string;
    nextStep: string | null;
} {
    return { label: statusLabel(status), nextStep: statusNextStep(status) };
}
