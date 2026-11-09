import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { OperatorCancellationCard } from '@/components/agreements/operator-cancellation';
import type { OperatorCancellation } from '@/types/agreement';

const api = vi.hoisted(() => ({
    request: vi.fn(),
    refresh: vi.fn().mockResolvedValue(undefined),
}));
vi.mock('@/lib/api', () => ({ apiRequest: api.request }));
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: api.refresh }));

const REQUEST_VERSION = 'r'.repeat(64);
const AGREEMENT_VERSION = 'a'.repeat(64);

function pending(): OperatorCancellation {
    return {
        earliest_end_on: '2027-01-31',
        pending: {
            requested_end_on: '2027-01-31',
            reason: 'Moving to a new agency next quarter.',
            requested_at: '2026-11-08T12:00:00Z',
            requested_by: 'Jo Synthetic',
            version: REQUEST_VERSION,
            options: [
                { value: '2026-12-31', waives_notice: true },
                { value: '2027-01-31', waives_notice: false },
                { value: '2027-02-28', waives_notice: false },
            ],
            approve_href:
                '/api/v1/workspaces/w/cancellation-requests/r/approve',
            decline_href:
                '/api/v1/workspaces/w/cancellation-requests/r/decline',
        },
        scheduled: null,
        schedule: null,
    };
}

beforeEach(() => {
    api.request.mockReset();
    api.request.mockResolvedValue({ ok: true, status: 200, data: {} });
});

describe('OperatorCancellationCard', () => {
    it('[CAN-4] approves on the requested date by default, with the request version and confirm', async () => {
        const user = userEvent.setup();
        render(<OperatorCancellationCard panel={pending()} />);

        expect(
            screen.getByText('Jo Synthetic · Nov 8, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: 'End on' }),
        ).toHaveTextContent('Jan 31, 2027 (requested, earliest with notice)');
        await user.type(
            screen.getByLabelText('Note to client (optional)'),
            'Thanks for working with us.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Approve, end Jan 31, 2027…' }),
        );
        await user.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Approve',
            }),
        );

        expect(api.request).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/cancellation-requests/r/approve',
            {
                expected_version: REQUEST_VERSION,
                end_date: '2027-01-31',
                waive_notice: false,
                note: 'Thanks for working with us.',
                confirm: true,
            },
        );
        expect(api.refresh).toHaveBeenCalled();
    });

    it('[CAN-4] [CAN-2] waives notice only for a date that needs it, and offers no other date', async () => {
        const user = userEvent.setup();
        render(<OperatorCancellationCard panel={pending()} />);

        await user.click(screen.getByRole('combobox', { name: 'End on' }));
        expect(
            (await screen.findAllByRole('option')).map(
                (option) => option.textContent,
            ),
        ).toEqual([
            'Dec 31, 2026 (waive notice)',
            'Jan 31, 2027 (requested, earliest with notice)',
            'Feb 28, 2027',
        ]);
        await user.click(
            screen.getByRole('option', { name: 'Dec 31, 2026 (waive notice)' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Approve, end Dec 31, 2026…' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        expect(
            within(dialog).getByText(/This date waives the notice period/),
        ).toBeInTheDocument();
        await user.click(
            within(dialog).getByRole('button', { name: 'Approve' }),
        );

        expect(api.request).toHaveBeenCalledWith(
            'POST',
            expect.stringContaining('/approve'),
            expect.objectContaining({
                end_date: '2026-12-31',
                waive_notice: true,
            }),
        );
    });

    it('[CAN-4] declines with the note the client reads', async () => {
        const user = userEvent.setup();
        render(<OperatorCancellationCard panel={pending()} />);

        await user.type(
            screen.getByLabelText('Note to client (optional)'),
            'Your minimum term runs to March.',
        );
        await user.click(screen.getByRole('button', { name: 'Decline…' }));
        await user.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Decline',
            }),
        );

        expect(api.request).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/cancellation-requests/r/decline',
            {
                expected_version: REQUEST_VERSION,
                note: 'Your minimum term runs to March.',
                confirm: true,
            },
        );
    });

    it('[EDGE-9] undoes a scheduled end against the agreement version', async () => {
        const user = userEvent.setup();
        render(
            <OperatorCancellationCard
                panel={{
                    earliest_end_on: null,
                    pending: null,
                    schedule: null,
                    scheduled: {
                        ends_on: '2026-12-31',
                        previous_ends_on: '2027-06-30',
                        source: 'self_serve',
                        scheduled_at: '2026-11-09T12:00:00Z',
                        version: AGREEMENT_VERSION,
                        undo_href:
                            '/api/v1/workspaces/w/agreements/a/scheduled-end/undo',
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Ends Dec 31, 2026 · Self serve'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/undoing restores its end on Jun 30, 2027/),
        ).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Undo scheduled end…' }),
        );
        await user.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Undo scheduled end',
            }),
        );

        expect(api.request).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/agreements/a/scheduled-end/undo',
            { expected_version: AGREEMENT_VERSION, confirm: true },
        );
    });

    it('[EDGE-9] schedules an end directly on an offered date', async () => {
        const user = userEvent.setup();
        render(
            <OperatorCancellationCard
                panel={{
                    earliest_end_on: '2026-12-31',
                    pending: null,
                    scheduled: null,
                    schedule: {
                        version: AGREEMENT_VERSION,
                        href: '/api/v1/workspaces/w/agreements/a/scheduled-end',
                        options: [
                            { value: '2026-11-30', waives_notice: true },
                            { value: '2026-12-31', waives_notice: false },
                        ],
                    },
                }}
            />,
        );

        expect(
            screen.getByRole('combobox', { name: 'End on' }),
        ).toHaveTextContent('Dec 31, 2026 (earliest with notice)');
        await user.click(screen.getByRole('button', { name: 'Schedule end…' }));
        await user.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Schedule end',
            }),
        );

        expect(api.request).toHaveBeenCalledWith(
            'POST',
            '/api/v1/workspaces/w/agreements/a/scheduled-end',
            {
                expected_version: AGREEMENT_VERSION,
                end_date: '2026-12-31',
                waive_notice: false,
                confirm: true,
            },
        );
    });

    it('[CAN-4] reads out a stale version and refreshes', async () => {
        const user = userEvent.setup();
        api.request.mockResolvedValue({
            ok: false,
            status: 409,
            message: 'Changed.',
        });
        render(<OperatorCancellationCard panel={pending()} />);

        await user.click(screen.getByRole('button', { name: 'Decline…' }));
        await user.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Decline',
            }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This changed since the page loaded.',
        );
        expect(api.refresh).toHaveBeenCalled();
    });

    it('[CAN-4] renders nothing when there is nothing to decide or schedule', () => {
        const { container } = render(
            <OperatorCancellationCard
                panel={{
                    earliest_end_on: null,
                    pending: null,
                    scheduled: null,
                    schedule: null,
                }}
            />,
        );
        expect(container).toBeEmptyDOMElement();
    });
});
