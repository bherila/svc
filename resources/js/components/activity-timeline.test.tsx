import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ActivityTimeline } from '@/components/activity-timeline';
import type { CompanyActivity } from '@/components/activity-timeline';

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
