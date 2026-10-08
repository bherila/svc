import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ActivityTimeline } from '@/components/activity-timeline';
import type { CompanyActivity } from '@/components/activity-timeline';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const activities: CompanyActivity[] = [
    {
        id: 'activity-1',
        action: 'agreement.transitioned',
        actor_name: 'Synthetic Manager',
        payload: {
            external_payload: {
                changes: { billing_cadence: { old: 'monthly', new: 'annual' } },
            },
        },
        created_at: '2026-08-29T20:00:00.000Z',
    },
    {
        id: 'activity-2',
        action: 'invoice.generated',
        actor_name: null,
        payload: {
            external_subject_id: 42,
            external_payload: { invoice_kind: 'cadence_period' },
        },
        created_at: '2026-08-29T19:00:00.000Z',
    },
];

describe('activity timeline', () => {
    it('surfaces notable events and keeps system noise available on demand', async () => {
        const user = userEvent.setup();
        render(<ActivityTimeline activities={activities} />);

        expect(screen.getByText('Agreement transitioned')).toBeVisible();
        expect(
            screen.getByText('billing cadence monthly → annual'),
        ).toBeVisible();
        expect(screen.getByText('By Synthetic Manager')).toBeVisible();
        expect(screen.queryByText('Invoice generated')).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Show system activity (1)' }),
        );

        expect(screen.getByText('Invoice generated')).toBeVisible();
        expect(screen.getByText('cadence period')).toBeVisible();
        expect(screen.getByText('System')).toBeVisible();
    });

    it('explains an empty history without rendering controls', () => {
        render(<ActivityTimeline activities={[]} />);

        expect(
            screen.getByText(
                'No activity has been logged for this client yet.',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('renders the native public-subject shape without an import wrapper', () => {
        render(
            <ActivityTimeline
                activities={[
                    {
                        id: 'activity-native',
                        action: 'invoice.issued',
                        actor_name: 'Synthetic Manager',
                        subject_type: 'client_invoice',
                        subject_id: '11111111-1111-4111-8111-111111111111',
                        payload: { invoice_kind: 'ad_hoc' },
                        created_at: '2026-08-30T00:00:00.000Z',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Invoice issued')).toBeVisible();
        expect(screen.getByText('ad hoc')).toBeVisible();
        expect(screen.getByText('By Synthetic Manager')).toBeVisible();
    });
});

/**
 * A date correction is the record that justifies allowing the edit.
 *
 * The service writes `invoice.payment_date_corrected` carrying the date it
 * replaced, and the whole argument for permitting a date-only edit at all - in
 * a codebase with no payment-edit path - was that the change would be recorded.
 * An action absent from these maps is classified as system noise and folded
 * away behind a button, with the two dates it carries rendered nowhere even
 * when it is expanded, which is an audit record nobody can see.
 */
describe('a corrected payment date', () => {
    const corrected: CompanyActivity = {
        id: 'activity-3',
        action: 'invoice.payment_date_corrected',
        actor_name: 'Synthetic Operator',
        payload: {
            amount: 4000,
            currency: 'USD',
            previous_received_on: '2026-08-25',
            received_on: '2026-08-18',
        },
        created_at: '2026-08-29T18:00:00.000Z',
    };

    it('is shown without expanding system activity, with both dates', () => {
        render(<ActivityTimeline activities={[corrected]} />);

        expect(screen.getByText('Payment date corrected')).toBeVisible();
        expect(
            screen.getByText('Received Aug 25, 2026 → Aug 18, 2026'),
        ).toBeVisible();
        // Nothing was hidden, so there is no disclosure control at all.
        expect(screen.queryByRole('button')).toBeNull();
    });

    /** An imported payment can carry no date, and the correction still reads. */
    it('says so when the payment had no date to begin with', () => {
        render(
            <ActivityTimeline
                activities={[
                    {
                        ...corrected,
                        payload: {
                            ...corrected.payload,
                            previous_received_on: null,
                        },
                    },
                ]}
            />,
        );

        expect(
            screen.getByText('Received not recorded → Aug 18, 2026'),
        ).toBeVisible();
    });

    /**
     * An imported payload carrying a `received_on` of its own is a payment's
     * date, not a change to one, so it is not read as a correction.
     */
    it('does not read an imported payment date as a correction', () => {
        render(
            <ActivityTimeline
                activities={[
                    {
                        id: 'activity-4',
                        action: 'invoice.payment_received',
                        actor_name: null,
                        payload: {
                            external_payload: { received_on: '2026-08-18' },
                        },
                        created_at: '2026-08-29T17:00:00.000Z',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Payment received')).toBeVisible();
        expect(screen.queryByText(/Received /)).toBeNull();
    });
});

/**
 * The general payment correction carries a before/after of only the fields
 * that changed and the reason it was made; both are the audit record, so both
 * are shown without expanding system activity.
 */
describe('a corrected payment', () => {
    it('shows each changed field, its dates as dates, and the reason', () => {
        render(
            <ActivityTimeline
                activities={[
                    {
                        id: 'activity-4',
                        action: 'invoice.payment_corrected',
                        actor_name: 'Synthetic Operator',
                        payload: {
                            amount: 4000,
                            currency: 'USD',
                            changes: {
                                method: { old: 'wire', new: 'ach' },
                                reference: { old: null, new: 'SYN-REF-1' },
                                received_on: {
                                    old: '2026-08-25',
                                    new: '2026-08-18',
                                },
                            },
                            reason: 'Synthetic bank statement reconciliation',
                        },
                        created_at: '2026-08-29T18:00:00.000Z',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Payment corrected')).toBeVisible();
        expect(
            screen.getByText(
                'method wire → ach, reference none → SYN-REF-1, received on Aug 25, 2026 → Aug 18, 2026 · Reason: Synthetic bank statement reconciliation',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('button')).toBeNull();
    });
});

/**
 * A correction records references, notes and reasons verbatim, and a
 * reference is often one unbroken identifier. Each has to wrap inside the
 * card rather than push the page open.
 */
describe('an oversized correction record', () => {
    it('gives every unbreakable run somewhere to go', () => {
        const unbroken = 'SYN'.padEnd(255, 'X');
        const { container } = render(
            <ActivityTimeline
                activities={[
                    {
                        id: 'activity-oversized',
                        action: 'invoice.payment_corrected',
                        actor_name: 'Synthetic'.padEnd(120, 'Y'),
                        payload: {
                            changes: {
                                reference: { old: unbroken, new: unbroken },
                                notes: { old: null, new: 'N'.padEnd(121, 'N') },
                            },
                            reason: 'R'.padEnd(500, 'R'),
                        },
                        created_at: '2026-08-29T18:00:00.000Z',
                    },
                ]}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

/**
 * A draft's due date moves what the client will be told they owe and when, so
 * the entry says from which day to which, without expanding system activity.
 */
describe('updated draft details', () => {
    it('shows the old and new due date and whether the notes changed', () => {
        render(
            <ActivityTimeline
                activities={[
                    {
                        id: 'activity-details',
                        action: 'invoice.details_updated',
                        actor_name: 'Synthetic Operator',
                        payload: {
                            invoice_kind: 'cadence_period',
                            due_date: { old: '2026-09-30', new: '2026-10-15' },
                            notes_changed: true,
                        },
                        created_at: '2026-10-08T18:00:00.000Z',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Invoice details updated')).toBeVisible();
        expect(
            screen.getByText(/^due date .+ → .+, notes changed$/),
        ).toBeVisible();
        expect(screen.queryByText('cadence period')).not.toBeInTheDocument();
    });
});
